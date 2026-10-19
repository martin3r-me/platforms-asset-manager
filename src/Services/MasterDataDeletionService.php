<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetTeamSetting;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetVendor;

/**
 * Löschen von Kostenpositionen und Stammdaten — die **eine** Wahrheit für UI und MCP-Tools.
 *
 * Vorher lag die Logik in `Livewire\MasterData\Index`, war also nur über die Oberfläche erreichbar:
 * die Tools konnten anlegen und ändern, aber nichts wieder wegräumen. Ein zweiter Satz Löschregeln
 * im Tool wäre die schlechtere Antwort gewesen — die Sperren unten (Kostenart trägt noch Kosten,
 * Kinder rutschen hoch) sind fachliche Entscheidungen, keine UI-Details.
 *
 * Jede Methode liefert `['ok' => bool, 'message' => string, …]`. `ok = false` heißt **bewusst
 * blockiert** (mit Begründung), nicht „Fehler" — der Aufrufer entscheidet, ob daraus eine Flash-
 * Meldung oder ein Tool-Fehler wird.
 *
 * Alle Methoden sind team-gescopet; der Tenant kommt zusätzlich über den Global Scope
 * ({@see \Platform\AssetManager\Concerns\TenantScopable}) und muss vom Aufrufer gesetzt sein.
 */
class MasterDataDeletionService
{
    /** Einstellungs-Rollen, die auf eine Kostenstelle zeigen — Spalte am AssetTeamSetting → Bezeichnung. */
    public const COST_CENTER_SETTING_ROLES = [
        'license_pool_cost_center_id'     => 'Lizenz-Pool',
        'license_overflow_cost_center_id' => 'Lizenz-Überhang',
        'telephony_cost_center_id'        => 'Telefonie-Sammelstelle',
        'datev_source_cost_center_id'     => 'DATEV-Quellkostenstelle',
    ];

    /**
     * Kostenstelle löschen. Kinder werden NICHT mitgelöscht: `parent_id` ist nullOnDelete, sie
     * rutschen also zu obersten Knoten hoch und brauchen danach eine neue `depth`. Die übrigen FKs
     * (Träger, Kostenpositionen, Geräte) sind ebenfalls nullOnDelete — die Zuordnung fällt weg, die
     * Datensätze bleiben.
     *
     * Gesperrt, solange eine Einstellung auf die Kostenstelle zeigt (#966): Pool, Überhang,
     * Telefonie-Sammelstelle und DATEV-Quelle wären sonst still genullt worden — die Lizenz-Kaskade,
     * der Festnetz-Import und die DATEV-Umbuchung liefen danach ohne Ziel.
     *
     * @return array{ok: bool, message: string, code?: string, promoted_children?: int, impact?: array<string, int>, dry_run?: bool}
     */
    public function deleteCostCenter(int $teamId, int $id, bool $dryRun = false): array
    {
        $center = AssetCostCenter::where('team_id', $teamId)->find($id);
        if (! $center) {
            return ['ok' => false, 'message' => 'Kostenstelle nicht gefunden.'];
        }

        $code = $center->code;

        $roles = [];
        $settings = AssetTeamSetting::withoutTenantScope()->where('team_id', $teamId)->get();
        foreach ($settings as $setting) {
            foreach (self::COST_CENTER_SETTING_ROLES as $column => $label) {
                if ((int) $setting->{$column} === (int) $center->id) {
                    $roles[] = $label;
                }
            }
        }
        if ($roles !== []) {
            return [
                'ok'      => false,
                'code'    => $code,
                'message' => "Kostenstelle {$code} ist in den Einstellungen als " . implode(', ', array_unique($roles))
                    . ' hinterlegt — dort erst eine andere Kostenstelle wählen, dann ist sie löschbar.',
            ];
        }

        $impact = [
            'children'   => AssetCostCenter::where('parent_id', $center->id)->count(),
            'holders'    => AssetHolder::where('team_id', $teamId)->where('cost_center_id', $center->id)->count(),
            'cost_lines' => AssetCostLine::where('team_id', $teamId)->where('cost_center_id', $center->id)->count(),
            'devices'    => AssetDevice::where('team_id', $teamId)->where('cost_center_id', $center->id)->count(),
        ];

        $summary = sprintf('%d Träger, %d Kostenpositionen, %d Geräte verlieren die Zuordnung; %d untergeordnete Kostenstelle(n) werden oberste Knoten.',
            $impact['holders'], $impact['cost_lines'], $impact['devices'], $impact['children']);

        if ($dryRun) {
            return [
                'ok'      => true,
                'dry_run' => true,
                'code'    => $code,
                'impact'  => $impact,
                'message' => "Vorschau: Kostenstelle {$code} löschen — {$summary}",
            ];
        }

        // Der alte Code-String am Träger (`cost_center`) bliebe sonst als Altwert neben einer leeren
        // `cost_center_id` stehen und tauchte in Auswertungen über den Freitext wieder auf.
        // Je Träger über `save()` statt Query-Builder-`update()`: der Verlust der Kostenstelle ist eine
        // Buchungsänderung und gehört in den Verlauf (#897). FK und Code gemeinsam leeren.
        AssetHolder::where('team_id', $teamId)->where('cost_center_id', $center->id)->get()
            ->each(fn (AssetHolder $holder) => $holder->update(['cost_center' => null, 'cost_center_id' => null]));

        // Kinder ausdrücklich abhängen statt sich nur auf nullOnDelete zu verlassen — ohne aktive
        // FK-Prüfung (SQLite) blieben sie sonst unter einer gelöschten Kostenstelle hängen.
        AssetCostCenter::where('parent_id', $center->id)->update(['parent_id' => null]);

        $center->delete();

        // Die hochgerutschten Kinder tragen noch die alte depth.
        AssetCostCenter::whereNull('parent_id')->where('depth', '>', 0)->get()
            ->each(fn (AssetCostCenter $node) => $node->recomputeDepth());

        return [
            'ok'                => true,
            'code'              => $code,
            'promoted_children' => $impact['children'],
            'impact'            => $impact,
            'message'           => "Kostenstelle {$code} gelöscht — {$summary}",
        ];
    }

    /**
     * Kostenart löschen — nur, wenn sie keine Kosten mehr trägt.
     *
     * Zwei Sperren, beide gegen stillen Datenverlust:
     *  1. `cost_type_id` ist cascadeOnDelete → hängende Kostenpositionen würden mitgelöscht.
     *  2. Virtuelle Quellen (hardware_afa/ms_license/asset_device) haben NIE Kostenpositionen
     *     (`cost_lines_count = 0`), tragen ihre Beträge aber aus Inventar-AfA, bepreisten
     *     Lizenz-Zuweisungen oder Geräten. Ein Löschen kippte diese Beträge aus dem Pivot.
     *
     * @return array{ok: bool, message: string, name?: string}
     */
    public function deleteCostType(int $teamId, int $id): array
    {
        $type = AssetCostType::where('team_id', $teamId)->withCount('costLines')->find($id);
        if (! $type) {
            return ['ok' => false, 'message' => 'Kostenart nicht gefunden.'];
        }

        if ($type->cost_lines_count > 0) {
            return [
                'ok'      => false,
                'message' => "Kostenart {$type->name} hat {$type->cost_lines_count} Position(en) — erst dort umbuchen oder löschen, dann ist die Kostenart löschbar.",
            ];
        }

        $virtualSources = [
            AssetCostType::SOURCE_HARDWARE_AFA,
            AssetCostType::SOURCE_MS_LICENSE,
            AssetCostType::SOURCE_ASSET_DEVICE,
        ];

        if (in_array($type->aggregation_source, $virtualSources, true)) {
            $contributes = app(CostAggregationService::class)
                ->normalizedLines($teamId)
                ->contains(fn ($l) => (int) $l['cost_type_id'] === (int) $type->id && (float) $l['amount'] != 0.0);

            if ($contributes) {
                return [
                    'ok'      => false,
                    'message' => "Kostenart {$type->name} (Quelle: {$type->aggregation_source}) trägt aktuell Kosten aus Geräten/Inventar/Lizenzen — diese erst entkoppeln oder umbuchen, dann ist die Kostenart löschbar.",
                ];
            }
        }

        $name = $type->name;
        $type->delete();

        return ['ok' => true, 'name' => $name, 'message' => "Kostenart {$name} gelöscht."];
    }

    /**
     * Kreditor löschen. `vendor_id` (Kostenpositionen) und `vendor_default_id` (Kostenarten) sind
     * nullOnDelete → die Zuordnungen fallen weg, Positionen und Kostenarten bleiben erhalten.
     *
     * @return array{ok: bool, message: string, name?: string}
     */
    public function deleteVendor(int $teamId, int $id): array
    {
        $vendor = AssetVendor::where('team_id', $teamId)->find($id);
        if (! $vendor) {
            return ['ok' => false, 'message' => 'Kreditor nicht gefunden.'];
        }

        $name = $vendor->name;
        $vendor->delete();

        return ['ok' => true, 'name' => $name, 'message' => "Kreditor {$name} gelöscht (Zuordnungen wurden entfernt)."];
    }

    /**
     * Kostenposition löschen (Soft-Delete, wie im UI).
     *
     * Bewusst soft: eine Import-Zeile, die hier gelöscht wird, soll beim nächsten Excel-Lauf NICHT
     * wiederbelebt werden — `CostExcelImportService::upsertLine()` sucht `withTrashed()` und lässt
     * die bewusste Löschung gewinnen (restore-or-refuse → refuse).
     *
     * @return array{ok: bool, message: string, label?: string}
     */
    public function deleteCostLine(int $teamId, int $id): array
    {
        $line = AssetCostLine::where('team_id', $teamId)->find($id);
        if (! $line) {
            return ['ok' => false, 'message' => 'Kostenposition nicht gefunden.'];
        }

        $label = $line->label;
        $line->delete();

        return ['ok' => true, 'label' => $label, 'message' => "Kostenposition {$label} gelöscht."];
    }
}
