<?php

namespace Platform\AssetManager\Console\Commands;

use Illuminate\Console\Command;
use Platform\AssetManager\Services\CostExcelImportService;

class ImportCostExcelCommand extends Command
{
    protected $signature = 'asset-manager:import-costs
        {--team= : Team-ID (Pflicht)}
        {--file= : Pfad zur Kostenaufteilung_IT.xlsx (Pflicht)}
        {--batch=excel-bootstrap : Import-Batch-ID}
        {--dry-run : Nur einlesen, am Ende zurückrollen}';

    protected $description = 'Importiert die Kostenaufteilungs-Excel als Ist-Stand-Bootstrap (idempotent)';

    public function handle(CostExcelImportService $service): int
    {
        $teamId = (int) $this->option('team');
        $file   = $this->option('file');

        if (!$teamId || !$file) {
            $this->error('--team und --file sind erforderlich.');
            return self::FAILURE;
        }
        if (!is_file($file)) {
            $this->error("Datei nicht gefunden: {$file}");
            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? '[DRY-RUN] ' : '') . "Importiere {$file} für Team {$teamId} …");

        $stats = $service->import($teamId, $file, (string) $this->option('batch'), (bool) $this->option('dry-run'));

        foreach ($stats as $sheet => $value) {
            $this->line(sprintf('  %-14s %s', $sheet, is_int($value) ? "{$value} Positionen" : $value));
        }

        $this->info('Fertig.');
        return self::SUCCESS;
    }
}
