<?php

namespace Platform\AssetManager\Livewire\Costs;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Services\CostExcelImportService;
use Platform\AssetManager\Services\CostResetService;

class Import extends Component
{
    use ResolvesCurrentTeam;
    use WithFileUploads;
    use AuthorizesTeamRole;

    public $file;                      // TemporaryUploadedFile
    public ?array $result = null;      // Statistik je Sheet
    public ?array $resetResult = null; // Gelöschte Anzahlen beim Reset
    public ?string $error = null;
    public bool $wasDryRun = true;
    public bool $running = false;

    /** owner/admin im aktiven Team? (analog AssetDevicePolicy) */
    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    public function updatedFile(): void
    {
        $this->result = null;
        $this->error  = null;
        $this->validateFile();
    }

    /** Validiert die Datei; gibt false zurück (statt zu werfen), wenn ungültig. */
    protected function validateFile(): bool
    {
        $this->resetErrorBag('file');

        if (!$this->file) {
            $this->addError('file', 'Bitte eine Datei wählen.');
            return false;
        }
        if ($this->file->getSize() > 20 * 1024 * 1024) {
            $this->addError('file', 'Datei zu groß (max. 20 MB).');
            return false;
        }
        $ext = strtolower($this->file->getClientOriginalExtension() ?? '');
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            $this->addError('file', 'Bitte eine .xlsx- oder .xls-Datei hochladen.');
            return false;
        }
        return true;
    }

    public function preview(CostExcelImportService $service): void
    {
        $this->run($service, true);
    }

    public function runImport(CostExcelImportService $service): void
    {
        $this->run($service, false);
    }

    protected function run(CostExcelImportService $service, bool $dryRun): void
    {
        // Schreibrechte (ADR 0004): Import/Vorschau ist eine Verwaltungsaktion — nur Owner/Admin.
        // Zentral in run(), deckt preview() (Dry-Run) und runImport() gleichermaßen. runImport()
        // löscht via pruneStaleLines() Import-Cost-Lines hart und überschreibt Stammdaten — vorher
        // war nur resetImport() gegated, dieser destruktivere Pfad nicht.
        abort_unless($this->canManage(), 403);

        $this->error  = null;
        $this->result = null;

        if (!$this->validateFile()) {
            return;
        }

        $this->running   = true;
        $this->wasDryRun = $dryRun;

        try {
            $path = $this->file->getRealPath();
            $this->result = $service->import($this->teamId(), $path, 'excel-upload', $dryRun);
        } catch (\Throwable $e) {
            // Rohe Exception-Message ins Server-Log (kann Pfade/interne Details enthalten), dem Nutzer eine
            // generische bzw. handhabbare Meldung zeigen (N8) — keine rohe Exception in die UI.
            Log::error('AssetManager: Excel-Import fehlgeschlagen', [
                'team_id' => $this->teamId(),
                'dry_run' => $dryRun,
                'error'   => $e->getMessage(),
            ]);

            $this->error = str_contains($e->getMessage(), 'geöffnet werden')
                ? 'Die Datei konnte nicht geöffnet werden — es wird eine echte .xlsx-Datei erwartet (kein altes .xls/CSV, nicht passwortgeschützt).'
                : 'Der Import ist fehlgeschlagen. Bitte Datei und Format prüfen; Details stehen im Server-Log.';
        } finally {
            $this->running = false;
        }
    }

    /** Macht den Excel-Import komplett rückgängig (Stammdaten bleiben). Nur owner/admin. */
    public function resetImport(CostResetService $reset): void
    {
        abort_unless($this->canManage(), 403);

        $this->error       = null;
        $this->result      = null;
        $this->resetResult = $reset->clearImport($this->teamId());
    }

    public function render()
    {
        return view('asset-manager::livewire.costs.import', [
            'canManage' => $this->canManage(),
        ])->layout('platform::layouts.app');
    }
}
