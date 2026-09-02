<?php

namespace Platform\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\HolderMergeService;
use Platform\AssetManager\Services\TenantContext;

/**
 * Räumt Träger-Dubletten auf, die durch gelöschte Entra-Konten entstanden sind (siehe
 * {@see HolderMergeService}).
 *
 * Entra benennt eine gelöschte UPN um; weil die UPN der Identitätsanker ist (ADR 0017), entstand
 * daraus jedes Mal ein zweiter Träger. Seit der Normalisierung im {@see \Platform\AssetManager\Services\HolderService}
 * passiert das nicht mehr — dieser Befehl ist für den Bestand, der schon doppelt dasteht.
 *
 * `--dry-run` zuerst. Der Lauf hängt Zuordnungen um und löscht Datensätze; was er anfassen würde,
 * sollte man einmal gesehen haben, bevor man es tut.
 */
class MergeDeletedHoldersCommand extends Command
{
    protected $signature = 'asset-manager:merge-deleted-holders
        {--team= : Team-ID (Pflicht)}
        {--tenant= : Nur diesen Tenant; ohne Angabe alle Tenants des Teams}
        {--dry-run : Nur anzeigen, nichts ändern}';

    protected $description = 'Führt Träger-Dubletten aus gelöschten Entra-Konten zusammen (ADR 0017)';

    public function handle(HolderMergeService $merger): int
    {
        $teamId = (int) $this->option('team');
        if (! $teamId) {
            $this->error('--team ist erforderlich.');

            return self::FAILURE;
        }

        $dryRun       = (bool) $this->option('dry-run');
        $tenantOption = $this->option('tenant');

        $tenants = AssetTenant::query()
            ->where('team_id', $teamId)
            ->when($tenantOption !== null, fn ($q) => $q->whereKey((int) $tenantOption))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($tenants->isEmpty()) {
            $this->error('Kein passender Tenant für dieses Team gefunden.');

            return self::FAILURE;
        }

        $totalMerged  = 0;
        $totalRenamed = 0;

        foreach ($tenants as $tenant) {
            TenantContext::forceTenant($tenant->id);

            $result = $merger->mergeDeletedUpnHolders($teamId, $tenant->id, $dryRun);

            $this->line('');
            $this->info("Tenant {$tenant->id} — {$tenant->name}");

            if ($result['results'] === []) {
                $this->line('  Keine Papierkorb-Dubletten gefunden.');
                continue;
            }

            $this->table(
                ['Dublette', 'UPN (Papierkorb)', 'Ursprüngliche UPN', 'Aktion', 'Hängt dran'],
                array_map(fn (array $row) => [
                    $row['duplicate_id'],
                    \Illuminate\Support\Str::limit((string) $row['duplicate_upn'], 44),
                    \Illuminate\Support\Str::limit((string) $row['original_upn'], 34),
                    $row['action'],
                    // Nur die Tabellen zeigen, an denen wirklich etwas hängt — sonst ist die Spalte
                    // eine Reihe Nullen und man übersieht den einen Fall, der zählt.
                    collect($row['references'])->filter()->map(fn ($n, $t) => "{$t}: {$n}")->implode(', ') ?: '—',
                ], $result['results']),
            );

            $totalMerged  += $result['merged'];
            $totalRenamed += $result['renamed'];
        }

        TenantContext::clearOverride();

        $this->line('');

        if ($dryRun) {
            $this->warn("Probelauf: {$totalMerged} würden zusammengeführt, {$totalRenamed} nur umbenannt. Nichts geändert.");
            $this->line('Ohne --dry-run erneut ausführen, um es anzuwenden.');

            return self::SUCCESS;
        }

        $this->info("Fertig: {$totalMerged} zusammengeführt, {$totalRenamed} umbenannt und stillgelegt.");

        return self::SUCCESS;
    }
}
