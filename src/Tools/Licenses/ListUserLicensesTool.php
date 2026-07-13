<?php

namespace Platform\AssetManager\Tools\Licenses;

use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Tools\Concerns\ResolvesTeam;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

/**
 * Team-/tenant-weiter Auszug der Microsoft-Lizenz-User (User↔SKU-Zuweisungen), pro Nutzer
 * gruppiert und um Mitarbeiter-Kontext (Match, aktiv?, Abteilung, Kostenstelle) angereichert.
 * Read-only — als Basis für einen Lizenz↔Mitarbeiter-Abgleich (verwaiste/inaktive Zuordnungen)
 * im Chat. Das Tool bewertet nicht, es liefert Fakten + Kennzahlen.
 *
 * Feld-Semantik (verifiziert an SyncLicensesJob): asset_user_licenses.display_name ist der
 * NUTZER-Name (Graph displayName). Der LIZENZ-Name kommt aus asset_license_skus.display_name
 * und wird hier als sku_name explizit über den SKU-Katalog aufgelöst (Fallback sku_part_number).
 */
class ListUserLicensesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesTeam;

    /**
     * Listen-Obergrenze (verhindert riesige Antworten; `truncated` meldet, wenn gekürzt wurde).
     * Das `summary` zählt IMMER die volle Menge, unabhängig von diesem Cap.
     */
    private const MAX_USERS = 500;

    public function getName(): string
    {
        return 'asset-manager.user-licenses.GET';
    }

    public function getDescription(): string
    {
        return 'GET /asset-manager/user-licenses - Auszug der Microsoft-Lizenz-User des Teams: pro '
            . 'Nutzer (UPN) dessen zugewiesene Lizenzen (sku_name, Stückpreis, Zuweisungsdatum) PLUS '
            . 'Mitarbeiter-Kontext (matched, is_active, Abteilung, Kostenstelle) für den Abgleich. '
            . 'summary liefert Kennzahlen (matched_active/matched_inactive/unmatched) über die VOLLE '
            . 'Menge. Optional: tenant_id (nur ein Tenant), sku_part_number (nur diese Lizenz, z.B. '
            . '"SPB" — licenses[] enthält dann nur diese SKU), search (UPN/Name), match_state '
            . '(all|matched|unmatched|inactive — unmatched = Lizenz-User ohne Mitarbeiter-Datensatz, '
            . 'inactive = Mitarbeiter vorhanden aber inaktiv). Die Liste ist auf 500 Nutzer begrenzt '
            . '(truncated=true → per tenant_id/sku_part_number/search eingrenzen).';
    }

    public function getSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'tenant_id' => [
                    'type'        => 'integer',
                    'description' => 'Optional: nur Lizenz-User dieses Tenants. Gültige IDs siehe "tenants" in der Antwort. Ohne = alle Tenants des Teams.',
                ],
                'search' => [
                    'type'        => 'string',
                    'description' => 'Optional: filtert nach user_principal_name oder Anzeigename (Teilstring).',
                ],
                'sku_part_number' => [
                    'type'        => 'string',
                    'description' => 'Optional: nur Nutzer mit dieser Lizenz-SKU (z.B. "SPB", "ENTERPRISEPACK"). licenses[] enthält dann nur diese SKU.',
                ],
                'match_state' => [
                    'type'        => 'string',
                    'enum'        => ['all', 'matched', 'unmatched', 'inactive'],
                    'description' => 'Optional (Default all): matched = aktiver Mitarbeiter zur UPN, unmatched = kein Mitarbeiter-Datensatz zur UPN, inactive = Mitarbeiter vorhanden aber is_active=false.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (!$teamId) {
                return ToolResult::error('MISSING_TEAM', 'Kein aktives Team im Kontext. Nutze core__context__GET / core__team__switch.');
            }

            $tenants = AssetTenant::where('team_id', $teamId)
                ->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'is_default']);

            // Tenant-Filter validieren (muss zum Team gehören) — sonst klare Fehlermeldung statt leerem Ergebnis.
            $tenantId = isset($arguments['tenant_id']) ? (int) $arguments['tenant_id'] : null;
            if ($tenantId !== null && !$tenants->contains('id', $tenantId)) {
                return ToolResult::error('INVALID_TENANT', "Tenant {$tenantId} gehört nicht zu diesem Team.");
            }

            $matchState = isset($arguments['match_state']) ? (string) $arguments['match_state'] : 'all';
            if (!in_array($matchState, ['all', 'matched', 'unmatched', 'inactive'], true)) {
                return ToolResult::error('INVALID_MATCH_STATE', "Unbekannter match_state '{$matchState}'. Erlaubt: all, matched, unmatched, inactive.");
            }

            // 1. Zuweisungen laden (team + optional tenant/sku/search).
            $query = AssetUserLicense::where('team_id', $teamId)->forTenant($tenantId);

            if (!empty($arguments['sku_part_number'])) {
                $query->where('sku_part_number', (string) $arguments['sku_part_number']);
            }
            if (!empty($arguments['search'])) {
                $s = (string) $arguments['search'];
                $query->where(function ($q) use ($s) {
                    $q->where('user_principal_name', 'like', '%' . $s . '%')
                      ->orWhere('display_name', 'like', '%' . $s . '%');
                });
            }

            $assignments = $query->get();

            // 2. SKU-Katalog EINMAL laden → sku_name/unit_price je Zuweisung auflösen (kein N+1 über sku()).
            $skuById = AssetLicenseSku::where('team_id', $teamId)->get()->keyBy('sku_id');

            // 3. Mitarbeiter EINMAL laden (per UPN, team-scoped) → Match/Kontext. Muster: ListEmployeesTool.
            //    UPN ist team-weit eindeutig (unique(team_id, user_principal_name)) → keyBy ist kollisionsfrei.
            $upns = $assignments->pluck('user_principal_name')->filter()->unique()->values()->all();
            $employeesByUpn = $upns
                ? AssetEmployee::where('team_id', $teamId)->whereIn('user_principal_name', $upns)->with('costCenter')->get()->keyBy('user_principal_name')
                : collect();

            // 4. Nach UPN gruppieren → pro Nutzer eine Zeile mit licenses[] + Mitarbeiter-Kontext.
            $users = $assignments->groupBy('user_principal_name')->map(function ($rows, $upn) use ($skuById, $employeesByUpn) {
                /** @var AssetEmployee|null $emp */
                $emp = $employeesByUpn->get($upn);

                $licenses = $rows->map(function (AssetUserLicense $l) use ($skuById) {
                    $sku = $skuById->get($l->sku_id);

                    return [
                        'sku_id'          => $l->sku_id,
                        'sku_part_number' => $l->sku_part_number,
                        'sku_name'        => ($sku && $sku->display_name) ? $sku->display_name : $l->sku_part_number,
                        'unit_price'      => ($sku && $sku->unit_price !== null) ? (float) $sku->unit_price : null,
                        'assigned_at'     => $l->assigned_at?->toIso8601String(),
                    ];
                })->sortBy('sku_name')->values()->all();

                return [
                    'user_principal_name' => $upn,
                    'display_name'        => $rows->first()->display_name,
                    'tenant_id'           => $rows->first()->tenant_id,
                    'matched'             => $emp !== null,
                    'employee'            => $emp ? [
                        'id'                => $emp->id,
                        'name'              => $emp->name,
                        'is_active'         => (bool) $emp->is_active,
                        'account_type'      => $emp->account_type,
                        'department'        => $emp->department,
                        'cost_center'       => $emp->cost_center,
                        'cost_center_label' => $emp->costCenter?->label,
                    ] : null,
                    'license_count'       => count($licenses),
                    'licenses'            => $licenses,
                ];
            })->values();

            // 5. Summary über die VOLLE Menge (vor match_state-Filter & Cap) — verlässliche Abgleich-Kennzahlen.
            $summary = [
                'total_users'       => $users->count(),
                'total_assignments' => $assignments->count(),
                'matched_active'    => $users->filter(fn ($u) => $u['employee'] && $u['employee']['is_active'])->count(),
                'matched_inactive'  => $users->filter(fn ($u) => $u['employee'] && !$u['employee']['is_active'])->count(),
                'unmatched'         => $users->filter(fn ($u) => !$u['matched'])->count(),
            ];

            // 6. match_state-Filter auf die gruppierte Menge.
            $filtered = $users->filter(function ($u) use ($matchState) {
                return match ($matchState) {
                    'matched'   => $u['employee'] && $u['employee']['is_active'],
                    'inactive'  => $u['employee'] && !$u['employee']['is_active'],
                    'unmatched' => !$u['matched'],
                    default     => true,
                };
            })->sortBy(fn ($u) => mb_strtolower($u['display_name'] ?? $u['user_principal_name']))->values();

            // 7. Cap auf die Liste (Summary bleibt ungecappt).
            $usersTotal = $filtered->count();
            $page       = $filtered->take(self::MAX_USERS)->values();

            return ToolResult::success([
                'team_id'        => $teamId,
                'tenant_id'      => $tenantId,
                'match_state'    => $matchState,
                'tenants'        => $tenants->map(fn ($t) => [
                    'id'         => $t->id,
                    'name'       => $t->name,
                    'is_default' => (bool) $t->is_default,
                ])->all(),
                'summary'        => $summary,
                'users_total'    => $usersTotal,
                'users_returned' => $page->count(),
                'truncated'      => $usersTotal > $page->count(),
                'users'          => $page->all(),
                'currency'       => 'EUR',
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', 'Fehler beim Laden der Lizenz-User: ' . $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return ['read_only' => true, 'tags' => ['asset-manager', 'licenses', 'employees']];
    }
}
