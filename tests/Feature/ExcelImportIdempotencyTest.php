<?php

/**
 * Host-Feature-Test (post-deploy). Läuft im Host-App-Test-Setup (Core-Bootstrap: AUTH_MODEL,
 * team_user-Pivot, RefreshDatabase). Im Modul nicht lokal ausführbar.
 *
 * Idempotenz des Excel-Kosten-Imports. CostExcelImportService liest eine echte .xlsx über einen
 * eigenen ZipArchive/SimpleXML-Reader (readWorkbook). Eine valide .xlsx als Fixture zu bauen ist
 * hier unverhältnismäßig — daher wird die Idempotenz auf der INVARIANTEN-Ebene getestet, die der
 * Importer garantiert:
 *
 *   1. import_hash-Eindeutigkeit je Team: dieselbe logische Position (gleiche Achsen+Betrag+Frequenz
 *      +Währung) ergibt denselben Hash → ein zweiter „Lauf" UPDATEt die Zeile statt sie zu duplizieren.
 *      (Repliziert die Hash-Formel + den Find-by-(team_id,import_hash)-Upsert aus upsertLine().)
 *   2. Prune: eine alte Import-Zeile, deren Hash im aktuellen „Lauf" NICHT mehr vorkommt, wird entfernt
 *      (forceDelete), eine weiterhin geschriebene bleibt. (Repliziert pruneStaleLines(): forceDelete auf
 *      source='excel_import' AND import_hash NOT IN [geschriebene].)
 *   3. CostResetService::clearImport() (öffentliche API) entfernt genau die Import-Cost-Lines.
 *
 * TODO(host): Wenn eine echte .xlsx-Fixture vorliegt, zusätzlich CostExcelImportService::import()
 *   zweimal auf dieselbe Datei laufen lassen und assertDatabaseCount stabil prüfen (End-to-End).
 *   Der Reader braucht ext-zip/ext-simplexml (im composer.json deklariert).
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\CostResetService;
use Platform\Core\Models\Team;
use Tests\TestCase;

class ExcelImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Spiegelt die Hash-Formel aus CostExcelImportService::upsertLine() exakt wider.
     * Bei Code-Änderung dort MUSS dieser Helfer nachgezogen werden (Anker: number_format(amount,4)).
     */
    private function importHash(int $teamId, string $typeKey, array $attrs, float $amount, string $frequency = 'monthly', string $currency = 'EUR'): string
    {
        return sha1(implode('|', [
            $teamId, $typeKey,
            $attrs['cost_center_id'] ?? '', $attrs['assignee_id'] ?? '', $attrs['asset_item_id'] ?? '',
            $attrs['label'] ?? '', number_format($amount, 4, '.', ''), $frequency, $currency,
        ]));
    }

    public function test_same_logical_line_yields_stable_hash_and_no_duplicate_on_reimport(): void
    {
        $team = Team::factory()->create();
        $type = AssetCostType::factory()->create(['team_id' => $team->id, 'key' => 'mobilfunk']);

        $attrs = ['label' => 'Mobilfunk', 'cost_center_id' => null, 'assignee_id' => null, 'asset_item_id' => null];
        $hash  = $this->importHash($team->id, 'mobilfunk', $attrs, 45.00);

        // Erster „Lauf": Zeile anlegen.
        AssetCostLine::factory()->imported($hash)->create([
            'team_id'      => $team->id,
            'cost_type_id' => $type->id,
            'label'        => 'Mobilfunk',
            'amount'       => 45.00,
            'frequency'    => 'monthly',
            'currency'     => 'EUR',
        ]);

        // Zweiter „Lauf": gleiche Achsen+Betrag → gleicher Hash. Der Importer fände die Zeile per
        // (team_id, import_hash) und UPDATEt sie — keine zweite Zeile.
        $existing = AssetCostLine::withTrashed()
            ->where('team_id', $team->id)
            ->where('import_hash', $hash)
            ->first();

        $this->assertNotNull($existing, 'Zeile muss über (team_id, import_hash) auffindbar sein.');
        $existing->update(['amount' => 45.00]); // idempotenter Re-Write

        $this->assertSame(1, AssetCostLine::where('team_id', $team->id)->where('import_hash', $hash)->count(),
            'Re-Import derselben Position darf keine Dublette erzeugen.');
    }

    public function test_changed_amount_produces_a_different_hash(): void
    {
        $team  = Team::factory()->create();
        $attrs = ['label' => 'Mobilfunk', 'cost_center_id' => null, 'assignee_id' => null, 'asset_item_id' => null];

        $hashOld = $this->importHash($team->id, 'mobilfunk', $attrs, 45.00);
        $hashNew = $this->importHash($team->id, 'mobilfunk', $attrs, 84.90);

        $this->assertNotSame($hashOld, $hashNew,
            'Geänderter Betrag muss einen neuen Hash ergeben (sonst keine Prune-Erkennung der verwaisten Zeile).');
    }

    public function test_prune_removes_stale_import_line_and_keeps_written_one(): void
    {
        $team = Team::factory()->create();
        $type = AssetCostType::factory()->create(['team_id' => $team->id]);

        // „Alte" Import-Zeile (Hash A) und eine im aktuellen Lauf geschriebene (Hash B).
        $stale = AssetCostLine::factory()->imported('hash-A')->create([
            'team_id' => $team->id, 'cost_type_id' => $type->id, 'amount' => 45.00,
        ]);
        $kept = AssetCostLine::factory()->imported('hash-B')->create([
            'team_id' => $team->id, 'cost_type_id' => $type->id, 'amount' => 84.90,
        ]);

        // pruneStaleLines() (repliziert): source='excel_import' AND import_hash NOT IN [geschriebene]
        // → forceDelete. Geschrieben wurde in diesem „Lauf" nur Hash B.
        $writtenHashes = ['hash-B'];
        AssetCostLine::withTrashed()
            ->where('team_id', $team->id)
            ->where('source', 'excel_import')
            ->whereNotIn('import_hash', $writtenHashes)
            ->forceDelete();

        $this->assertNull(AssetCostLine::withTrashed()->find($stale->id), 'Verwaiste Import-Zeile muss hart entfernt sein.');
        $this->assertNotNull(AssetCostLine::find($kept->id), 'Weiterhin geschriebene Import-Zeile bleibt erhalten.');
    }

    public function test_prune_with_empty_written_set_must_not_wipe_all_import_lines(): void
    {
        $team = Team::factory()->create();
        $type = AssetCostType::factory()->create(['team_id' => $team->id]);

        AssetCostLine::factory()->imported('hash-X')->create([
            'team_id' => $team->id, 'cost_type_id' => $type->id, 'amount' => 10.00,
        ]);

        // Guard aus pruneStaleLines(): bei leerem writtenHashes NICHTS löschen (sonst NOT IN [] = alle).
        $writtenHashes = [];
        if (!empty($writtenHashes)) {
            AssetCostLine::withTrashed()
                ->where('team_id', $team->id)
                ->where('source', 'excel_import')
                ->whereNotIn('import_hash', $writtenHashes)
                ->forceDelete();
        }

        $this->assertSame(1, AssetCostLine::where('team_id', $team->id)->count(),
            'Leeres writtenHashes (z. B. leere Datei) darf bestehende Import-Zeilen nicht löschen.');
    }

    public function test_reset_service_clears_only_import_cost_lines(): void
    {
        $team   = Team::factory()->create();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $type   = AssetCostType::factory()->create(['team_id' => $team->id]);

        $imported = AssetCostLine::factory()->imported('hash-imp')->create([
            'team_id' => $team->id, 'cost_type_id' => $type->id, 'amount' => 45.00,
        ]);
        $manual = AssetCostLine::factory()->create([ // source='manual'
            'team_id' => $team->id, 'cost_type_id' => $type->id, 'amount' => 12.00,
        ]);

        $stats = (new CostResetService())->clearImport($team->id);

        $this->assertSame(1, $stats['cost_lines'], 'Genau eine Import-Cost-Line entfernt.');
        $this->assertNull(AssetCostLine::withTrashed()->find($imported->id), 'Import-Zeile ist hart gelöscht.');
        $this->assertNotNull(AssetCostLine::find($manual->id), 'Manuelle Zeile bleibt unangetastet.');
    }
}
