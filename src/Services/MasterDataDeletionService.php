<?php

namespace Platform\AssetManager\Services;

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
    /**
     * Kostenstelle löschen. Kinder werden NICHT mitgelöscht: `parent_id` ist nullOnDelete, sie
     * rutschen also zu obersten Knoten hoch und brauchen danach eine neue `depth`. Die übrigen FKs
     * (Träger, Kostenpositionen) sind ebenfalls nullOnDelete — die Zuordnung fällt weg, die
     * Datensätze bleiben.
     *
     * @return array{ok: bool, message: string, code?: string, promoted_children?: int}
     */
    public function deleteCostCenter(int $teamId, int $id): array
    {
        $center = AssetCostCenter::where('team_id', $teamId)->find($id);
        if (! $center) {
            return ['ok' => false, 'message' => 'Kostenstelle nicht gefunden.'];
        }

        $code       = $center->code;
        $childCount = AssetCostCenter::where('parent_id', $center->id)->count();

        $center->delete();

        // Die hochgerutschten Kinder tragen noch die alte depth.
        AssetCostCenter::whereNull('parent_id')->where('depth', '>', 0)->get()
            ->each(fn (AssetCostCenter $node) => $node->recomputeDepth());

        return [
            'ok'                => true,
            'code'              => $code,
            'promoted_children' => $childCount,
            'message'           => $childCount > 0
                ? "Kostenstelle {$code} gelöscht — {$childCount} untergeordnete Kostenstelle(n) sind jetzt oberste Knoten, Zuordnungen wurden entfernt."
                : "Kostenstelle {$code} gelöscht (Zuordnungen wurden entfernt).",
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
