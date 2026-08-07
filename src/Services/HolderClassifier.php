<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetTeamSetting;

/**
 * Bestimmt den {@see AssetHolder} `holder_type` aus konfigurierbaren Regeln (siehe docs/adr/0017).
 *
 * **Warum konfigurierbar und nicht im Code:** „alles mit `adm-` ist ein Admin-Account" ist eine
 * Konvention *eines* Kunden, keine Domänenwahrheit. Im Code stehend wäre sie eine Kundenspezifik im
 * Schema — genau das, was die Multi-Tenant-Leitplanke verbietet. Die Regeln liegen deshalb **pro
 * Tenant** in `asset_team_settings.holder_type_rules`.
 *
 * Regelformat (JSON-Array, erste Übereinstimmung gewinnt):
 * ```json
 * [
 *   {"type": "admin",    "field": "upn",   "match": "prefix",   "value": "adm-"},
 *   {"type": "service",  "field": "upn",   "match": "contains", "value": "svc"},
 *   {"type": "external", "field": "email", "match": "suffix",   "value": "@dienstleister.de"}
 * ]
 * ```
 * `field` ∈ `upn` | `email` | `display_name` · `match` ∈ `prefix` | `suffix` | `contains` | `equals`
 *
 * Vergleich ist case-insensitiv — Entra liefert UPNs in gemischter Schreibweise, und eine Regel, die
 * an Groß-/Kleinschreibung scheitert, wäre für den Pflegenden nicht nachvollziehbar.
 */
class HolderClassifier
{
    /** Synthetische UPN-Domain der Funktionskonten (vom Excel-Import erzeugt). */
    public const FUNCTION_UPN_SUFFIX = '@funktion.import.local';

    /** @var array<string, array<int, array<string, string>>> Regeln je Tenant, request-lokal memoisiert. */
    private static array $rulesCache = [];

    /**
     * Typ für einen Träger bestimmen.
     *
     * Reihenfolge und ihre Begründung:
     *  1. **Funktionskonto an der synthetischen UPN** — das ist eine Tatsache über die Herkunft des
     *     Datensatzes, keine Konvention, und darf von einer Kundenregel nicht übersteuert werden.
     *  2. **Ein bereits gesetzter Nicht-Default-Typ** — eine manuelle Korrektur im UI ist die bessere
     *     Information als jede Regel und überlebt daher jeden Sync.
     *  3. **Tenant-Regeln**, erste Übereinstimmung.
     *  4. `person` als Default.
     */
    public function classify(AssetHolder $holder, ?int $tenantId = null): string
    {
        $upn = (string) $holder->user_principal_name;

        if ($upn !== '' && str_ends_with(mb_strtolower($upn), self::FUNCTION_UPN_SUFFIX)) {
            return AssetHolder::TYPE_FUNCTION;
        }

        $current = $holder->holder_type;
        if ($current !== null && $current !== AssetHolder::TYPE_PERSON && in_array($current, AssetHolder::TYPES, true)) {
            return $current;
        }

        foreach ($this->rulesFor($tenantId ?? (int) $holder->tenant_id) as $rule) {
            if ($this->matches($holder, $rule)) {
                return $rule['type'];
            }
        }

        return AssetHolder::TYPE_PERSON;
    }

    /**
     * Typ eines Trägers neu bestimmen und speichern, falls er sich ändert.
     *
     * @return bool true, wenn der Typ geändert wurde.
     */
    public function apply(AssetHolder $holder, ?int $tenantId = null): bool
    {
        $type = $this->classify($holder, $tenantId);

        if ($holder->holder_type === $type) {
            return false;
        }

        $holder->holder_type = $type;
        $holder->save();

        return true;
    }

    /**
     * Regeln eines Tenants — validiert, damit eine kaputte JSON-Konfiguration die Klassifizierung
     * (und damit den Sync) nicht zum Absturz bringt, sondern nur nichts tut.
     *
     * @return array<int, array{type:string, field:string, match:string, value:string}>
     */
    public function rulesFor(?int $tenantId): array
    {
        if (! $tenantId) {
            return [];
        }

        $key = (string) $tenantId;

        if (array_key_exists($key, self::$rulesCache)) {
            return self::$rulesCache[$key];
        }

        $raw = AssetTeamSetting::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->value('holder_type_rules');

        return self::$rulesCache[$key] = $this->sanitize(is_array($raw) ? $raw : []);
    }

    /** @return array<int, array{type:string, field:string, match:string, value:string}> */
    public function sanitize(array $raw): array
    {
        $fields  = ['upn', 'email', 'display_name'];
        $matches = ['prefix', 'suffix', 'contains', 'equals'];
        $clean   = [];

        foreach ($raw as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type  = (string) ($rule['type'] ?? '');
            $field = (string) ($rule['field'] ?? 'upn');
            $match = (string) ($rule['match'] ?? 'prefix');
            $value = trim((string) ($rule['value'] ?? ''));

            // `person` als Regel-Ziel ist sinnlos (es ist der Default) und würde nur früher greifende
            // Regeln verdecken.
            if ($value === ''
                || ! in_array($type, AssetHolder::TYPES, true)
                || $type === AssetHolder::TYPE_PERSON
                || ! in_array($field, $fields, true)
                || ! in_array($match, $matches, true)
            ) {
                continue;
            }

            $clean[] = ['type' => $type, 'field' => $field, 'match' => $match, 'value' => $value];
        }

        return $clean;
    }

    /** Memo verwerfen — nach dem Speichern geänderter Regeln. */
    public static function forget(): void
    {
        self::$rulesCache = [];
    }

    /** @param array{type:string, field:string, match:string, value:string} $rule */
    private function matches(AssetHolder $holder, array $rule): bool
    {
        $subject = mb_strtolower((string) match ($rule['field']) {
            'email'        => $holder->email,
            'display_name' => $holder->display_name,
            default        => $holder->user_principal_name,
        });

        if ($subject === '') {
            return false;
        }

        $value = mb_strtolower($rule['value']);

        return match ($rule['match']) {
            'suffix'   => str_ends_with($subject, $value),
            'contains' => str_contains($subject, $value),
            'equals'   => $subject === $value,
            default    => str_starts_with($subject, $value),
        };
    }
}
