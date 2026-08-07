<?php

namespace Platform\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Platform\AssetManager\Models\AssetTenant;
use Platform\AssetManager\Services\CostExcelImportService;

class ImportCostExcelCommand extends Command
{
    protected $signature = 'asset-manager:import-costs
        {--team= : Team-ID (Pflicht)}
        {--tenant= : Tenant-ID (Kundenkontext); ohne Angabe der Default-Tenant des Teams}
        {--file= : Pfad zur Kostenaufteilung_IT.xlsx (Pflicht)}
        {--batch=excel-bootstrap : Import-Batch-ID}
        {--dry-run : Nur einlesen, am Ende zurückrollen}';

    protected $description = 'Importiert die Kostenaufteilungs-Excel als Ist-Stand-Bootstrap (idempotent)';

    public function handle(CostExcelImportService $service): int
    {
        $teamId   = (int) $this->option('team');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $file     = $this->option('file');

        if (!$teamId || !$file) {
            $this->error('--team und --file sind erforderlich.');
            return self::FAILURE;
        }
        if (!is_file($file)) {
            $this->error("Datei nicht gefunden: {$file}");
            return self::FAILURE;
        }

        // Tenant vorab prüfen: ein Tippfehler in --tenant würde sonst den Default-Tenant treffen und
        // die Kostenzeilen im falschen Kundenkontext anlegen (ADR 0016).
        if ($tenantId !== null && !AssetTenant::query()->whereKey($tenantId)->where('team_id', $teamId)->exists()) {
            $this->error("Tenant {$tenantId} gehört nicht zu Team {$teamId}.");

            return self::FAILURE;
        }

        $target = $tenantId !== null ? "Tenant {$tenantId}" : 'den Default-Tenant';
        $this->info(($this->option('dry-run') ? '[DRY-RUN] ' : '') . "Importiere {$file} für Team {$teamId} in {$target} …");

        $stats = $service->import($teamId, $file, (string) $this->option('batch'), (bool) $this->option('dry-run'), $tenantId);

        foreach ($stats as $sheet => $value) {
            $this->line(sprintf('  %-14s %s', $sheet, is_int($value) ? "{$value} Positionen" : $value));
        }

        $this->info('Fertig.');
        return self::SUCCESS;
    }
}
