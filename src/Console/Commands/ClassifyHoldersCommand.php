<?php

namespace Platform\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\HolderClassifier;
use Platform\AssetManager\Services\TenantContext;

/**
 * Klassifiziert den **Bestand** der Asset-Träger neu (siehe docs/adr/0017).
 *
 * Der Entra-Sync klassifiziert laufend mit; dieser Befehl ist für die beiden Fälle, in denen das nicht
 * reicht: nach dem **erstmaligen** Hinterlegen der Regeln (der Bestand ist dann noch komplett
 * `person`) und nach einer **Regeländerung**, wenn man nicht auf den nächsten Sync warten will.
 *
 * `--dry-run` zeigt, was sich ändern würde. Bei einem Regelwerk, das versehentlich zu breit greift
 * („alles mit `a` ist ein Admin"), ist die Vorschau der Unterschied zwischen einem Tippfehler und
 * einer stillen Umklassifizierung des halben Bestands.
 */
class ClassifyHoldersCommand extends Command
{
    protected $signature = 'asset-manager:classify-holders
        {--team= : Team-ID (Pflicht)}
        {--tenant= : Nur diesen Tenant; ohne Angabe alle Tenants des Teams}
        {--dry-run : Nur anzeigen, nichts speichern}';

    protected $description = 'Bestimmt holder_type der Asset-Träger neu aus den Tenant-Regeln (ADR 0017)';

    public function handle(HolderClassifier $classifier): int
    {
        $teamId = (int) $this->option('team');
        if (! $teamId) {
            $this->error('--team ist erforderlich.');

            return self::FAILURE;
        }

        $tenantOption = $this->option('tenant');
        $tenants = AssetTenant::query()
            ->where('team_id', $teamId)
            ->when($tenantOption !== null, fn ($q) => $q->whereKey((int) $tenantOption))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($tenants->isEmpty()) {
            $this->error($tenantOption !== null
                ? "Tenant {$tenantOption} gehört nicht zu Team {$teamId}."
                : "Team {$teamId} hat keine Tenants.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $total  = 0;

        foreach ($tenants as $tenant) {
            $rules = $classifier->rulesFor((int) $tenant->id);

            $this->line(sprintf(
                '%s%s (Tenant %d): %d Regel(n)',
                $dryRun ? '[DRY-RUN] ' : '',
                $tenant->name,
                $tenant->id,
                count($rules),
            ));

            // Im Tenant-Kontext laufen, damit der Global Scope nichts Fremdes einbezieht.
            $changed = TenantContext::runFor((int) $tenant->id, function () use ($classifier, $tenant, $dryRun) {
                $changed = [];

                AssetHolder::query()->orderBy('id')->chunkById(500, function ($holders) use ($classifier, $tenant, $dryRun, &$changed) {
                    foreach ($holders as $holder) {
                        $new = $classifier->classify($holder, (int) $tenant->id);

                        if ($holder->holder_type === $new) {
                            continue;
                        }

                        $changed[] = sprintf('%s: %s → %s', $holder->user_principal_name, $holder->holder_type ?? '—', $new);

                        if (! $dryRun) {
                            $holder->holder_type = $new;
                            $holder->saveQuietly();
                        }
                    }
                });

                return $changed;
            });

            foreach (array_slice($changed, 0, 20) as $line) {
                $this->line('    ' . $line);
            }
            if (count($changed) > 20) {
                $this->line(sprintf('    … und %d weitere', count($changed) - 20));
            }

            $this->line(sprintf('    %s%d Änderung(en)', $dryRun ? 'würde ' : '', count($changed)));
            $total += count($changed);
        }

        $this->info($dryRun
            ? "Vorschau: {$total} Träger würden umklassifiziert. Kein Schreibvorgang."
            : "{$total} Träger umklassifiziert.");

        return self::SUCCESS;
    }
}
