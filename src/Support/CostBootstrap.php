<?php

namespace Platform\AssetManager\Support;

/**
 * Stammdaten-Definitionen für die Kostenaufteilung.
 *
 * Zwei klar getrennte Sets:
 *  - NEUTRAL_COST_TYPES: firmen-agnostische Erst-Defaults für JEDES neue Team (kein Firmenname,
 *    kein Vendor, kein Buchungssystem). Wird von seedForTeam() angelegt.
 *  - COMPANIES / COST_CENTER_COMPANY / COST_TYPES / VENDORS: BROICH-spezifisches Set (abgeleitet aus
 *    Kostenaufteilung_IT.xlsx). NUR opt-in über seedBroichDefaults() bzw. den BROICH-Excel-Import —
 *    fließt nie automatisch in fremde Teams (Multi-Tenant).
 */
class CostBootstrap
{
    /**
     * Neutrale Erst-Defaults für jedes Team. Bewusst klein und ohne Firmenspezifika.
     * Gleiche Feld-Struktur wie COST_TYPES (vendor/system bleiben null).
     * hardware_afa + ms_lizenz sind drin, weil die beiden virtuellen Pivot-Quellen
     * (CostAggregationService) eine passende Kostenart-Zeile als Spalte brauchen — beides universell.
     */
    public const NEUTRAL_COST_TYPES = [
        ['key' => 'hardware_afa', 'name' => 'Hardware (AfA)', 'vendor' => null, 'system' => null, 'frequency' => 'monthly', 'per_employee' => true,  'aggregation_source' => 'hardware_afa', 'allow_negative' => false],
        ['key' => 'ms_lizenz',    'name' => 'MS Lizenz',      'vendor' => null, 'system' => null, 'frequency' => 'monthly', 'per_employee' => true,  'aggregation_source' => 'ms_license',   'allow_negative' => false],
        ['key' => 'software_abo', 'name' => 'Software-Abo',   'vendor' => null, 'system' => null, 'frequency' => 'monthly', 'per_employee' => false, 'aggregation_source' => 'cost_line',    'allow_negative' => false],
        ['key' => 'mobilfunk',    'name' => 'Mobilfunk',      'vendor' => null, 'system' => null, 'frequency' => 'monthly', 'per_employee' => true,  'aggregation_source' => 'cost_line',    'allow_negative' => false],
        ['key' => 'internet',     'name' => 'Internet',       'vendor' => null, 'system' => null, 'frequency' => 'monthly', 'per_employee' => false, 'aggregation_source' => 'cost_line',    'allow_negative' => false],
        ['key' => 'telefonie',    'name' => 'Telefonie',      'vendor' => null, 'system' => null, 'frequency' => 'monthly', 'per_employee' => false, 'aggregation_source' => 'cost_line',    'allow_negative' => false],
    ];

    /** Gesellschaften: slug => Anzeigename (Reihenfolge wie in der Excel-Pivot). */
    public const COMPANIES = [
        'gf-gl'       => 'BROICH - GF GL',
        'verwaltung'  => 'BROICH - VERWALTUNG',
        'rhein-ruhr'  => 'BROICH - RHEIN RUHR',
        'bonn'        => 'BROICH - BONN',
        'sports'      => 'BROICH - SPORTS',
        'kita'        => 'BROICH - KITA',
        'bhg-digital' => 'BHG.DIGITAL',
        'efp'         => 'EFP',
    ];

    /** Kostenstelle (Code als String) => Gesellschaft-Slug. */
    public const COST_CENTER_COMPANY = [
        '1000' => 'gf-gl',
        '1900' => 'gf-gl',

        '1500' => 'verwaltung',
        '1510' => 'verwaltung',
        '1520' => 'verwaltung',
        '1540' => 'verwaltung',
        '1600' => 'verwaltung',
        '1610' => 'verwaltung',
        '1800' => 'verwaltung',
        '4090' => 'verwaltung',

        '2599' => 'rhein-ruhr',
        '4000' => 'rhein-ruhr',
        '5000' => 'rhein-ruhr',
        '9900' => 'rhein-ruhr',
        '9950' => 'rhein-ruhr',

        '2627' => 'bonn',
        '2629' => 'bonn',
        '2630' => 'bonn',
        '2699' => 'bonn',

        '2890' => 'sports',

        '8000' => 'kita',
        '8010' => 'kita',
        '8020' => 'kita',
        '8025' => 'kita',
        '8500' => 'kita',
        '8090' => 'kita',

        '9920' => 'bhg-digital',

        'EFP'  => 'efp',
    ];

    /**
     * Kostenarten. Reihenfolge = Spaltenreihenfolge der Excel-Pivot (Sheet1/2) + Zusätze.
     *
     * Felder je Eintrag:
     *  key                 stabiler Schlüssel
     *  name                Anzeigename (= Excel-Spaltenkopf)
     *  vendor              Default-Kreditor (Name) oder null
     *  system              HGK|Moss|null  (Buchungssystem)
     *  frequency           monthly|quarterly|yearly|once  (Default für neue Positionen)
     *  per_employee        true = wird je Mitarbeiter erfasst
     *  aggregation_source  cost_line|hardware_afa|ms_license  (woher der Pivot-Wert kommt)
     *  allow_negative      true = negative Beträge erlaubt (Rabatt)
     */
    public const COST_TYPES = [
        ['key' => 'ms_lizenz',        'name' => 'MS Lizenz',            'vendor' => 'Vodafone',                       'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'ms_license', 'allow_negative' => false],
        ['key' => 'vf_lizenz_rc',     'name' => 'VF Lizenz RC',         'vendor' => 'VODAFONE HANDYZUBEHOER NL WEST', 'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'vf_lizenz_rc_rab', 'name' => 'VF Lizenz RC Rabatt',  'vendor' => 'VODAFONE HANDYZUBEHOER NL WEST', 'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => true],
        ['key' => 'lap_dock',         'name' => 'Lap+Dock',             'vendor' => 'CSI LEASING',                    'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'o365_backup',      'name' => 'O365 Backup Preise',   'vendor' => 'EXO3',                           'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'mobilfunk',        'name' => 'Mobilfunk',            'vendor' => 'Vodafone',                       'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'adobe_indesign',   'name' => 'Adobe InDesign',       'vendor' => 'InDesign',                       'system' => 'Moss', 'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'optisigns',        'name' => 'OptiSigns',            'vendor' => 'OPTISIGNS DIGITAL SIGN',         'system' => 'Moss', 'frequency' => 'monthly',   'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'firstinvision',    'name' => 'FirstInVision',        'vendor' => 'FIRSTINVISION SOFTWARE',         'system' => 'HGK',  'frequency' => 'yearly',    'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'mobileiron',       'name' => 'MobileIron',           'vendor' => 'VODAFONE HANDYZUBEHOER NL WEST', 'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'bpevent',          'name' => 'BPEvent',              'vendor' => 'BANKETTPROFI (A)',               'system' => 'HGK',  'frequency' => 'quarterly', 'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'druckerwartung',   'name' => 'Druckerwartung',       'vendor' => 'PK OFFICE',                      'system' => 'HGK',  'frequency' => 'quarterly', 'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'druckerleasing',   'name' => 'Druckerleasing',       'vendor' => 'GRENKE',                         'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'internet',         'name' => 'Internet',             'vendor' => null,                             'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'hgk',              'name' => 'HGK',                  'vendor' => 'HGK',                            'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'necta',            'name' => 'necta',                'vendor' => 'NECTA',                          'system' => 'HGK',  'frequency' => 'quarterly', 'per_employee' => false, 'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'chatgpt',          'name' => 'ChatGPT',              'vendor' => 'OPENAI',                         'system' => 'Moss', 'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'canva',            'name' => 'Canva',                'vendor' => 'Canva',                          'system' => 'Moss', 'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],

        // Zusätze, die in der Excel als Spalten in „Übersicht" auftauchen bzw. das Modul ergänzt
        ['key' => 'versicherung',     'name' => 'Versicherung',         'vendor' => null,                             'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'brevo',            'name' => 'Brevo',                'vendor' => null,                             'system' => 'Moss', 'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'cost_line',  'allow_negative' => false],
        ['key' => 'hardware_afa',     'name' => 'Hardware (AfA)',       'vendor' => null,                             'system' => 'HGK',  'frequency' => 'monthly',   'per_employee' => true,  'aggregation_source' => 'hardware_afa', 'allow_negative' => false],
    ];

    /** Kreditoren (Kreditor-Namen, wie sie in der Excel stehen). */
    public const VENDORS = [
        'Vodafone',
        'VODAFONE HANDYZUBEHOER NL WEST',
        'CSI LEASING',
        'EXO3',
        'InDesign',
        'OPTISIGNS DIGITAL SIGN',
        'FIRSTINVISION SOFTWARE',
        'BANKETTPROFI (A)',
        'PK OFFICE',
        'GRENKE',
        'HGK',
        'NECTA',
        'OPENAI',
        'Canva',
        'Telekom',
    ];

    /**
     * Gibt den Gesellschaft-Slug für eine Kostenstelle zurück (oder null wenn unbekannt).
     */
    public static function companyForCostCenter(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }
        return self::COST_CENTER_COMPANY[trim($code)] ?? null;
    }
}
