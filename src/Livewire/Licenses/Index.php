<?php

namespace Platform\AssetManager\Livewire\Licenses;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Jobs\SyncLicensesJob;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetLicenseSyncLog;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Platform\AssetManager\Support\LicensePriceBook;

class Index extends Component
{
    use WithPagination;

    public string  $search        = '';
    public string  $sortField     = 'display_name';
    public string  $sortDirection = 'asc';
    public ?string $syncResult    = null;

    /** Hinweis, wenn ein Preis nicht von Hand gesetzt werden kann (rechnungsgedeckte Lizenz). */
    public ?string $priceNotice   = null;

    // Inline-Preis-Bearbeitung
    public array $editingPrices = [];

    // Filter (linke Sidebar)
    public string $filterUsage = '';   // '' | 'unused' | 'full'
    public string $filterPrice = '';   // '' | 'priced' | 'unpriced'
    public int    $perPage     = 25;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterUsage(): void { $this->resetPage(); }
    public function updatedFilterPrice(): void { $this->resetPage(); }
    public function updatedPerPage(): void { $this->resetPage(); }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField     = $field;
            $this->sortDirection = 'asc';
        }
    }

    public function syncNow(): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $team = Auth::user()->currentTeam;

        SyncLicensesJob::dispatchForTeam($team->id);

        $this->syncResult = 'Lizenz-Sync gestartet — Daten werden im Hintergrund synchronisiert.';
    }

    public function updatePrice(int $skuId, ?string $price): void
    {
        Gate::authorize('manageConnector', AssetDevice::class);

        $team = Auth::user()->currentTeam;

        $sku = AssetLicenseSku::where('team_id', $team->id)->where('id', $skuId)->first();

        if ($sku === null) {
            return;
        }

        // Steht der Preis dieser Lizenz auf einer Rechnung, gibt es hier nichts zu setzen: die Seats
        // tragen ihren Tarifpreis, und ein Handwert daneben waere eine zweite Wahrheit (ADR 0019).
        if ($sku->isContractPriced()) {
            $this->priceNotice = 'Der Preis dieser Lizenz kommt aus der Rechnung und wird nicht von Hand gesetzt.';
            return;
        }

        $sku->update([
            'unit_price'       => $price !== null && $price !== '' ? (float) str_replace(',', '.', $price) : null,
            'price_source'     => $price !== null && $price !== '' ? AssetLicenseSku::PRICE_SOURCE_MANUAL : null,
            'price_updated_at' => now(),
        ]);

        LicensePriceBook::flush();

        unset($this->editingPrices[$skuId]);
    }

    public function render()
    {
        $team  = Auth::user()->currentTeam;
        $query = AssetLicenseSku::where('team_id', $team->id);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('sku_part_number', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterUsage === 'unused') {
            $query->where('available_units', '>', 0);
        } elseif ($this->filterUsage === 'full') {
            $query->where('available_units', '<=', 0)->where('purchased_units', '>', 0);
        }

        // Der Preisfilter kann nicht mehr rein in SQL laufen: ein Preis kann am Stueckpreis ODER an
        // Vertragszeilen haengen. Die Lizenzzahl je Tenant ist zweistellig, daher wird nach dem Laden
        // gefiltert — eine SQL-Variante braeuchte ein Subquery auf die Vertragszeilen ohne Mehrwert.
        $pricedSkuIds = null;

        if ($this->filterPrice !== '') {
            $pricedSkuIds = AssetLicenseSku::where('team_id', $team->id)
                ->get(['sku_id', 'unit_price'])
                ->filter(fn ($s) => $s->unit_price !== null
                    || $book->priceRangeForSku((string) $s->sku_id) !== null)
                ->pluck('sku_id')
                ->all();

            $this->filterPrice === 'priced'
                ? $query->whereIn('sku_id', $pricedSkuIds ?: ['__none__'])
                : $query->whereNotIn('sku_id', $pricedSkuIds ?: ['__none__']);
        }

        $dir = $this->sortDirection === 'desc' ? 'desc' : 'asc';

        if ($this->sortField === 'monthly_cost') {
            // Naeherung in SQL (Handpreis x Seats). Rechnungsgedeckte Lizenzen haben hier 0 und
            // landen zusammen; die exakte Reihenfolge liefert die Anzeige-Spalte selbst. Eine
            // korrekte Sortierung braeuchte die Vertragszeilen-Summe als Subquery.
            $query->orderByRaw('COALESCE(unit_price, 0) * consumed_units ' . $dir);
        } else {
            $field = in_array($this->sortField, ['display_name', 'consumed_units'], true)
                ? $this->sortField
                : 'display_name';
            $query->orderBy($field, $dir);
        }

        $skus = $query->paginate($this->perPage);

        $book = LicensePriceBook::for($team->id);

        $allSkus          = AssetLicenseSku::where('team_id', $team->id)->get();
        // Monatskosten je Lizenz: rechnungsgedeckt aus den Vertragszeilen (Seats + Sammelstellen),
        // sonst aus dem handgepflegten Stueckpreis. Eine Quelle fuer alle Auswertungen (ADR 0019).
        $costFor = fn ($sku) => $book->priceRangeForSku((string) $sku->sku_id) !== null
            ? $book->monthlyCostForSku((string) $sku->sku_id)
            : $sku->monthlyCost();

        $totalMonthlyCost = $allSkus->sum($costFor);
        $unusedLicenses   = $allSkus->where('available_units', '>', 0)->count();
        // Kostenrelevante Luecke: genutzte Lizenzen, die WEDER auf einer Rechnung stehen NOCH einen
        // Handpreis haben. Vorher zaehlte hier jede Lizenz mit leerem `unit_price` — seit dem
        // Rechnungsimport ist das Feld bei gedeckten Lizenzen absichtlich leer, und der Zaehler
        // meldete 14 fehlende Preise, obwohl alle Betraege belegt waren.
        $unpricedCount    = $allSkus->filter(
            fn($s) => $s->unit_price === null
                && $book->priceRangeForSku((string) $s->sku_id) === null
                && (int) $s->consumed_units > 0
        )->count();

        $lastLog  = AssetLicenseSyncLog::where('team_id', $team->id)
            ->orderBy('started_at', 'desc')
            ->first();

        $config   = AssetConnectorConfig::where('team_id', $team->id)->first();
        $canSync  = Gate::allows('manageConnector', AssetDevice::class);

        // Die Nutzer-Zuweisungen einer SKU standen früher in einem rechten Vorschau-Panel; sie sind
        // auf der Lizenz-Detailseite vollständig (und paginiert) zu sehen — „Nutzer" führt dorthin (S8b).
        return view('asset-manager::livewire.licenses.index', [
            'skus'             => $skus,
            'book'             => $book,
            'totalMonthlyCost' => $totalMonthlyCost,
            'unusedLicenses'   => $unusedLicenses,
            'unpricedCount'    => $unpricedCount,
            'lastLog'          => $lastLog,
            'canSync'          => $canSync,
            'config'           => $config,
        ])->layout('platform::layouts.app');
    }
}
