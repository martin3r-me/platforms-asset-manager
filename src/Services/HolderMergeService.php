<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetHolder;

/**
 * Räumt Träger-Dubletten auf, die durch das Löschen eines Entra-Kontos entstanden sind.
 *
 * Entra benennt eine gelöschte UPN um — objectId ohne Bindestriche plus ursprüngliche Adresse
 * (siehe {@see HolderService::DELETED_UPN_PREFIX}). Weil die UPN der Identitätsanker ist
 * (ADR 0017), war das für den Asset Manager eine andere Person: jeder Pfad, der die umbenannte
 * Adresse sah, legte einen **zweiten** Träger an. Das Löschen im Verzeichnis hat den Bestand hier
 * also nicht bereinigt, sondern verdoppelt.
 *
 * Seit der Normalisierung in {@see HolderService::findOrCreateByUpn()} entstehen keine neuen
 * Dubletten mehr. Dieser Service ist für die, die schon da sind.
 *
 * **Zusammenführen statt löschen.** An einem Träger hängen Übergabeprotokolle, Zuordnungsverläufe
 * und Kostenzeilen vergangener Monate. Die Dublette wird erst entleert — alles wandert auf den
 * bestehenden Träger —, und nur der dann leere Datensatz verschwindet. Existiert kein Gegenstück,
 * wird gar nichts gelöscht: dann bekommt die Dublette einfach ihre richtige UPN zurück.
 */
class HolderMergeService
{
    /**
     * Tabellen und die Spalten, die auf einen Träger zeigen — je Tabelle als **Kandidatenliste**,
     * erster Treffer gewinnt.
     *
     * Warum nicht ein fester Name: die Umbenennung „Mitarbeiter → Asset-Träger" (ADR 0017) hat
     * `employee_id` in `asset_assignments` und `asset_handovers` zu `holder_id` gemacht, in
     * `asset_cost_lines` aber `assignee_id` gelassen — „assignee" war schon neutral. Ein fest
     * verdrahteter Name traf deshalb je nach Tabelle und Migrationsstand ins Leere; genau daran ist
     * der erste scharfe Lauf gescheitert. Aufgelöst wird zur Laufzeit gegen das echte Schema.
     *
     * Kommt eine Referenz dazu, gehört sie hierher — sonst bliebe sie beim Zusammenführen zurück.
     *
     * @var array<string, list<string>>
     */
    protected const REFERENCES = [
        'asset_items'       => ['assignee_id'],
        'asset_cost_lines'  => ['assignee_id'],
        'asset_assignments' => ['holder_id', 'employee_id'],
        'asset_handovers'   => ['holder_id', 'employee_id'],
        // Verlauf (#897): die Spur der Dublette gehört nach dem Zusammenführen zum Ziel.
        'asset_holder_events' => ['holder_id'],
        // Telefonie-Platz (#931) und Preis-Historie (ADR 0029) — fehlten bis #986, ein zusammengeführter
        // Träger verlor sonst seinen RingCentral-Platz bzw. den Verlauf seiner Rechnungsposten.
        'asset_telephony_seats'   => ['holder_id'],
        'asset_cost_line_history' => ['assignee_id'],
    ];

    /**
     * Tabellen, die einen Träger **über die UPN** statt über einen Fremdschlüssel finden. Sie fehlen
     * in {@see REFERENCES} und wären beim Zusammenführen sonst lautlos verlorengegangen: das Gerät
     * eines gelöschten Kontos zeigte nach dem Löschen der Dublette auf eine UPN, die es nicht mehr
     * gibt — und verschwände aus jeder Auswertung, ohne dass etwas fehlschlägt.
     *
     * @var array<string, string>
     */
    protected const UPN_REFERENCES = [
        'asset_devices'       => 'user_principal_name',
        'asset_user_licenses' => 'user_principal_name',
    ];

    /** @var array<string, string|null> Aufgelöste Spaltennamen je Tabelle, request-lokal. */
    protected array $columnCache = [];

    /** Felder, die von der Dublette übernommen werden, wenn das Ziel dort nichts stehen hat. */
    protected const FILLABLE_GAPS = [
        'cost_center', 'cost_center_id', 'department', 'job_title', 'display_name', 'email',
    ];

    /**
     * Alle Träger eines Tenants, deren UPN das Papierkorb-Präfix trägt.
     *
     * Gefiltert wird in PHP: das Muster ist ein regulärer Ausdruck, und `LIKE` könnte 32 Hex-Zeichen
     * nicht von 32 beliebigen unterscheiden.
     */
    public function findDeletedUpnHolders(int $teamId, int $tenantId): Collection
    {
        return AssetHolder::withoutTenantScope()
            ->forTenant($tenantId)
            ->where('team_id', $teamId)
            ->get()
            ->filter(fn (AssetHolder $h) => HolderService::isDeletedUpn((string) $h->user_principal_name))
            ->values();
    }

    /**
     * Papierkorb-Dubletten eines Tenants zusammenführen.
     *
     * @return array{
     *     dry_run: bool, merged: int, renamed: int, skipped: int,
     *     results: list<array<string, mixed>>
     * }
     */
    public function mergeDeletedUpnHolders(int $teamId, int $tenantId, bool $dryRun = false): array
    {
        $results = [];
        $merged  = 0;
        $renamed = 0;
        $skipped = 0;

        foreach ($this->findDeletedUpnHolders($teamId, $tenantId) as $duplicate) {
            $originalUpn = HolderService::normalizeUpn((string) $duplicate->user_principal_name);

            $target = AssetHolder::withoutTenantScope()
                ->forTenant($tenantId)
                ->where('team_id', $teamId)
                ->whereRaw('LOWER(user_principal_name) = ?', [mb_strtolower($originalUpn)])
                ->where('id', '!=', $duplicate->id)
                ->first();

            $row = [
                'duplicate_id'  => $duplicate->id,
                'duplicate_upn' => $duplicate->user_principal_name,
                'original_upn'  => $originalUpn,
                'references'    => $this->countReferences($duplicate->id, (string) $duplicate->user_principal_name),
            ];

            if ($target === null) {
                // Kein Gegenstück: nichts zusammenzuführen, aber die Identität ist falsch. Die UPN
                // zurückzusetzen ist verlustfrei — und macht den Träger für den nächsten Sync wieder
                // auffindbar, falls das Konto wiederhergestellt wird.
                $row['action'] = $dryRun ? 'would_rename' : 'renamed';
                $renamed++;

                if (! $dryRun) {
                    DB::transaction(function () use ($duplicate, $originalUpn, $teamId): void {
                        // Geräte und Lizenzzuweisungen finden ihren Träger über die UPN. Ohne diesen
                        // Schritt zeigten sie nach dem Umbenennen auf eine Adresse, die es nicht mehr
                        // gibt — sie fielen aus jeder Auswertung, ohne dass etwas fehlschlägt.
                        $this->moveUpnReferences(
                            (string) $duplicate->user_principal_name,
                            $originalUpn,
                            $teamId,
                        );

                        $duplicate->user_principal_name = $originalUpn;
                        if ($duplicate->email && HolderService::isDeletedUpn((string) $duplicate->email)) {
                            $duplicate->email = HolderService::normalizeUpn((string) $duplicate->email);
                        }
                        // Das Konto ist im Verzeichnis gelöscht — aktiv ist hier nichts mehr.
                        $duplicate->is_active = false;
                        $duplicate->save();
                    });
                }

                $results[] = $row;
                continue;
            }

            $row['target_id'] = $target->id;
            $row['action']    = $dryRun ? 'would_merge' : 'merged';
            $merged++;

            if (! $dryRun) {
                $this->mergeInto($duplicate, $target);
            }

            $results[] = $row;
        }

        return [
            'dry_run' => $dryRun,
            'merged'  => $merged,
            'renamed' => $renamed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    /**
     * UPN-Umbenennung im Verzeichnis nachziehen (#986) — vor dem UPN-Abgleich aufrufen.
     *
     * Die UPN ist unser Anker (ADR 0017), eine Umbenennung im AD (Tippfehler, Namensänderung) war deshalb
     * eine neue Person: der Geräte- oder Lizenz-Sync legte für die neue UPN einen zweiten Träger an, der
     * Abgleich legte den alten still, und beide liefen getrennt weiter (O.PARADZE → O.PERADZE). Die
     * Entra-Objekt-ID (`graph_id`) bleibt bei einer Umbenennung gleich — über sie wird der bestehende Träger
     * gefunden und **umbenannt**; Geräte und Lizenzen ziehen über die UPN mit.
     *
     * Dubletten mit der neuen UPN werden hineingeführt, wenn sie keine oder dieselbe Entra-ID tragen — auch
     * ein bereits gespaltener Fall (zwei Träger mit derselben ID) heilt so beim nächsten Abgleich. Gehört die
     * neue UPN einem Träger mit **anderer** Entra-ID (UPN eines gelöschten Kontos neu vergeben), wird nichts
     * angefasst: das sind zwei Personen.
     *
     * @return array{renamed: bool, merged: int}
     */
    public function followRename(int $teamId, int $tenantId, string $graphId, string $upn, ?string $displayName = null): array
    {
        $none  = ['renamed' => false, 'merged' => 0];
        $upn   = HolderService::normalizeUpn($upn);
        $key   = mb_strtolower($upn);
        if ($graphId === '' || $upn === '') {
            return $none;
        }

        $candidates = AssetHolder::withoutTenantScope()
            ->forTenant($tenantId)
            ->where('team_id', $teamId)
            ->where(fn ($q) => $q->where('graph_id', $graphId)->orWhereRaw('LOWER(user_principal_name) = ?', [$key]))
            ->orderBy('id')
            ->get();

        // Ältester Träger dieser Entra-ID ist das Ziel — er trägt Verlauf und gepflegte Felder am längsten.
        $target = $candidates->firstWhere('graph_id', $graphId);
        if ($target === null) {
            return $none; // neue Person oder nur ein UPN-Treffer: der normale Weg genügt
        }

        if ($candidates->contains(fn (AssetHolder $h) => $h->graph_id !== null && $h->graph_id !== '' && $h->graph_id !== $graphId)) {
            \Illuminate\Support\Facades\Log::warning('AssetManager: UPN gehört einem anderen Entra-Konto — Umbenennung nicht nachgezogen', [
                'team_id' => $teamId, 'tenant_id' => $tenantId, 'holder_id' => $target->id,
            ]);

            return $none;
        }

        $duplicates = $candidates->reject(fn (AssetHolder $h) => $h->id === $target->id);
        $renaming   = mb_strtolower((string) $target->user_principal_name) !== $key;

        if ($duplicates->isEmpty() && ! $renaming) {
            return $none;
        }

        DB::transaction(function () use ($duplicates, $target, $upn, $teamId, $displayName): void {
            foreach ($duplicates as $duplicate) {
                $this->mergeInto($duplicate, $target);
                $target->refresh();
            }

            $old = (string) $target->user_principal_name;
            if (mb_strtolower($old) === mb_strtolower($upn)) {
                return;
            }

            // E-Mail mitziehen, wenn sie nur die alte UPN war — eine gepflegte Adresse bleibt.
            if (blank($target->email) || mb_strtolower((string) $target->email) === mb_strtolower($old)) {
                $target->email = $upn;
            }
            $target->user_principal_name = $upn;
            // Mit der UPN ist meist auch der Name korrigiert (Tippfehler, Namensänderung). Sonst übernimmt der
            // Abgleich Namen nur in leere Felder, damit gepflegte Namen bleiben — hier ist der alte Name falsch.
            if (filled($displayName)) {
                $target->display_name = trim((string) $displayName);
            }
            $target->save();

            $this->moveUpnReferences($old, $upn, $teamId);
        });

        \Illuminate\Support\Facades\Log::info('AssetManager: UPN-Umbenennung nachgezogen', [
            'team_id' => $teamId, 'tenant_id' => $tenantId, 'holder_id' => $target->id,
            'renamed' => $renaming, 'merged' => $duplicates->count(),
        ]);

        return ['renamed' => $renaming, 'merged' => $duplicates->count()];
    }

    /**
     * Dublette in den Ziel-Träger überführen und danach entfernen.
     *
     * Alles in einer Transaktion: ein Abbruch zwischen Umhängen und Löschen hinterließe entweder
     * verwaiste Zuordnungen oder eine Dublette, die schon leer ist und trotzdem mitgezählt wird.
     */
    public function mergeInto(AssetHolder $duplicate, AssetHolder $target): void
    {
        DB::transaction(function () use ($duplicate, $target): void {
            foreach (self::REFERENCES as $table => $candidates) {
                $column = $this->resolveColumn($table, $candidates);
                if ($column === null) {
                    continue;
                }

                // Bewusst als Bulk-Update und nicht über Model-Instanzen: es wird ausschließlich ein
                // Fremdschlüssel umgehängt, kein fachliches Feld. Kein `saving()`-Hook der beteiligten
                // Modelle hängt daran, und bei vielen Zeilen wäre der Model-Weg reine Last.
                DB::table($table)->where($column, $duplicate->id)->update([$column => $target->id]);
            }

            $this->moveUpnReferences(
                (string) $duplicate->user_principal_name,
                (string) $target->user_principal_name,
                (int) $target->team_id,
            );

            // Lücken im Ziel aus der Dublette füllen — sie trägt oft die Kostenstelle aus dem Import,
            // die dem Verzeichnis-Datensatz fehlt. Vorhandene Werte im Ziel bleiben unangetastet.
            $filled = false;
            foreach (self::FILLABLE_GAPS as $field) {
                if (blank($target->{$field}) && filled($duplicate->{$field})) {
                    $value = in_array($field, ['email'], true)
                        ? HolderService::normalizeUpn((string) $duplicate->{$field})
                        : $duplicate->{$field};

                    $target->{$field} = $value;
                    $filled = true;
                }
            }

            if ($filled) {
                $target->save();
            }

            $duplicate->delete();
        });
    }

    /**
     * Geräte und Lizenzzuweisungen von der Papierkorb-UPN auf die richtige umschreiben.
     *
     * Zwei verschiedene Fälle, weil die Indizes verschieden sind:
     *
     * - **Geräte** tragen keinen Unique auf der UPN (eine Person kann mehrere haben) — ein reines
     *   Umschreiben genügt.
     * - **Lizenzzuweisungen** sind über `(team_id, user_principal_name, sku_id)` eindeutig. Trägt
     *   das Ziel dieselbe Lizenz bereits, liefe das Umschreiben in den Index. Solche Zeilen sind der
     *   veraltete Doppelgänger und werden vorher entfernt; alles Übrige wandert mit.
     */
    public function moveUpnReferences(string $trashUpn, string $realUpn, int $teamId): void
    {
        if ($trashUpn === '' || $realUpn === '' || $trashUpn === $realUpn) {
            return;
        }

        if (DB::getSchemaBuilder()->hasTable('asset_devices')) {
            DB::table('asset_devices')
                ->where('user_principal_name', $trashUpn)
                ->update(['user_principal_name' => $realUpn]);
        }

        if (! DB::getSchemaBuilder()->hasTable('asset_user_licenses')) {
            return;
        }

        $targetSkus = DB::table('asset_user_licenses')
            ->where('team_id', $teamId)
            ->where('user_principal_name', $realUpn)
            ->pluck('sku_id')
            ->all();

        if ($targetSkus !== []) {
            DB::table('asset_user_licenses')
                ->where('team_id', $teamId)
                ->where('user_principal_name', $trashUpn)
                ->whereIn('sku_id', $targetSkus)
                ->delete();
        }

        DB::table('asset_user_licenses')
            ->where('user_principal_name', $trashUpn)
            ->update(['user_principal_name' => $realUpn]);
    }

    /**
     * Erste vorhandene Spalte aus der Kandidatenliste — oder null, wenn die Tabelle fehlt oder keine
     * davon trägt.
     *
     * Das Ergebnis wird gemerkt: `hasColumn()` fragt je Aufruf das Informationsschema ab, und der
     * Merge läuft je Dublette einmal durch alle Tabellen.
     *
     * @param  list<string>  $candidates
     */
    protected function resolveColumn(string $table, array $candidates): ?string
    {
        if (array_key_exists($table, $this->columnCache)) {
            return $this->columnCache[$table];
        }

        $schema = DB::getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            return $this->columnCache[$table] = null;
        }

        foreach ($candidates as $candidate) {
            if ($schema->hasColumn($table, $candidate)) {
                return $this->columnCache[$table] = $candidate;
            }
        }

        return $this->columnCache[$table] = null;
    }

    /**
     * Wie viele Datensätze hängen an diesem Träger? Für die Vorschau — inklusive der UPN-gebundenen,
     * denn genau die übersieht man sonst.
     */
    public function countReferences(int $holderId, ?string $upn = null): array
    {
        $counts = [];

        foreach (self::REFERENCES as $table => $candidates) {
            $column = $this->resolveColumn($table, $candidates);
            if ($column === null) {
                continue;
            }

            $counts[$table] = DB::table($table)->where($column, $holderId)->count();
        }

        if ($upn === null || $upn === '') {
            return $counts;
        }

        foreach (self::UPN_REFERENCES as $table => $column) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $counts[$table] = DB::table($table)->where($column, $upn)->count();
        }

        return $counts;
    }
}
