<?php

namespace Platform\AssetManager\Livewire\Costs;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetDatevBatch;
use Platform\AssetManager\Models\AssetInvoiceSnapshot;
use Platform\AssetManager\Models\AssetTeamSetting;
use Platform\AssetManager\Services\DatevBatchService;
use Platform\AssetManager\Services\DatevReallocationService;
use Platform\AssetManager\Services\InvoiceSnapshotService;
use Platform\AssetManager\Services\TenantContext;
use Platform\AssetManager\Support\DatevExtfException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * DATEV-Umbuchung (Baustein 5, Discussion #26, docs/adr/0027): aus den Monatsständen der importierten
 * Rechnungen wird je Buchungsmonat ein EXTF-Buchungsstapel, der die IT-Kosten von der Sammel-KST auf die
 * nutzenden Kostenstellen umbucht.
 *
 * Aufbau wie die Weiterberechnung: oben die **Vorschau** (welche Rechnungen, welche Blöcke, welche
 * Befunde — berechnet, nichts geschrieben), darunter das **Protokoll** der erzeugten Stapel mit Download
 * und Verwerfen. Erzeugen und Verwerfen sind Owner/Admin vorbehalten; ansehen und herunterladen darf
 * jedes Teammitglied mit Controlling-Zugang.
 *
 * Arbeitet immer auf **einem** Tenant (dem aktiven): ein Stapel gehört zu genau einem DATEV-Mandanten.
 */
class DatevReallocation extends Component
{
    use AuthorizesTeamRole;
    use ResolvesCurrentTeam;

    /** Buchungsmonat als `Y-m`. */
    public string $month = '';

    public bool $showBookings = false;

    public ?int $voidingId = null;

    public string $voidReason = '';

    public ?string $flash = null;

    /** @var list<string> */
    public array $problems = [];

    protected $queryString = ['month' => ['except' => '']];

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = $this->defaultMonth();
        }
    }

    /** Jüngster Monat mit offenen Monatsständen, sonst der Vormonat. */
    protected function defaultMonth(): string
    {
        return $this->openMonths()->keys()->first()
            ?? now('Europe/Berlin')->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * Monate mit noch umzubuchenden Rechnungen (`Y-m` => Anzahl, neueste zuerst). Gebuchte zählen nicht,
     * direkt gebuchte (ganze Rechnung auf einer KST) auch nicht — sie brauchen nie einen Stapel.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    protected function openMonths(): \Illuminate\Support\Collection
    {
        return AssetInvoiceSnapshot::withoutTenantScope()
            ->where('team_id', $this->teamId())
            ->where('tenant_id', $this->tenantId())
            ->whereNull('datev_batch_id')
            ->whereNotNull('booking_month')
            ->get(['id', 'booking_month', 'distribution'])
            ->reject(fn (AssetInvoiceSnapshot $s) => DatevReallocationService::directCostCenter($s) !== null)
            ->groupBy(fn (AssetInvoiceSnapshot $s) => $s->booking_month->format('Y-m'))
            ->map->count()
            ->sortKeysDesc();
    }

    public function updatedMonth(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = $this->defaultMonth();
        }
        $this->resetMessages();
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = $this->monthStart()->modify(($delta >= 0 ? '+' : '') . $delta . ' month')->format('Y-m');
        $this->resetMessages();
    }

    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    protected function tenantId(): int
    {
        return TenantContext::resolveForWrite($this->teamId(), (int) Auth::id());
    }

    protected function monthStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->month . '-01');
    }

    protected function settings(): ?AssetTeamSetting
    {
        return AssetTeamSetting::withoutTenantScope()
            ->where('team_id', $this->teamId())
            ->where('tenant_id', $this->tenantId())
            ->with('datevSourceCostCenter:id,code,name')
            ->first();
    }

    protected function resetMessages(): void
    {
        $this->flash  = null;
        $this->problems = [];
    }

    // ---- Aktionen ------------------------------------------------------------------------------

    public function createBatch(DatevBatchService $batches): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetMessages();

        $settings = $this->settings();
        $missing  = $settings?->datevMissing() ?? ['Stapelkopf'];
        if ($missing !== []) {
            $this->problems = ['Stapelkopf unvollständig: ' . implode(', ', $missing) . ' — in den Einstellungen nachtragen.'];

            return;
        }

        try {
            $batch = $batches->create(
                $this->teamId(),
                $this->tenantId(),
                $this->monthStart(),
                $settings->datevMeta($this->monthStart()),
                (int) Auth::id(),
                null,
                (string) $settings->datevSourceCostCenter->code,
            );
        } catch (DatevExtfException $e) {
            $this->problems = $e->violations;

            return;
        } catch (Throwable $e) {
            $this->problems = [$e->getMessage()];

            return;
        }

        $this->flash = "Stapel „{$batch->label}“ erzeugt ({$batch->bookings_count} Buchungen). "
            . 'Datei herunterladen und in DATEV importieren — die Rechnungen sind ab jetzt gesperrt.';
    }

    public function download(int $id): StreamedResponse
    {
        $batch = $this->batchOrFail($id);
        $bytes = $batch->contents();

        return response()->streamDownload(fn () => print($bytes), $batch->file_name, [
            'Content-Type' => 'text/csv; charset=windows-1252',
        ]);
    }

    public function startVoid(int $id): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetMessages();
        $this->voidingId  = $this->batchOrFail($id)->id;
        $this->voidReason = '';
    }

    public function cancelVoid(): void
    {
        $this->voidingId  = null;
        $this->voidReason = '';
    }

    public function confirmVoid(DatevBatchService $batches): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetMessages();

        if ($this->voidingId === null) {
            return;
        }

        try {
            $batch = $batches->void($this->batchOrFail($this->voidingId), (int) Auth::id(), $this->voidReason);
        } catch (Throwable $e) {
            $this->problems = [$e->getMessage()];

            return;
        }

        $this->cancelVoid();
        $this->flash = "Stapel „{$batch->label}“ verworfen — seine Rechnungen sind wieder offen. "
            . 'Wurde er schon in DATEV importiert, muss er dort storniert werden.';
    }

    /** Monatsstand einer offenen Rechnung aus den heutigen Kostenpositionen neu festschreiben. */
    public function recapture(int $snapshotId, InvoiceSnapshotService $snapshots): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetMessages();

        $snapshot = $this->snapshotOrFail($snapshotId);

        $result = $snapshots->recapture($snapshot);
        if ($result['findings'] !== []) {
            $this->problems = $result['findings'];

            return;
        }

        $month = $result['snapshot']?->booking_month;
        $this->flash = "Monatsstand der Rechnung {$snapshot->invoice} neu festgeschrieben"
            . ($month !== null ? ' (Buchungsmonat ' . \Platform\AssetManager\Support\DatevBookingText::month($month) . ').' : '.');
    }

    /** Monatsstand einer Rechnung zurücksetzen — der nächste Import schreibt ihn neu fest. */
    public function resetSnapshot(int $snapshotId, InvoiceSnapshotService $snapshots): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetMessages();

        $snapshot = $this->snapshotOrFail($snapshotId);

        try {
            $snapshots->reset($snapshot);
        } catch (Throwable $e) {
            $this->problems = [$e->getMessage()];

            return;
        }

        $this->flash = "Monatsstand der Rechnung {$snapshot->invoice} zurückgesetzt. Der nächste scharfe Import "
            . 'dieser Rechnung schreibt ihn neu fest.';
    }

    /** Alle offenen Monatsstände des gewählten Monats zurücksetzen; gebuchte bleiben. */
    public function resetMonth(InvoiceSnapshotService $snapshots): void
    {
        abort_unless($this->canManage(), 403);
        $this->resetMessages();

        $count = $snapshots->resetMonth($this->teamId(), $this->tenantId(), $this->monthStart());

        $this->flash = $count === 0
            ? 'Keine offenen Monatsstände in diesem Monat.'
            : "{$count} offene(r) Monatsstand/-stände zurückgesetzt. Gebuchte bleiben, bis ihr Stapel verworfen wird.";
    }

    protected function snapshotOrFail(int $id): AssetInvoiceSnapshot
    {
        return AssetInvoiceSnapshot::withoutTenantScope()
            ->where('team_id', $this->teamId())
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);
    }

    protected function batchOrFail(int $id): AssetDatevBatch
    {
        return AssetDatevBatch::withoutTenantScope()
            ->where('team_id', $this->teamId())
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);
    }

    // ---- Ansicht -------------------------------------------------------------------------------

    public function render(DatevReallocationService $reallocation)
    {
        $settings = $this->settings();
        $source   = (string) ($settings?->datevSourceCostCenter?->code ?? '');

        // Vorschau rechnet auch bei unvollständigem Kopf — dann ohne Mandanten-Prüfung und mit „?" als
        // Quelle; erzeugt werden kann erst mit vollständigem Kopf.
        $plan = $reallocation->prepare(
            $this->teamId(),
            $this->tenantId(),
            $this->monthStart(),
            (string) ($settings?->datev_client ?? ''),
            null,
            $source !== '' ? $source : '?',
        );

        $snapshots = AssetInvoiceSnapshot::withoutTenantScope()
            ->where('team_id', $this->teamId())
            ->where('tenant_id', $this->tenantId())
            ->whereDate('booking_month', $this->monthStart()->format('Y-m-d'))
            ->orderBy('source')->orderBy('account')->orderBy('invoice')
            ->get();

        $batches = AssetDatevBatch::withoutTenantScope()
            ->where('team_id', $this->teamId())
            ->where('tenant_id', $this->tenantId())
            ->with(['user:id,name', 'voidedByUser:id,name'])
            ->orderByDesc('booking_month')->orderByDesc('id')
            ->limit(36)
            ->get();

        $openMonths = $this->openMonths();

        $missing = $settings?->datevMissing() ?? ['Stapelkopf'];

        return view('asset-manager::livewire.costs.datev-reallocation', [
            'canManage'   => $this->canManage(),
            'settings'    => $settings,
            'missing'     => $missing,
            'plan'        => $plan,
            'snapshots'   => $snapshots,
            'batches'     => $batches,
            'openMonths'  => $openMonths,
            'batchLabels' => $batches->pluck('label', 'id'),
            // Rechnung-ID => KST, auf die die Buchhaltung sie direkt bucht.
            'directIds'   => collect($plan['direct'])->pluck('cost_center', 'id'),
            'canCreate'   => $missing === [] && $plan['violations'] === [] && $plan['bookings'] !== [],
        ])->layout('platform::layouts.app');
    }
}
