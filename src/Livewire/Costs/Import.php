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
use Platform\AssetManager\Services\LicenseInvoiceImportService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneInvoiceImportService;

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

    /** Zweiter, unabhängiger Upload: Vodafone-Rechnungsanalyse (ADR 0018). */
    public $vodafoneFile;
    public ?array $vodafoneResult = null;
    public ?string $vodafoneError = null;
    public bool $vodafoneWasDryRun = true;

    /** Dritter Upload: Lizenz-Abrechnung des Wiederverkäufers (ADR 0019). */
    public $licenseFile;
    public ?array $licenseResult = null;
    public ?string $licenseError = null;
    public bool $licenseWasDryRun = true;

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
            $this->result = $service->import($this->teamId(), $path, 'excel-upload', $dryRun, $this->activeTenantId());
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

    // ---- Vodafone-Rechnungsanalyse ---------------------------------------------------------

    public function updatedVodafoneFile(): void
    {
        $this->vodafoneResult = null;
        $this->vodafoneError  = null;
        $this->validateVodafoneFile();
    }

    protected function validateVodafoneFile(): bool
    {
        $this->resetErrorBag('vodafoneFile');

        if (! $this->vodafoneFile) {
            $this->addError('vodafoneFile', 'Bitte eine Datei wählen.');
            return false;
        }
        if ($this->vodafoneFile->getSize() > 20 * 1024 * 1024) {
            $this->addError('vodafoneFile', 'Datei zu groß (max. 20 MB).');
            return false;
        }
        if (strtolower($this->vodafoneFile->getClientOriginalExtension() ?? '') !== 'xlsx') {
            $this->addError('vodafoneFile', 'Bitte den Portal-Export als .xlsx hochladen.');
            return false;
        }

        return true;
    }

    public function previewVodafone(VodafoneInvoiceImportService $service): void
    {
        $this->runVodafone($service, true);
    }

    public function runVodafoneImport(VodafoneInvoiceImportService $service): void
    {
        $this->runVodafone($service, false);
    }

    protected function runVodafone(VodafoneInvoiceImportService $service, bool $dryRun): void
    {
        // Gleiche Grenze wie beim Excel-Import (ADR 0004): der Lauf löscht die abgelösten
        // Excel-Mobilfunkzeilen hart und schreibt Kostenpositionen — Owner/Admin.
        abort_unless($this->canManage(), 403);

        $this->vodafoneError  = null;
        $this->vodafoneResult = null;

        if (! $this->validateVodafoneFile()) {
            return;
        }

        $this->running          = true;
        $this->vodafoneWasDryRun = $dryRun;

        try {
            $this->vodafoneResult = $service->import(
                $this->teamId(),
                $this->vodafoneFile->getRealPath(),
                'vodafone-upload',
                $dryRun,
                $this->activeTenantId(),
            );
        } catch (\Throwable $e) {
            Log::error('AssetManager: Vodafone-Import fehlgeschlagen', [
                'team_id' => $this->teamId(),
                'dry_run' => $dryRun,
                'error'   => $e->getMessage(),
            ]);

            // Die Meldungen des Services sind bewusst fachlich formuliert (fehlendes Blatt, fehlende
            // Kostenart) — die darf der Nutzer sehen, sie sagen ihm, was zu tun ist.
            $this->vodafoneError = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Der Import ist fehlgeschlagen. Bitte Datei und Format prüfen; Details stehen im Server-Log.';
        } finally {
            $this->running = false;
        }
    }

    public function updatedLicenseFile(): void
    {
        $this->licenseResult = null;
        $this->licenseError  = null;
        $this->validateLicenseFile();
    }

    /** Validiert die Lizenz-Abrechnung; gibt false zurück (statt zu werfen), wenn ungültig. */
    protected function validateLicenseFile(): bool
    {
        $this->resetErrorBag('licenseFile');

        if (! $this->licenseFile) {
            $this->addError('licenseFile', 'Bitte eine Datei wählen.');
            return false;
        }
        if ($this->licenseFile->getSize() > 20 * 1024 * 1024) {
            $this->addError('licenseFile', 'Datei zu groß (max. 20 MB).');
            return false;
        }
        if (strtolower($this->licenseFile->getClientOriginalExtension() ?? '') !== 'csv') {
            $this->addError('licenseFile', 'Bitte den Export „Abrechnungsdetails" als .csv hochladen.');
            return false;
        }

        return true;
    }

    public function previewLicenseInvoice(LicenseInvoiceImportService $service): void
    {
        $this->runLicenseInvoice($service, true);
    }

    public function runLicenseInvoiceImport(LicenseInvoiceImportService $service): void
    {
        $this->runLicenseInvoice($service, false);
    }

    protected function runLicenseInvoice(LicenseInvoiceImportService $service, bool $dryRun): void
    {
        // Gleiche Grenze wie bei den anderen Importen (ADR 0004): der Lauf schreibt Preise, die in die
        // Kostenaufteilung einfließen, und löst handgepflegte Stückpreise ab — Owner/Admin.
        abort_unless($this->canManage(), 403);

        $this->licenseError  = null;
        $this->licenseResult = null;

        if (! $this->validateLicenseFile()) {
            return;
        }

        $this->running          = true;
        $this->licenseWasDryRun = $dryRun;

        try {
            $this->licenseResult = $service->import(
                $this->teamId(),
                $this->licenseFile->getRealPath(),
                'license-upload',
                $dryRun,
                $this->activeTenantId(),
            );
        } catch (\Throwable $e) {
            Log::error('AssetManager: Lizenz-Rechnungsimport fehlgeschlagen', [
                'team_id' => $this->teamId(),
                'dry_run' => $dryRun,
                'error'   => $e->getMessage(),
            ]);

            // Die Meldungen des Services sind bewusst fachlich formuliert (falsche Kopfzeile, keine
            // Positionen) — sie sagen dem Nutzer, was mit der Datei nicht stimmt.
            $this->licenseError = $e instanceof \RuntimeException
                ? $e->getMessage()
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
        $this->resetResult = $reset->clearImport($this->teamId(), $this->activeTenantId());
    }

    /**
     * Tenant, in den Import und Reset wirken (ADR 0016) — der aktuell gewählte Kundenkontext.
     * Explizit übergeben statt dem Global Scope überlassen: bei einem Import ist es wichtiger, dass
     * im Code steht, wo die Zeilen landen, als dass es kurz ist.
     */
    protected function activeTenantId(): int
    {
        return TenantContext::resolveForWrite($this->teamId(), (int) Auth::id());
    }

    public function render()
    {
        return view('asset-manager::livewire.costs.import', [
            'canManage' => $this->canManage(),
        ])->layout('platform::layouts.app');
    }
}
