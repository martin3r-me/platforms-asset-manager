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
use Platform\AssetManager\Models\AssetImportRun;
use Platform\AssetManager\Models\AssetCategory;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Services\LicenseInvoiceImportService;
use Platform\AssetManager\Services\TelephonySeatImportService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Services\VodafoneFixedNetImportService;
use Platform\AssetManager\Services\VodafoneInvoiceImportService;
use Platform\AssetManager\Support\ImportRunRecorder;

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

    /** Vierter Upload: RingCentral-Benutzerliste → wer welchen Telefonie-Platz belegt (#931). */
    public $seatFile;
    public ?array $seatResult = null;
    public ?string $seatError = null;
    public bool $seatWasDryRun = true;

    /** Fünfter Upload: Vodafone-Festnetzrechnung „Rechnung CSV" (#931). */
    /** Preis-Historie nachtragen (ADR 0029): mehrere Rechnungsdateien, Typ wird je Datei erkannt. */
    public $historyFiles = [];
    /** @var ?list<array{file: string, type: string, invoice: ?string, period: ?string, rows: int, error: ?string}> */
    public ?array $historyResults = null;

    public $fixednetFile;
    public ?array $fixednetResult = null;
    public ?string $fixednetError = null;
    public bool $fixednetWasDryRun = true;

    /**
     * Erstzuordnung Anschlusskennung → bestehende Internetleitung im Inventar. Nur nötig, solange eine
     * Leitung ihre Kennung noch nicht trägt (Altbestand aus der Excel); danach findet der Import sie
     * selbst. Ohne Zuordnung legte er eine neue Leitung an, und die Excel-Zeile zählte doppelt.
     *
     * @var array<string, int|string|null>
     */
    public array $lineMapping = [];

    /** Aufgeklappter Protokoll-Eintrag (Import-Historie unten auf der Seite). */
    public ?int $expandedRunId = null;

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

        $startedAt = microtime(true);
        $fileName  = $this->file->getClientOriginalName();

        try {
            $path = $this->file->getRealPath();
            $this->result = $service->import($this->teamId(), $path, 'excel-upload', $dryRun, $this->activeTenantId());

            // Nach dem Import protokollieren, nie daraus heraus: der Importer arbeitet in einer
            // Transaktion, die im Probelauf zurueckgerollt wird — ein von dort geschriebener Eintrag
            // verschwaende mit dem Rollback.
            $this->recordRun(AssetImportRun::SOURCE_EXCEL, $this->result, $fileName, $dryRun, $startedAt);
        } catch (\Throwable $e) {
            $this->recordFailure(AssetImportRun::SOURCE_EXCEL, $e, $fileName, $dryRun, $startedAt);
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
        if (! in_array(strtolower($this->vodafoneFile->getClientOriginalExtension() ?? ''), ['xlsx', 'csv'], true)) {
            $this->addError('vodafoneFile', 'Bitte den Portal-Export „Rechnungsdaten" als .xlsx oder .csv hochladen.');
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

        $startedAt = microtime(true);
        $fileName  = $this->vodafoneFile->getClientOriginalName();

        try {
            $this->vodafoneResult = $service->import(
                $this->teamId(),
                $this->vodafoneFile->getRealPath(),
                'vodafone-upload',
                $dryRun,
                $this->activeTenantId(),
            );

            $this->recordRun(AssetImportRun::SOURCE_VODAFONE, $this->vodafoneResult, $fileName, $dryRun, $startedAt);
        } catch (\Throwable $e) {
            $this->recordFailure(AssetImportRun::SOURCE_VODAFONE, $e, $fileName, $dryRun, $startedAt);

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

        $startedAt = microtime(true);
        $fileName  = $this->licenseFile->getClientOriginalName();

        try {
            $this->licenseResult = $service->import(
                $this->teamId(),
                $this->licenseFile->getRealPath(),
                'license-upload',
                $dryRun,
                $this->activeTenantId(),
            );

            $this->recordRun(AssetImportRun::SOURCE_LICENSE_INVOICE, $this->licenseResult, $fileName, $dryRun, $startedAt);
        } catch (\Throwable $e) {
            $this->recordFailure(AssetImportRun::SOURCE_LICENSE_INVOICE, $e, $fileName, $dryRun, $startedAt);

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

    // ---- RingCentral-Benutzerliste + Festnetzrechnung (#931) --------------------------------

    public function updatedSeatFile(): void
    {
        $this->seatResult = null;
        $this->seatError  = null;
        $this->validateUpload('seatFile', ['xlsx'], self::SEAT_FILE_HINT);
    }

    public function updatedFixednetFile(): void
    {
        $this->fixednetResult = null;
        $this->fixednetError  = null;
        $this->lineMapping    = [];
        $this->validateUpload('fixednetFile', ['csv'], self::FIXEDNET_FILE_HINT);
    }

    private const SEAT_FILE_HINT     = 'Bitte die RingCentral-Benutzerliste („Download User List") als .xlsx hochladen.';
    private const FIXEDNET_FILE_HINT = 'Bitte die „Rechnung CSV" des Festnetzkontos hochladen.';

    /** Gemeinsame Upload-Prüfung der beiden Telefonie-Abschnitte; false statt Exception. */
    protected function validateUpload(string $property, array $extensions, string $message): bool
    {
        $this->resetErrorBag($property);
        $file = $this->{$property};

        if (! $file) {
            $this->addError($property, 'Bitte eine Datei wählen.');
            return false;
        }
        if ($file->getSize() > 20 * 1024 * 1024) {
            $this->addError($property, 'Datei zu groß (max. 20 MB).');
            return false;
        }
        if (! in_array(strtolower($file->getClientOriginalExtension() ?? ''), $extensions, true)) {
            $this->addError($property, $message);
            return false;
        }

        return true;
    }

    public function previewSeats(TelephonySeatImportService $service): void
    {
        $this->runSeats($service, true);
    }

    public function runSeatImport(TelephonySeatImportService $service): void
    {
        $this->runSeats($service, false);
    }

    protected function runSeats(TelephonySeatImportService $service, bool $dryRun): void
    {
        // ADR 0004: ersetzt den Platz-Snapshot und verschiebt damit Kosten — Owner/Admin.
        abort_unless($this->canManage(), 403);

        $this->seatError  = null;
        $this->seatResult = null;

        if (! $this->validateUpload('seatFile', ['xlsx'], self::SEAT_FILE_HINT)) {
            return;
        }

        $this->running       = true;
        $this->seatWasDryRun = $dryRun;
        $startedAt = microtime(true);
        $fileName  = $this->seatFile->getClientOriginalName();

        try {
            $this->seatResult = $service->import($this->teamId(), $this->seatFile->getRealPath(), $dryRun, $this->activeTenantId());
            $this->recordRun(AssetImportRun::SOURCE_TELEPHONY_SEATS, $this->seatResult, $fileName, $dryRun, $startedAt);
        } catch (\Throwable $e) {
            $this->recordFailure(AssetImportRun::SOURCE_TELEPHONY_SEATS, $e, $fileName, $dryRun, $startedAt);
            Log::error('AssetManager: RingCentral-Benutzerliste fehlgeschlagen', ['team_id' => $this->teamId(), 'error' => $e->getMessage()]);
            $this->seatError = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Der Import ist fehlgeschlagen. Bitte Datei und Format prüfen; Details stehen im Server-Log.';
        } finally {
            $this->running = false;
        }
    }

    public function previewFixedNet(VodafoneFixedNetImportService $service): void
    {
        $this->runFixedNet($service, true);
    }

    public function runFixedNetImport(VodafoneFixedNetImportService $service): void
    {
        $this->runFixedNet($service, false);
    }

    protected function runFixedNet(VodafoneFixedNetImportService $service, bool $dryRun): void
    {
        // ADR 0004: löst Excel-Zeilen hart ab und schreibt Kostenpositionen — Owner/Admin.
        abort_unless($this->canManage(), 403);

        $this->fixednetError  = null;
        $this->fixednetResult = null;

        if (! $this->validateUpload('fixednetFile', ['csv'], self::FIXEDNET_FILE_HINT)) {
            return;
        }

        $this->running           = true;
        $this->fixednetWasDryRun = $dryRun;
        $startedAt = microtime(true);
        $fileName  = $this->fixednetFile->getClientOriginalName();

        // Nur echte Auswahlen weiterreichen; „— neue Leitung anlegen —" ist ein leerer Wert.
        $mapping = array_map('intval', array_filter($this->lineMapping, fn ($id) => (int) $id > 0));

        try {
            $this->fixednetResult = $service->import(
                $this->teamId(),
                $this->fixednetFile->getRealPath(),
                'fixednet-upload',
                $dryRun,
                $this->activeTenantId(),
                $mapping,
            );
            $this->recordRun(AssetImportRun::SOURCE_VODAFONE_FIXEDNET, $this->fixednetResult, $fileName, $dryRun, $startedAt);
        } catch (\Throwable $e) {
            $this->recordFailure(AssetImportRun::SOURCE_VODAFONE_FIXEDNET, $e, $fileName, $dryRun, $startedAt);
            Log::error('AssetManager: Festnetz-Import fehlgeschlagen', ['team_id' => $this->teamId(), 'error' => $e->getMessage()]);
            $this->fixednetError = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Der Import ist fehlgeschlagen. Bitte Datei und Format prüfen; Details stehen im Server-Log.';
        } finally {
            $this->running = false;
        }
    }

    // ---- Preis-Historie nachtragen (ADR 0029) ----------------------------------------------------

    public function updatedHistoryFiles(): void
    {
        $this->historyResults = null;
        $this->resetErrorBag('historyFiles');
    }

    /**
     * Ältere Rechnungen nur in die Preis-Historie übernehmen. Je Datei rechnet der passende Importer wie
     * immer und rollt zurück; geschrieben werden ausschließlich die Verlaufszeilen — Kosten, Monatsstände
     * und DATEV bleiben, wie sie sind. Deshalb kein Bestätigungsdialog und keine Sperre gegen alte Monate.
     */
    public function runHistoryBackfill(VodafoneInvoiceImportService $mobile, VodafoneFixedNetImportService $fixed): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetErrorBag('historyFiles');

        $files = array_values(array_filter((array) $this->historyFiles));
        if ($files === []) {
            $this->addError('historyFiles', 'Bitte eine oder mehrere Rechnungsdateien wählen.');
            return;
        }
        // PHP (max_file_uploads) liefert höchstens 20 Dateien je Upload — mehr kommt hier nie an.
        if (count($files) > 20) {
            $this->addError('historyFiles', 'Höchstens 20 Dateien je Durchgang.');
            return;
        }

        $this->running = true;
        $results = [];

        foreach ($files as $file) {
            $name = $file->getClientOriginalName();
            $row  = ['file' => $name, 'type' => '—', 'invoice' => null, 'period' => null, 'rows' => 0, 'error' => null];

            try {
                if ($file->getSize() > 20 * 1024 * 1024) {
                    throw new \RuntimeException('Datei zu groß (max. 20 MB).');
                }

                $type = self::historyFileType($file->getRealPath(), strtolower((string) $file->getClientOriginalExtension()));
                if ($type === null) {
                    throw new \RuntimeException('Unbekanntes Format — erwartet „Rechnungsdaten" (Mobilfunk) oder „Rechnung CSV" (Festnetz).');
                }

                if ($type === 'mobile') {
                    $res = $mobile->import($this->teamId(), $file->getRealPath(), 'history-upload', false, $this->activeTenantId(), historyOnly: true);
                    $row['type']    = 'Mobilfunk';
                    $row['invoice'] = $res['period']['invoice'] ?? null;
                    $row['period']  = ($res['period']['from'] ?? null) && ($res['period']['to'] ?? null)
                        ? $res['period']['from']->format('d.m.Y') . '–' . $res['period']['to']->format('d.m.Y') : null;
                } else {
                    $res = $fixed->import($this->teamId(), $file->getRealPath(), 'history-upload', false, $this->activeTenantId(), [], historyOnly: true);
                    $row['type']    = 'Festnetz ' . ($res['account'] ?? '');
                    $row['invoice'] = $res['invoice'] ?? null;
                    $row['period']  = $res['period'] ?? null;
                }
                $row['rows'] = (int) ($res['history_rows'] ?? 0);
            } catch (\Throwable $e) {
                Log::warning('AssetManager: Historie nachtragen fehlgeschlagen', ['team_id' => $this->teamId(), 'file' => $name, 'error' => $e->getMessage()]);
                $row['error'] = $e instanceof \RuntimeException ? $e->getMessage() : 'Fehlgeschlagen — Details im Server-Log.';
            }

            $results[] = $row;
        }

        usort($results, fn ($a, $b) => [$a['type'], $a['invoice']] <=> [$b['type'], $b['invoice']]);

        $this->historyResults = $results;
        $this->historyFiles   = [];
        $this->running        = false;
    }

    /** 'mobile' (Rechnungsdaten-Export, .xlsx oder CSV), 'fixednet' (Rechnung CSV eines Festnetzkontos) oder null. */
    public static function historyFileType(string $path, string $extension): ?string
    {
        if ($extension === 'xlsx') {
            return 'mobile';
        }

        $head = (string) @file_get_contents($path, false, null, 0, 200);
        $head = ltrim($head, "\xEF\xBB\xBF");

        return match (true) {
            str_starts_with($head, 'Typ;Rechnungskonto')  => 'mobile',
            str_starts_with($head, 'Dokument Nummer;')     => 'fixednet',
            default                                        => null,
        };
    }

    /**
     * Internetleitungen ohne Anschlusskennung — die Kandidaten der Erstzuordnung. Nur geladen, wenn
     * eine Vorschau unbekannte Kennungen gemeldet hat.
     */
    protected function unmappedInternetLines(): \Illuminate\Support\Collection
    {
        if (empty($this->fixednetResult['unknown_lines'])) {
            return collect();
        }

        $categoryId = AssetCategory::where('key', 'internet')->value('id');

        return AssetItem::where('team_id', $this->teamId())
            ->where('category_id', $categoryId)
            ->whereNull('external_id')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    // ---- Import-Protokoll ------------------------------------------------------------------

    /**
     * Einen abgeschlossenen Lauf festhalten.
     *
     * Bewusst hier und nicht in den Importern: nur an dieser Stelle ist die Transaktion des
     * Importers schon abgeschlossen (im Probelauf zurückgerollt), und nur hier sind Dateiname und
     * angemeldeter Nutzer bekannt. Ein Fehler beim Protokollieren darf den Import nie nachträglich
     * scheitern lassen — deshalb gekapselt.
     *
     * @param array<string, mixed> $result
     */
    protected function recordRun(string $source, array $result, ?string $fileName, bool $dryRun, float $startedAt): void
    {
        try {
            app(ImportRunRecorder::class)->record(
                source: $source,
                teamId: $this->teamId(),
                tenantId: $this->activeTenantId() ?? TenantContext::defaultTenantId($this->teamId()),
                result: $result,
                fileName: $fileName,
                dryRun: $dryRun,
                userId: Auth::id(),
                startedAt: $startedAt,
            );
        } catch (\Throwable $e) {
            Log::warning('AssetManager: Import-Protokoll konnte nicht geschrieben werden', [
                'source' => $source,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /** Fehlgeschlagenen Lauf festhalten — die wichtigste Zeile im Protokoll. */
    protected function recordFailure(string $source, \Throwable $exception, ?string $fileName, bool $dryRun, float $startedAt): void
    {
        try {
            app(ImportRunRecorder::class)->recordFailure(
                source: $source,
                teamId: $this->teamId(),
                tenantId: $this->activeTenantId() ?? TenantContext::defaultTenantId($this->teamId()),
                message: $exception->getMessage(),
                fileName: $fileName,
                dryRun: $dryRun,
                userId: Auth::id(),
                startedAt: $startedAt,
            );
        } catch (\Throwable $e) {
            Log::warning('AssetManager: Import-Protokoll (Fehlschlag) konnte nicht geschrieben werden', [
                'source' => $source,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /** Einen Protokoll-Eintrag auf- oder zuklappen. */
    public function toggleRun(int $runId): void
    {
        $this->expandedRunId = $this->expandedRunId === $runId ? null : $runId;
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
            'internetLines' => $this->unmappedInternetLines(),
            // Import-Historie: die letzten Läufe aller drei Quellen. 25 reichen — der Log ist zum
            // Nachvollziehen der jüngsten Arbeit da, nicht als Archiv.
            'runs'      => AssetImportRun::where('team_id', $this->teamId())
                ->with('user:id,name')
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
        ])->layout('platform::layouts.app');
    }
}
