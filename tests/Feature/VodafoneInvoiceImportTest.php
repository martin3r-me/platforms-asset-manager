<?php

/**
 * Host-Feature-Test (post-deploy). Deckt den Import der Vodafone-Rechnungsanalyse ab (ADR 0018).
 *
 * Die Fixture spiegelt die Struktur eines echten Portal-Exports (verifiziert 07/2026): Blatt
 * `Rechnungsdaten`, Spalte A trennt `Rechnung` / `Rufnummer` / `Rechnungsposten`, Beträge in
 * Spalte J (netto), Rufnummer in K, Kostenstelle in M als `2599 - HKS Team Düsseldorf`.
 */

namespace Platform\AssetManager\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneInvoiceImportService;
use Platform\AssetManager\Support\PhoneNumber;
use Platform\Core\Models\Team;
use Tests\TestCase;

class VodafoneInvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) @unlink($f);
        }
        TenantContext::forceTenant(null);
        TenantContext::forget();

        parent::tearDown();
    }

    // ---- Rufnummern-Normalisierung ---------------------------------------------------------

    /**
     * Der Abgleich steht und fällt hiermit: der Export liefert `1721234567`, Entra `+49 172 …`,
     * manuelle Pflege `0172/…`. Treffen die nicht auf dieselbe Form, matcht keine einzige Zeile.
     */
    public function test_phone_numbers_from_every_source_normalise_to_the_same_value(): void
    {
        $expected = '+491721234567';

        foreach (['1721234567', '+49 172 1234567', '0172/123 45 67', '00491721234567', '491721234567', '+49 (0)172 1234567'] as $variant) {
            $this->assertSame($expected, PhoneNumber::e164($variant), "Variante '{$variant}' muss auf {$expected} normalisieren.");
        }

        $this->assertNull(PhoneNumber::e164(null), 'Kein Wert ist etwas anderes als ein Wert, der nicht passt.');
        $this->assertNull(PhoneNumber::e164('   '));
        $this->assertSame('01721234567', PhoneNumber::national('+491721234567'), 'Anzeigeform für Labels.');
    }

    // ---- Import ----------------------------------------------------------------------------

    public function test_import_matches_numbers_to_holders_and_writes_cost_lines(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();

        // Träger mit Nummer in Entra-Schreibweise — der Export liefert die nationale Form.
        $holder = AssetHolder::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id,
            'display_name' => 'ERIKA MUSTERMANN', 'mobile_phone' => '+49 172 1234567',
        ]);

        $file = $this->makeInvoice([
            ['msisdn' => '1721234567', 'net' => 21.00, 'center' => '2599 - HKS Team Düsseldorf'],
        ]);

        $result = (new VodafoneInvoiceImportService())->import($team->id, $file, 'test', false, $tenant->id);

        $this->assertSame(1, $result['stats']['numbers']);
        $this->assertSame(1, $result['stats']['matched']);
        $this->assertSame(0, $result['stats']['unmatched']);

        $line = AssetCostLine::withoutTenantScope()->where('team_id', $team->id)
            ->where('source', VodafoneInvoiceImportService::SOURCE)->first();

        $this->assertNotNull($line);
        $this->assertSame($holder->id, $line->assignee_id, 'Die Position muss dem Träger zugeordnet sein.');
        $this->assertEqualsWithDelta(21.00, (float) $line->amount, 0.01);
        $this->assertEqualsWithDelta(21.00, (float) $line->monthly_amount, 0.01);
        $this->assertSame($tenant->id, $line->tenant_id);
    }

    /** Ein Betrag, der still verschwindet, ist schlimmer als eine Zeile, die auffällt. */
    public function test_unmatched_number_still_creates_a_flagged_line(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();

        $file = $this->makeInvoice([
            ['msisdn' => '1729999999', 'net' => 46.00, 'center' => '2599 - HKS Team Düsseldorf'],
        ]);

        $result = (new VodafoneInvoiceImportService())->import($team->id, $file, 'test', false, $tenant->id);

        $this->assertSame(1, $result['stats']['unmatched']);

        $line = AssetCostLine::withoutTenantScope()->where('team_id', $team->id)
            ->where('source', VodafoneInvoiceImportService::SOURCE)->first();

        $this->assertNotNull($line, 'Auch ohne Treffer muss die Kostenposition entstehen.');
        $this->assertNull($line->assignee_id);
        $this->assertTrue((bool) ($line->raw_data['unmatched'] ?? false), 'Die Zeile muss als nicht zugeordnet markiert sein.');
        $this->assertEqualsWithDelta(46.00, (float) $line->amount, 0.01);
    }

    /**
     * Der Kern des Idempotenz-Versprechens: Der Preis schwankt monatlich (Zusatzbuchungen,
     * Verbrauch). Die Zeile muss dabei ihre Identität behalten — sonst bekäme sie jeden Monat eine
     * neue ID, und alles, was daran hängt, verliert den Bezug.
     */
    public function test_changed_price_updates_the_same_row_instead_of_creating_a_new_one(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();
        AssetHolder::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id, 'mobile_phone' => '+491721234567',
        ]);

        $service = new VodafoneInvoiceImportService();

        $service->import($team->id, $this->makeInvoice([['msisdn' => '1721234567', 'net' => 21.00]]), 'test', false, $tenant->id);
        $first = AssetCostLine::withoutTenantScope()->where('source', VodafoneInvoiceImportService::SOURCE)->sole();

        // Zweiter Monat: 1,34 Verbrauch obendrauf.
        $service->import($team->id, $this->makeInvoice([['msisdn' => '1721234567', 'net' => 22.34]]), 'test', false, $tenant->id);
        $second = AssetCostLine::withoutTenantScope()->where('source', VodafoneInvoiceImportService::SOURCE)->sole();

        $this->assertSame($first->id, $second->id, 'Ein geänderter Betrag darf keine neue Zeile erzeugen.');
        $this->assertEqualsWithDelta(22.34, (float) $second->amount, 0.01);
    }

    /** Nummern, die auf der neuen Rechnung fehlen (gekündigt), dürfen nicht als Karteileiche weiterzählen. */
    public function test_numbers_missing_from_the_new_invoice_are_removed(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();
        $service = new VodafoneInvoiceImportService();

        $service->import($team->id, $this->makeInvoice([
            ['msisdn' => '1721111111', 'net' => 21.00],
            ['msisdn' => '1722222222', 'net' => 21.00],
        ]), 'test', false, $tenant->id);

        $this->assertSame(2, AssetCostLine::withoutTenantScope()->where('source', VodafoneInvoiceImportService::SOURCE)->count());

        $service->import($team->id, $this->makeInvoice([['msisdn' => '1721111111', 'net' => 21.00]]), 'test', false, $tenant->id);

        $this->assertSame(1, AssetCostLine::withoutTenantScope()->where('source', VodafoneInvoiceImportService::SOURCE)->count(),
            'Die gekündigte Nummer muss verschwinden.');
    }

    /**
     * Unsere Kostenstellen-Zuordnung gewinnt (Entscheidung 2026-08-10) — die Abweichung wird aber
     * gemeldet, sonst bliebe eine Fehlbuchung bei Vodafone unbemerkt.
     */
    public function test_our_cost_center_wins_and_the_difference_is_reported(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();

        $ours = AssetCostCenter::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id, 'code' => '1500', 'parent_id' => null, 'depth' => 0,
        ]);
        AssetCostCenter::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id, 'code' => '2599', 'parent_id' => null, 'depth' => 0,
        ]);

        AssetHolder::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id,
            'mobile_phone' => '+491721234567', 'cost_center_id' => $ours->id,
        ]);

        $result = (new VodafoneInvoiceImportService())->import(
            $team->id,
            $this->makeInvoice([['msisdn' => '1721234567', 'net' => 21.00, 'center' => '2599 - HKS Team Düsseldorf']]),
            'test', false, $tenant->id,
        );

        $line = AssetCostLine::withoutTenantScope()->where('source', VodafoneInvoiceImportService::SOURCE)->sole();
        $this->assertSame($ours->id, $line->cost_center_id, 'Gebucht wird auf unsere Kostenstelle.');
        $this->assertCount(1, $result['stats']['cost_center_diff'], 'Die Abweichung muss gemeldet werden.');
        $this->assertSame('2599', $result['stats']['cost_center_diff'][0]['vodafone']);
    }

    /** Eine Kostenstelle, die es bei uns nicht gibt, wird gemeldet — nicht heimlich angelegt. */
    public function test_unknown_cost_center_is_reported_not_created(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();

        $result = (new VodafoneInvoiceImportService())->import(
            $team->id,
            $this->makeInvoice([['msisdn' => '1721234567', 'net' => 21.00, 'center' => '8000 - KITA Kostenstelle']]),
            'test', false, $tenant->id,
        );

        $this->assertSame(['8000'], $result['stats']['unknown_centers']);
        $this->assertSame(0, AssetCostCenter::withoutTenantScope()->where('code', '8000')->count(),
            'Der Import darf den Stammdaten-Baum nicht erweitern.');
    }

    /** Sonst zählt Mobilfunk doppelt: der Excel-Prune fasst nur excel_import an, unserer nur vodafone_invoice. */
    public function test_import_removes_the_superseded_excel_mobilfunk_lines(): void
    {
        ['team' => $team, 'tenant' => $tenant, 'type' => $type] = $this->setUpTeam();

        $stale = AssetCostLine::factory()->imported('alter-excel-hash')->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id, 'cost_type_id' => $type->id, 'amount' => 99.00,
        ]);

        $result = (new VodafoneInvoiceImportService())->import(
            $team->id, $this->makeInvoice([['msisdn' => '1721234567', 'net' => 21.00]]), 'test', false, $tenant->id,
        );

        $this->assertSame(1, $result['removed_excel_rows']);
        $this->assertNull(AssetCostLine::withTrashed()->withoutTenantScope()->find($stale->id));
    }

    /** Die Prüfsumme des Exports (Posten + Rufnummern = Kopfbetrag) ist der eingebaute Selbsttest. */
    public function test_reconciliation_flag_detects_a_filtered_export(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();
        $service = new VodafoneInvoiceImportService();

        $ok = $service->import($team->id, $this->makeInvoice([['msisdn' => '1721234567', 'net' => 21.00]]), 'test', true, $tenant->id);
        $this->assertTrue($ok['reconciles']);

        // Kopfbetrag absichtlich zu hoch — so sieht ein gefilterter Export aus.
        $broken = $service->import($team->id, $this->makeInvoice([['msisdn' => '1721234567', 'net' => 21.00]], headerNet: 999.00), 'test', true, $tenant->id);
        $this->assertFalse($broken['reconciles']);
    }

    public function test_dry_run_writes_nothing(): void
    {
        ['team' => $team, 'tenant' => $tenant] = $this->setUpTeam();

        (new VodafoneInvoiceImportService())->import(
            $team->id, $this->makeInvoice([['msisdn' => '1721234567', 'net' => 21.00]]), 'test', true, $tenant->id,
        );

        $this->assertSame(0, AssetCostLine::withoutTenantScope()->where('source', VodafoneInvoiceImportService::SOURCE)->count());
    }

    // ---- Fixtures --------------------------------------------------------------------------

    /** @return array{team: Team, tenant: AssetTenant, type: AssetCostType} */
    private function setUpTeam(): array
    {
        $team   = Team::factory()->create();
        $tenant = AssetTenant::factory()->default()->create(['team_id' => $team->id]);
        $type   = AssetCostType::factory()->create([
            'team_id' => $team->id, 'tenant_id' => $tenant->id,
            'key' => VodafoneInvoiceImportService::TYPE_KEY, 'name' => 'Mobilfunk',
        ]);

        return compact('team', 'tenant', 'type');
    }

    /**
     * Baut einen Export im Format des Firmenkundenportals.
     *
     * @param  list<array{msisdn: string, net: float, center?: string}>  $numbers
     */
    private function makeInvoice(array $numbers, ?float $headerNet = null, array $accountItems = []): string
    {
        $net = $headerNet ?? (array_sum(array_column($numbers, 'net')) + array_sum(array_column($accountItems, 'net')));

        $rows = [
            ['A' => 'Rechnungsdaten'],
            [],
            ['A' => 'Typ', 'B' => 'Rechnungskonto', 'C' => 'Rechnungs-Nummer', 'D' => 'Rechnungs-Datum',
             'E' => 'Abrechnungszeitraum von', 'F' => 'Abrechnungszeitraum bis', 'G' => 'Brutto-Betrag Euro',
             'H' => 'Ust.-Satz %', 'I' => 'Ust.-Betrag Euro', 'J' => 'Netto-Betrag Euro',
             'K' => 'D2-Nummer', 'L' => 'Beschreibung', 'M' => 'Kostenstelle'],
            ['A' => 'Rechnung', 'B' => '86839593', 'C' => '122435770393', 'D' => '20.07.2026',
             'E' => '15.06.2026', 'F' => '14.07.2026', 'G' => round($net * 1.19, 4), 'H' => 19,
             'I' => round($net * 0.19, 4), 'J' => $net],
        ];

        foreach ($accountItems as $item) {
            $rows[] = ['A' => 'Rechnungsposten', 'B' => '86839593', 'C' => '122435770393', 'D' => '20.07.2026',
                       'E' => '15.06.2026', 'F' => '14.07.2026', 'G' => 0, 'H' => 19, 'I' => 0,
                       'J' => $item['net'], 'K' => $item['label'], 'L' => $item['label'], 'M' => $item['center'] ?? '1500 - Verwaltung'];
        }

        foreach ($numbers as $n) {
            $rows[] = ['A' => 'Rufnummer', 'B' => '86839593', 'C' => '122435770393', 'D' => '20.07.2026',
                       'E' => '15.06.2026', 'F' => '14.07.2026', 'G' => 0, 'H' => 19, 'I' => 0,
                       'J' => $n['net'], 'K' => $n['msisdn'], 'M' => $n['center'] ?? '1500 - Verwaltung'];
        }

        return $this->writeXlsx(['Rechnungsdaten' => $rows]);
    }

    /** @param array<string, list<array<string, string|int|float>>> $sheets */
    private function writeXlsx(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'am-vf-') . '.xlsx';
        $this->tempFiles[] = $path;

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->fail('Fixture-.xlsx konnte nicht erzeugt werden.');
        }

        $wbSheets = '';
        $rels     = '';
        $i        = 1;

        foreach ($sheets as $name => $rows) {
            $rid    = 'rId' . $i;
            $target = 'worksheets/sheet' . $i . '.xml';
            $wbSheets .= '<sheet name="' . htmlspecialchars($name, ENT_XML1) . '" sheetId="' . $i . '" r:id="' . $rid . '"/>';
            $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $target . '"/>';

            $xml = '<?xml version="1.0" encoding="UTF-8"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            $n = 0;
            foreach ($rows as $cells) {
                $n++;
                $xml .= '<row r="' . $n . '">';
                foreach ($cells as $col => $value) {
                    $ref = $col . $n;
                    $xml .= is_int($value) || is_float($value)
                        ? '<c r="' . $ref . '"><v>' . $value . '</v></c>'
                        : '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $value, ENT_XML1) . '</t></is></c>';
                }
                $xml .= '</row>';
            }
            $zip->addFromString('xl/' . $target, $xml . '</sheetData></worksheet>');
            $i++;
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
}
