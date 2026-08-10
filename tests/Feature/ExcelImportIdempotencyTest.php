<?php

/**
 * Host-Feature-Test (post-deploy). Läuft im Host-App-Test-Setup (Core-Bootstrap: AUTH_MODEL,
 * team_user-Pivot, RefreshDatabase). Im Modul nicht lokal ausführbar.
 *
 * Idempotenz des Excel-Kosten-Imports. Zwei Ebenen:
 *
 * ## A) End-to-End über CostExcelImportService::import()
 *
 * Der Reader (readWorkbook) ist selbstgebaut über ZipArchive/SimpleXML — wir kontrollieren also
 * beide Seiten und können eine minimale, echte .xlsx im Test erzeugen ({@see writeXlsx()}).
 * Das war lange als `TODO(host)` offen, mit teurer Folge: weil der Importer im Test nie lief,
 * blieb unbemerkt, dass er seit S1 (`tenant_id NOT NULL`) in eine Constraint-Verletzung lief —
 * `import()` setzte den Ziel-Tenant und überschrieb ihn sechs Zeilen später wieder mit null
 * (Überbleibsel aus der Zeit, als das Feld ein Memo-Cache war). Die Tests unten decken genau
 * diesen Pfad ab: jeder vom Importer geschriebene Datensatz muss seinen Tenant tragen.
 *
 * ## B) Invarianten-Ebene (bestand schon)
 *
 * Zusätzlich die Formel-Invarianten, die der Importer garantiert:
 *
 *   1. import_hash-Eindeutigkeit je Team: dieselbe logische Position (gleiche Achsen+Betrag+Frequenz
 *      +Währung) ergibt denselben Hash → ein zweiter „Lauf" UPDATEt die Zeile statt sie zu duplizieren.
 *      (Repliziert die Hash-Formel + den Find-by-(team_id,import_hash)-Upsert aus upsertLine().)
 *   2. Prune: eine alte Import-Zeile, deren Hash im aktuellen „Lauf" NICHT mehr vorkommt, wird entfernt
 *      (forceDelete), eine weiterhin geschriebene bleibt. (Repliziert pruneStaleLines(): forceDelete auf
 *      source='excel_import' AND import_hash NOT IN [geschriebene].)
 *   3. CostResetService::clearImport() (öffentliche API) entfernt genau die Import-Cost-Lines.
 *
 * Der Reader braucht ext-zip/ext-simplexml (im composer.json deklariert).
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Models\AssetVendor;
use Platform\AssetManager\Services\CostExcelImportService;
use Platform\AssetManager\Services\CostResetService;
use Platform\AssetManager\Services\TenantContext;
use Platform\Core\Models\Team;
use Tests\TestCase;

class ExcelImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Im Test erzeugte Fixture-Dateien — in tearDown() aufräumen. */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // Der Importer setzt einen Tenant-Override für den Rest des Requests (forceTenant).
        // Zwischen Tests zurücknehmen, sonst leckt er in den nächsten Fall.
        TenantContext::forceTenant(null);
        TenantContext::forget();

        parent::tearDown();
    }

    // ---- A) End-to-End: echte .xlsx durch CostExcelImportService::import() -----------------------

    /**
     * Baut eine minimale, valide .xlsx für den Reader in {@see CostExcelImportService::readWorkbook()}.
     * Bewusst nur die Teile, die er liest: workbook.xml (+ rels) und je Blatt eine sheetData.
     * Werte gehen als inlineStr bzw. als Zahl raus — beides Pfade, die parseSheet() unterscheidet.
     *
     * @param  array<string, list<array<string, string|int|float>>>  $sheets  Blattname => Zeilen (Spalte => Wert)
     */
    private function writeXlsx(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'am-import-') . '.xlsx';
        $this->tempFiles[] = $path;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail('Fixture-.xlsx konnte nicht erzeugt werden.');
        }

        $wbSheets = '';
        $rels     = '';
        $index    = 1;

        foreach ($sheets as $name => $rows) {
            $rid    = 'rId' . $index;
            $target = 'worksheets/sheet' . $index . '.xml';

            $wbSheets .= '<sheet name="' . htmlspecialchars($name, ENT_XML1) . '" sheetId="' . $index . '" r:id="' . $rid . '"/>';
            $rels .= '<Relationship Id="' . $rid . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="' . $target . '"/>';

            $zip->addFromString('xl/' . $target, $this->sheetXml($rows));
            $index++;
        }

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $wbSheets . '</sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels . '</Relationships>');

        $zip->close();

        return $path;
    }

    /** @param list<array<string, string|int|float>> $rows */
    private function sheetXml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $rowNumber = 0;
        foreach ($rows as $cells) {
            $rowNumber++;
            $xml .= '<row r="' . $rowNumber . '">';
            foreach ($cells as $column => $value) {
                $ref = $column . $rowNumber;
                $xml .= is_int($value) || is_float($value)
                    ? '<c r="' . $ref . '"><v>' . $value . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $value, ENT_XML1) . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    /**
     * Eine Arbeitsmappe mit den zwei Blättern, die zusammen alle drei tenant-pflichtigen
     * Schreibpfade abdecken: Kostenposition (Übersicht), Asset + Kreditor (Internet).
     * Kostenstelle 2599 hängt laut CostBootstrap::COST_CENTER_PARENT unter „rhein-ruhr" —
     * damit prüft der Import zugleich die Baum-Auflösung.
     */
    private function makeWorkbook(): string
    {
        return $this->writeXlsx([
            'Übersicht' => [
                ['A' => 'Name', 'B' => 'KSt', 'L' => 'Mobilfunk'],           // Kopfzeile (wird übersprungen)
                ['A' => 'ERIKA MUSTERMANN', 'B' => '2599', 'L' => 29.90],
            ],
            'Internet' => [
                ['A' => 'Anschluss', 'B' => 'Anschrift', 'C' => 'Standort', 'D' => 'Anbieter', 'E' => 'Betrag', 'F' => 'KSt'],
                ['A' => 'DSL Zentrale', 'B' => 'Hauptstr. 1', 'C' => 'Köln', 'D' => 'Telekom', 'E' => 49.99, 'F' => '2599'],
            ],
        ]);
    }

    /**
     * Regressionstest zum Tenant-Bug: JEDER vom Importer geschriebene Datensatz muss den Ziel-Tenant
     * tragen. Vor dem Fix schrieb der Importer überall null — seit `tenant_id NOT NULL` (S1) bricht
     * der Lauf damit ab, davor wären die Daten tenant-los und in keiner Auswertung sichtbar gewesen.
     */
    public function test_import_stamps_the_target_tenant_on_every_written_record(): void
    {
        $team   = Team::factory()->create();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);

        (new CostExcelImportService())->import($team->id, $this->makeWorkbook(), 'test-batch');

        $lines = AssetCostLine::withoutTenantScope()->where('team_id', $team->id)->get();
        $this->assertGreaterThan(0, $lines->count(), 'Der Import muss Kostenpositionen geschrieben haben.');
        $this->assertEmpty($lines->whereNull('tenant_id'),
            'Keine Kostenposition darf ohne Tenant geschrieben werden.');
        $this->assertEmpty($lines->where('tenant_id', '!=', $tenant->id),
            'Alle Kostenpositionen gehören in den Ziel-Tenant.');

        $items = AssetItem::withoutTenantScope()->where('team_id', $team->id)->get();
        $this->assertGreaterThan(0, $items->count(), 'Das Internet-Blatt legt ein Asset an.');
        $this->assertEmpty($items->whereNull('tenant_id'), 'Kein Asset darf ohne Tenant entstehen.');

        $vendors = AssetVendor::withoutTenantScope()->where('team_id', $team->id)->get();
        $this->assertGreaterThan(0, $vendors->count(), 'Der Anbieter aus dem Internet-Blatt wird angelegt.');
        $this->assertEmpty($vendors->whereNull('tenant_id'), 'Kein Kreditor darf ohne Tenant entstehen.');
    }

    /** Der eigentliche Idempotenz-Beleg: derselbe Lauf zweimal ergibt denselben Bestand. */
    public function test_running_the_same_import_twice_keeps_the_row_count_stable(): void
    {
        $team = Team::factory()->create();
        AssetTenant::factory()->default()->create(['team_id' => $team->id]);

        $file    = $this->makeWorkbook();
        $service = new CostExcelImportService();

        $service->import($team->id, $file, 'test-batch');
        $afterFirst = AssetCostLine::withoutTenantScope()->where('team_id', $team->id)->count();

        $service->import($team->id, $file, 'test-batch');
        $afterSecond = AssetCostLine::withoutTenantScope()->where('team_id', $team->id)->count();

        $this->assertGreaterThan(0, $afterFirst);
        $this->assertSame($afterFirst, $afterSecond,
            'Ein zweiter Lauf derselben Datei darf keine Dubletten erzeugen (import_hash-Upsert).');
    }

    /**
     * Import in einen NICHT-Default-Tenant. Genau hier hätte der Bug auch ohne NOT-NULL wehgetan:
     * die Daten wären still im Default-Tenant gelandet, also beim falschen Kunden.
     */
    public function test_import_targets_the_requested_tenant_not_the_default(): void
    {
        $team    = Team::factory()->create();
        $default = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $target  = AssetTenant::factory()->create(['team_id' => $team->id]);

        (new CostExcelImportService())->import($team->id, $this->makeWorkbook(), 'test-batch', false, $target->id);

        $this->assertSame(0, AssetCostLine::withoutTenantScope()->where('team_id', $team->id)
            ->where('tenant_id', $default->id)->count(),
            'Nichts darf im Default-Tenant landen, wenn ein anderer Tenant angefordert wurde.');

        $this->assertGreaterThan(0, AssetCostLine::withoutTenantScope()->where('team_id', $team->id)
            ->where('tenant_id', $target->id)->count(),
            'Die Kostenpositionen gehören in den angeforderten Tenant.');
    }

    /**
     * Der Importer löst Kostenstellen-Codes gegen den Baum auf (S5): 2599 bekommt den Gruppen-Knoten
     * „rhein-ruhr" als Elternteil, nicht eine flache Nachbar-Kostenstelle.
     */
    public function test_import_resolves_cost_center_codes_into_the_tree(): void
    {
        $team   = Team::factory()->create();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);

        (new CostExcelImportService())->import($team->id, $this->makeWorkbook(), 'test-batch');

        $node = AssetCostCenter::withoutTenantScope()
            ->where('tenant_id', $tenant->id)->where('code', '2599')->first();

        $this->assertNotNull($node, 'Die Kostenstelle aus der Datei muss angelegt sein.');
        $this->assertNotNull($node->parent_id, 'Sie hängt unter einem Gruppen-Knoten, nicht auf der Wurzel.');

        $parent = AssetCostCenter::withoutTenantScope()->find($node->parent_id);
        $this->assertSame('rhein-ruhr', $parent->code);
        $this->assertNull($parent->parent_id, 'Der Gruppen-Knoten ist selbst eine Wurzel.');
    }

    /**
     * Der Prune am Ende jedes Laufs ist ein `forceDelete` über die Import-Zeilen eines Teams. Er darf
     * nur den Ziel-Tenant treffen — sonst räumt ein Import für Kunde B die Kostendaten von Kunde A weg.
     */
    public function test_import_for_one_tenant_does_not_prune_another_tenants_import_lines(): void
    {
        $team    = Team::factory()->create();
        $tenantA = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $tenantB = AssetTenant::factory()->create(['team_id' => $team->id]);

        $service = new CostExcelImportService();
        $service->import($team->id, $this->makeWorkbook(), 'batch-a', false, $tenantA->id);

        $inABefore = AssetCostLine::withoutTenantScope()->where('tenant_id', $tenantA->id)->count();
        $this->assertGreaterThan(0, $inABefore);

        // Zweiter Lauf mit ANDEREN Inhalten in den anderen Tenant: die Hashes aus A tauchen im
        // writtenHashes von B nicht auf — ohne Tenant-Filter würde der Prune sie mitnehmen.
        $otherFile = $this->writeXlsx([
            'Übersicht' => [
                ['A' => 'Name', 'B' => 'KSt', 'L' => 'Mobilfunk'],
                ['A' => 'MAX BEISPIEL', 'B' => '1500', 'L' => 12.50],
            ],
        ]);
        $service->import($team->id, $otherFile, 'batch-b', false, $tenantB->id);

        $this->assertSame($inABefore, AssetCostLine::withoutTenantScope()->where('tenant_id', $tenantA->id)->count(),
            'Der Import in Tenant B darf die Import-Zeilen von Tenant A nicht anfassen.');
        $this->assertGreaterThan(0, AssetCostLine::withoutTenantScope()->where('tenant_id', $tenantB->id)->count(),
            'Tenant B hat seine eigenen Zeilen bekommen.');
    }

    // ---- B) Invarianten-Ebene -------------------------------------------------------------------

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
