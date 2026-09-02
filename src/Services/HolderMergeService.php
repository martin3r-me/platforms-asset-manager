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
     * Tabellen und Spalten, die auf einen Träger zeigen. Vollständig aus den Migrationen abgeleitet;
     * kommt eine Referenz dazu, gehört sie hierher — sonst bliebe sie beim Zusammenführen zurück und
     * liefe beim Löschen der Dublette entweder auf `null` oder ins Leere.
     *
     * @var array<string, string>
     */
    protected const REFERENCES = [
        'asset_items'       => 'assignee_id',
        'asset_cost_lines'  => 'assignee_id',
        'asset_assignments' => 'employee_id',
        'asset_handovers'   => 'employee_id',
    ];

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
                'references'    => $this->countReferences($duplicate->id),
            ];

            if ($target === null) {
                // Kein Gegenstück: nichts zusammenzuführen, aber die Identität ist falsch. Die UPN
                // zurückzusetzen ist verlustfrei — und macht den Träger für den nächsten Sync wieder
                // auffindbar, falls das Konto wiederhergestellt wird.
                $row['action'] = $dryRun ? 'would_rename' : 'renamed';
                $renamed++;

                if (! $dryRun) {
                    $duplicate->user_principal_name = $originalUpn;
                    if ($duplicate->email && HolderService::isDeletedUpn((string) $duplicate->email)) {
                        $duplicate->email = HolderService::normalizeUpn((string) $duplicate->email);
                    }
                    // Das Konto ist im Verzeichnis gelöscht — aktiv ist hier nichts mehr.
                    $duplicate->is_active = false;
                    $duplicate->save();
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
     * Dublette in den Ziel-Träger überführen und danach entfernen.
     *
     * Alles in einer Transaktion: ein Abbruch zwischen Umhängen und Löschen hinterließe entweder
     * verwaiste Zuordnungen oder eine Dublette, die schon leer ist und trotzdem mitgezählt wird.
     */
    public function mergeInto(AssetHolder $duplicate, AssetHolder $target): void
    {
        DB::transaction(function () use ($duplicate, $target): void {
            foreach (self::REFERENCES as $table => $column) {
                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                // Bewusst als Bulk-Update und nicht über Model-Instanzen: es wird ausschließlich ein
                // Fremdschlüssel umgehängt, kein fachliches Feld. Kein `saving()`-Hook der beteiligten
                // Modelle hängt daran, und bei vielen Zeilen wäre der Model-Weg reine Last.
                DB::table($table)->where($column, $duplicate->id)->update([$column => $target->id]);
            }

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

    /** Wie viele Datensätze hängen an diesem Träger? Für die Vorschau. */
    public function countReferences(int $holderId): array
    {
        $counts = [];

        foreach (self::REFERENCES as $table => $column) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $counts[$table] = DB::table($table)->where($column, $holderId)->count();
        }

        return $counts;
    }
}
