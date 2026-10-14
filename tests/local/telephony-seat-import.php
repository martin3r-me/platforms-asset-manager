<?php

/**
 * RingCentral-Benutzerliste → Telefonie-Seats ({@see \Platform\AssetManager\Services\TelephonySeatImportService}).
 *
 * Geprüft wird: Lesen über Spaltennamen (Reihenfolge egal, Zusatzspalten ignoriert), Abgleich per
 * E-Mail/UPN, Namens-Rückfall nur bei eindeutigem Treffer, Plätze ohne Träger, Status-Auswertung,
 * Snapshot-Ersatz (entfallene Plätze weg, neue gemeldet), Probelauf schreibt nichts, falsche Datei.
 *
 * Aufruf: php tests/local/telephony-seat-import.php   (siehe tests/local/bootstrap.php)
 */

require __DIR__ . '/bootstrap.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Platform\AssetManager\Models\AssetTelephonySeat;
use Platform\AssetManager\Services\TelephonySeatImportService;
use Platform\AssetManager\Services\TenantContext;

Schema::create('asset_tenants', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->string('name');
    $t->boolean('is_default')->default(false);
    $t->timestamps();
});

Schema::create('asset_holders', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id')->nullable();
    $t->string('user_principal_name')->nullable();
    $t->string('email')->nullable();
    $t->string('display_name')->nullable();
    $t->unsignedBigInteger('cost_center_id')->nullable();
    $t->timestamps();
});

Schema::create('asset_telephony_seats', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('team_id');
    $t->unsignedBigInteger('tenant_id');
    $t->string('provider', 32)->default('ringcentral');
    $t->string('external_id', 64);
    $t->string('email')->nullable();
    $t->string('display_name')->nullable();
    $t->string('extension', 16)->nullable();
    $t->string('direct_number', 96)->nullable();
    $t->string('device')->nullable();
    $t->string('status', 32)->nullable();
    $t->unsignedBigInteger('holder_id')->nullable();
    $t->string('match', 16)->nullable();
    $t->timestamp('imported_at')->nullable();
    $t->text('raw_data')->nullable();
    $t->timestamps();
    $t->unique(['tenant_id', 'provider', 'external_id']);
});

/** Minimal-.xlsx mit Inline-Strings (Muster tests/Feature/VodafoneInvoiceImportTest::writeXlsx). */
function writeXlsx(array $sheets): string
{
    $path = tempnam(sys_get_temp_dir(), 'am-rc-') . '.xlsx';
    $zip  = new \ZipArchive();
    $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    $wbSheets = $rels = '';
    $i = 1;
    foreach ($sheets as $name => $rows) {
        $target = 'worksheets/sheet' . $i . '.xml';
        $wbSheets .= '<sheet name="' . htmlspecialchars($name, ENT_XML1) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $target . '"/>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $n => $cells) {
            $xml .= '<row r="' . ($n + 1) . '">';
            foreach (array_values($cells) as $c => $value) {
                $ref = chr(65 + $c) . ($n + 1);
                $xml .= is_int($value)
                    ? '<c r="' . $ref . '"><v>' . $value . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $value, ENT_XML1) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $zip->addFromString('xl/' . $target, $xml . '</sheetData></worksheet>');
        $i++;
    }
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $zip->close();

    return $path;
}

// Kopf in der Reihenfolge des echten Exports (Auszug), plus eine Spalte, die der Import nicht kennt.
const RC_HEAD = ['Postfach-ID', 'Vorname', 'Nachname', 'Nebenstelle', 'E-Mail', 'Mobiltelefon', 'Durchwahlnummer (DW)', 'Hersteller/Modell', 'Status', 'MS Teams-Verbindungsstatus'];

function rcUser(int $id, string $first, string $last, string $mail, string $status = 'Aktiviert', string $device = 'Snom 715'): array
{
    return [$id, $first, $last, (string) (100 + $id % 100), $mail, '', '0211 602031' . ($id % 100), $device, $status, ''];
}

const TEAM   = 1;
const TENANT = 1;

DB::table('asset_tenants')->insert(['id' => TENANT, 'team_id' => TEAM, 'name' => 'Test', 'is_default' => true]);
TenantContext::forceTenant(TENANT);

$h = fn (array $v) => DB::table('asset_holders')->insertGetId($v + ['team_id' => TEAM, 'tenant_id' => TENANT]);
$alara   = $h(['user_principal_name' => 'A.ASAN@BROICHCATERING.COM', 'display_name' => 'ALARA ASAN']);
$cacilia = $h(['user_principal_name' => 'c.begere@firma.com', 'display_name' => 'CÄCILIA BEGERÉ']);
$twinA   = $h(['user_principal_name' => 'm.mueller@firma.com', 'display_name' => 'MIRIAM MÜLLER']);
$twinB   = $h(['user_principal_name' => 'm.mueller2@firma.com', 'display_name' => 'MIRIAM MUELLER']);

$service = new TelephonySeatImportService();

// ---- Erster Upload --------------------------------------------------------------------------

$path = writeXlsx([
    'Benutzer' => [
        RC_HEAD,
        rcUser(2195505071, 'Alara', 'Asan', 'a.asan@BROICHCATERING.com', 'Aktiviert', 'Vodafone Business UC with RingCentral app'),
        rcUser(2195505072, 'Cäcilia', 'Begeré', 'cbegere@anders.com', 'Nicht aktiviert'),
        rcUser(2195505073, 'Miriam', 'Müller', 'mm@anders.com'),
        rcUser(2195505074, 'Spinne', 'Telefonkonferenz', 'spinne@firma.com', 'Aktiviert', 'Polycom IP 6000 Conference Phone'),
    ],
    'Anrufwarteschlangen' => [['Postfach-ID', 'Zugewiesene Anrufwarteschlangen'], [2195505071, 'IT (Ext. 190)']],
]);

$dry = $service->import(TEAM, $path, true, TENANT);
check('Probelauf: 4 Plätze gelesen', 4, $dry['seats']);
check('Probelauf schreibt nichts', 0, AssetTelephonySeat::count());

$r = $service->import(TEAM, $path, false, TENANT);
check('Erster Upload: 4 Plätze', 4, AssetTelephonySeat::count());
check('Erster Upload meldet keine „neuen" Plätze', [], $r['added']);
check('Zugeordnet: E-Mail (Groß/Klein egal) + eindeutiger Name', 2, $r['matched']);
check('davon über den Namen', 1, $r['matched_name']);
check('E-Mail-Treffer', $alara, AssetTelephonySeat::where('external_id', '2195505071')->value('holder_id'));
check('Namens-Treffer mit Umlaut/Akzent', $cacilia, AssetTelephonySeat::where('external_id', '2195505072')->value('holder_id'));
check('Namens-Treffer markiert', AssetTelephonySeat::MATCH_NAME, AssetTelephonySeat::where('external_id', '2195505072')->value('match'));
check('Zwei gleichnamige Träger → kein geratener Platz', null, AssetTelephonySeat::where('external_id', '2195505073')->value('holder_id'));
check('Ohne Träger gemeldet (Doppelname + Konferenztelefon)', 2, count($r['unmatched']));
check('Status gezählt', ['Aktiviert' => 3, 'Nicht aktiviert' => 1], $r['status']);
check('Nicht aktivierte Plätze aufgelistet', 1, count($r['not_active']));
$seat = AssetTelephonySeat::where('external_id', '2195505071')->first();
check('Durchwahl übernommen', '0211 60203171', $seat->direct_number);
check('Endgerät übernommen', 'Vodafone Business UC with RingCentral app', $seat->device);
check('Unbekannte Spalte stört nicht', true, $seat->raw_data['Postfach-ID'] !== null);

// ---- Zweiter Upload: Snapshot wird ersetzt -------------------------------------------------

$path2 = writeXlsx([
    'Benutzer' => [
        RC_HEAD,
        rcUser(2195505071, 'Alara', 'Asan', 'a.asan@broichcatering.com'),
        rcUser(2195505074, 'Spinne', 'Telefonkonferenz', 'spinne@firma.com'),
        rcUser(2195505080, 'Neu', 'Kollege', 'neu@firma.com'),
        // Zweite Durchwahl desselben Benutzers: eigene Zeile im Export, gleiche Postfach-ID, kein Endgerät.
        [2195505071, 'Alara', 'Asan', '171', 'a.asan@broichcatering.com', '', '0211 60203199', '', 'Aktiviert', ''],
    ],
]);
$r = $service->import(TEAM, $path2, false, TENANT);
check('Zweite Durchwahl = derselbe Platz (3, nicht 4)', 3, $r['seats']);
check('Beide Durchwahlen erhalten', '0211 60203171, 0211 60203199', AssetTelephonySeat::where('external_id', '2195505071')->value('direct_number'));
check('Endgerät aus der vollständigen Zeile', 'Snom 715', AssetTelephonySeat::where('external_id', '2195505071')->value('device'));
check('Entfallene Plätze entfernt', 2, $r['removed']);
check('Neuer Platz gemeldet', ['Neu Kollege'], $r['added']);
check('Snapshot hat jetzt 3 Plätze', 3, AssetTelephonySeat::count());
check('Platz behält seine Zeile (gleiche Postfach-ID)', $seat->id, AssetTelephonySeat::where('external_id', '2195505071')->value('id'));

// ---- Falsche Datei --------------------------------------------------------------------------

$bad = writeXlsx(['Tabelle1' => [['A', 'B'], ['1', '2']]]);
try {
    $service->import(TEAM, $bad, true, TENANT);
    check('Falsche Datei wird abgewiesen', 'Exception', 'keine');
} catch (\RuntimeException $e) {
    check('Falsche Datei: verständliche Meldung', true, str_contains($e->getMessage(), 'Benutzer'));
}

foreach ([$path, $path2, $bad] as $p) {
    @unlink($p);
}

check_summary();
