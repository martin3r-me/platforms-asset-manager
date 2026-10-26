<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetTelephonySeat;
use Platform\AssetManager\Support\XlsxReader;

/**
 * Import der RingCentral-Benutzerliste (Admin-Portal → Benutzer → „Download User List", .xlsx) als
 * Snapshot der belegten Telefonie-Plätze (Dev-Issue #931).
 *
 * ## Dateiformat (verifiziert an einem echten Export, 14.10.2026)
 *
 * Blatt „Benutzer", Zeile 1 = Kopfzeile, eine Zeile je Benutzer (30 Spalten). Gelesen wird über die
 * **Spaltennamen**, nicht über die Buchstaben: Postfach-ID (stabile ID), Vorname, Nachname,
 * Nebenstelle, E-Mail, Durchwahlnummer (DW), Hersteller/Modell, Status. Das zweite Blatt
 * („Anrufwarteschlangen") ist für die Kosten ohne Belang.
 *
 * ## Abgleich mit den Asset-Trägern
 *
 * 1. E-Mail ↔ UPN oder E-Mail des Trägers (ohne Groß-/Kleinschreibung) — der Regelfall.
 * 2. Rückfall: normalisierter Name (Umlaute ausgeschrieben, nur Buchstaben) — aber **nur bei einem
 *    eindeutigen Treffer**. Zwei Träger gleichen Namens bekommen keinen geratenen Platz.
 * 3. Sonst ohne Träger. Das ist kein Fehler: Konferenztelefone, Lager- und Azubi-Apparate haben
 *    keine Person. Der Festnetz-Import bucht solche Plätze auf die Telefonie-Sammelstelle.
 *
 * Der Upload **ersetzt** den Snapshot des Tenants: ein Platz, der nicht mehr in der Liste steht, ist
 * beim Anbieter gelöscht und kostet nichts mehr.
 */
class TelephonySeatImportService
{
    /** Kopfzeilen-Namen der Pflichtspalten — zugleich das Erkennungsmerkmal der richtigen Datei. */
    private const REQUIRED = ['Postfach-ID', 'E-Mail', 'Status'];

    public function __construct(protected XlsxReader $reader = new XlsxReader()) {}

    /**
     * @return array<string, mixed> Kennzahlen + Listen für Vorschau und Protokoll
     */
    public function import(int $teamId, string $path, bool $dryRun = true, ?int $tenantId = null): array
    {
        $tenantId ??= TenantContext::defaultTenantId($teamId);
        TenantContext::forceTenant($tenantId);

        $users = $this->readUsers($path);

        [$byMail, $byName] = $this->holderIndex($teamId);

        // Bestehende Plätze einmal laden — der Abgleich unten fragt sonst je Platz einzeln nach (N+1).
        $existing = AssetTelephonySeat::where('team_id', $teamId)
            ->where('tenant_id', $tenantId)
            ->where('provider', AssetTelephonySeat::PROVIDER_RINGCENTRAL)
            ->get()
            ->keyBy('external_id');
        $previous = $existing->map(fn (AssetTelephonySeat $seat) => $seat->holder_id)->all();

        $stats = [
            'seats'        => count($users),
            'matched'      => 0,
            'matched_name' => 0,
            'unmatched'    => [],
            'status'       => [],
            'not_active'   => [],
            'added'        => [],
            'removed'      => 0,
        ];

        DB::beginTransaction();

        try {
            $now  = now();
            $kept = [];

            foreach ($users as $user) {
                $holder = $byMail[mb_strtolower($user['email'])] ?? null;
                $match  = $holder ? AssetTelephonySeat::MATCH_EXACT : null;

                if (! $holder && ($candidates = $byName[$this->normalizeName($user['name'])] ?? []) && count($candidates) === 1) {
                    $holder = $candidates[0];
                    $match  = AssetTelephonySeat::MATCH_NAME;
                }

                $stats['status'][$user['status'] ?: '—'] = ($stats['status'][$user['status'] ?: '—'] ?? 0) + 1;

                if ($holder) {
                    $stats['matched']++;
                    if ($match === AssetTelephonySeat::MATCH_NAME) {
                        $stats['matched_name']++;
                    }
                } else {
                    $stats['unmatched'][] = ['name' => $user['name'], 'email' => $user['email'], 'status' => $user['status'], 'device' => $user['device']];
                }

                if (mb_strtolower($user['status']) !== 'aktiviert') {
                    $stats['not_active'][] = ['name' => $user['name'], 'status' => $user['status'], 'holder' => $holder?->display_name];
                }

                if (! array_key_exists($user['external_id'], $previous)) {
                    $stats['added'][] = $user['name'];
                }

                $seat = $existing->get($user['external_id']) ?? new AssetTelephonySeat([
                    'team_id'     => $teamId,
                    'tenant_id'   => $tenantId,
                    'provider'    => AssetTelephonySeat::PROVIDER_RINGCENTRAL,
                    'external_id' => $user['external_id'],
                ]);
                $seat->fill([
                    'email'         => $user['email'] ?: null,
                    'display_name'  => $user['name'],
                    'extension'     => $user['extension'] ?: null,
                    'direct_number' => $user['direct_number'] ?: null,
                    'device'        => $user['device'] ?: null,
                    'status'        => $user['status'] ?: null,
                    'holder_id'     => $holder?->id,
                    'match'         => $match,
                    'imported_at'   => $now,
                    'raw_data'      => $user['raw'],
                ])->save();
                $existing->put($user['external_id'], $seat);

                $kept[] = $user['external_id'];
            }

            // Snapshot ersetzen: was nicht mehr in der Liste steht, ist beim Anbieter gelöscht.
            $stats['removed'] = AssetTelephonySeat::where('team_id', $teamId)
                ->where('provider', AssetTelephonySeat::PROVIDER_RINGCENTRAL)
                ->whereNotIn('external_id', $kept)
                ->delete();

            // Beim allerersten Upload ist „neu" jeder Platz — das ist keine Auskunft.
            if ($previous === []) {
                $stats['added'] = [];
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        ksort($stats['status']);

        return ['dry_run' => $dryRun, 'first_upload' => $previous === []] + $stats;
    }

    // ---- Lesen ------------------------------------------------------------------------------

    /**
     * @return list<array{external_id: string, name: string, email: string, extension: string, direct_number: string, device: string, status: string, raw: array<string, string>}>
     */
    protected function readUsers(string $path): array
    {
        try {
            $sheets = $this->reader->read($path);
        } catch (\Throwable) {
            throw new \RuntimeException('Die Datei ist keine lesbare .xlsx — bitte die RingCentral-Benutzerliste („Download User List") hochladen.');
        }

        $sheet = $this->reader->findSheet($sheets, ['Benutzer', 'Users']);

        if ($sheet === null || $sheet === []) {
            throw new \RuntimeException('Blatt „Benutzer" nicht gefunden — ist das die RingCentral-Benutzerliste („Download User List")?');
        }

        $rows    = array_values($sheet);
        $columns = [];
        foreach ($rows[0] as $letter => $title) {
            $columns[trim((string) $title)] = $letter;
        }

        $missing = array_values(array_diff(self::REQUIRED, array_keys($columns)));
        if ($missing !== []) {
            throw new \RuntimeException('In der Benutzerliste fehlen die Spalten: ' . implode(', ', $missing) . '.');
        }

        $get = fn (array $row, string $name) => isset($columns[$name]) ? trim((string) ($row[$columns[$name]] ?? '')) : '';

        $users = [];
        foreach (array_slice($rows, 1) as $row) {
            $id = $get($row, 'Postfach-ID');
            if ($id === '') {
                continue;
            }

            $raw = [];
            foreach ($columns as $name => $letter) {
                $value = trim((string) ($row[$letter] ?? ''));
                if ($value !== '') {
                    $raw[$name] = $value;
                }
            }

            $user = [
                'external_id'   => $id,
                'name'          => trim($get($row, 'Vorname') . ' ' . $get($row, 'Nachname')),
                'email'         => $get($row, 'E-Mail'),
                'extension'     => $get($row, 'Nebenstelle'),
                'direct_number' => $get($row, 'Durchwahlnummer (DW)'),
                'device'        => $get($row, 'Hersteller/Modell'),
                'status'        => $get($row, 'Status'),
                'raw'           => $raw,
            ];

            // Ein Benutzer mit zweiter Durchwahl steht zweimal in der Liste — gleiche Postfach-ID,
            // gleiche Nebenstelle, andere DW (Export 14.10.2026: vier Fälle). Das ist EIN Platz und
            // EINE Lizenz; die Durchwahlen werden zusammengeführt, statt den Platz doppelt zu zählen.
            if (isset($users[$id])) {
                $users[$id] = $this->mergeDuplicate($users[$id], $user);
                continue;
            }

            $users[$id] = $user;
        }

        if ($users === []) {
            throw new \RuntimeException('Die Benutzerliste enthält keine Benutzer.');
        }

        return array_values($users);
    }

    /** Zwei Zeilen desselben Benutzers zu einer verdichten: Durchwahlen sammeln, Lücken füllen. */
    protected function mergeDuplicate(array $first, array $second): array
    {
        $numbers = array_values(array_unique(array_filter([
            ...explode(', ', $first['direct_number']),
            $second['direct_number'],
        ])));

        $first['direct_number'] = implode(', ', $numbers);
        $first['device']      = $first['device'] !== '' ? $first['device'] : $second['device'];
        $first['raw']['Durchwahlnummern'] = $numbers;

        return $first;
    }

    // ---- Abgleich ---------------------------------------------------------------------------

    /**
     * @return array{0: array<string, AssetHolder>, 1: array<string, list<AssetHolder>>} E-Mail-Index, Namens-Index
     */
    protected function holderIndex(int $teamId): array
    {
        $byMail = [];
        $byName = [];

        AssetHolder::where('team_id', $teamId)
            ->get(['id', 'team_id', 'tenant_id', 'user_principal_name', 'email', 'display_name', 'cost_center_id'])
            ->each(function (AssetHolder $holder) use (&$byMail, &$byName) {
                foreach ([$holder->user_principal_name, $holder->email] as $mail) {
                    if ($mail) {
                        $byMail[mb_strtolower(trim($mail))] ??= $holder;
                    }
                }
                if ($holder->display_name) {
                    $byName[$this->normalizeName($holder->display_name)][] = $holder;
                }
            });

        return [$byMail, $byName];
    }

    /** „Cäcilia Begeré" und „CAECILIA BEGERE" → `caeciliabegere`. */
    protected function normalizeName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        return preg_replace('/[^a-z]/', '', $ascii !== false ? $ascii : $name) ?? '';
    }
}
