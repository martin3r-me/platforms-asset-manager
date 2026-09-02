<?php

namespace Platform\AssetManager\Livewire\Holders;

use Livewire\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Platform\AssetManager\Concerns\GuardsTenantBoundary;
use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetHandover;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Models\AssetLicenseSku;
use Platform\AssetManager\Models\AssetUserLicense;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\CostAggregationService;
use Platform\AssetManager\Services\HolderService;
use Platform\AssetManager\Services\HolderTypeService;

class Show extends Component
{
    use GuardsTenantBoundary;

    public AssetHolder $holder;

    public string  $displayName = '';
    public string  $email       = '';
    public string  $department  = '';
    public string  $costCenter  = '';
    public string  $jobTitle    = '';
    public bool    $isActive    = true;

    /**
     * Träger-Typ (ADR 0017). Von Hand setzbar, weil die Erkennungsregeln nur Namensmuster kennen: ein
     * Funktionskonto, dessen Kennung wie die einer Person aussieht, findet keine Regel. Ein hier
     * gesetzter Nicht-Personen-Typ **überlebt jeden Sync** ({@see HolderClassifier::classify()}).
     *
     * Für die Lizenz-Verteilung ist das der entscheidende Schalter: nur Funktionskonten erhalten die
     * Monatspreis-Zeilen (ADR 0019, Schritt 1).
     */
    public string  $holderType = AssetHolder::TYPE_PERSON;

    /**
     * Zugehöriges System eines Funktionskontos (ADR 0019). Zusammen mit der Kostenstelle die zweite
     * Pflichtangabe, ohne die die Lizenz-Verteilungs-Kaskade ein Funktionskonto nicht bevorzugt
     * behandelt — ein Konto ohne benanntes System ist nicht bewirtschaftbar.
     */
    public string  $functionSystem = '';
    public bool    $saved       = false;
    public bool    $anonymized  = false;

    // Mobilfunk (ADR 0014): Rufnummern aus Entra + manuelle Metadaten am Asset-Träger (1:1, kein eigenes Modell).
    public string  $mobilePhone    = '';
    public string  $businessPhone  = '';
    public string  $simNumber      = '';
    public string  $contractNumber = '';
    public string  $dataVolume     = '';
    // Toggle: true = Rufnummern werden aus Entra gepflegt; false = manuell übersteuert (phone_overridden).
    public bool    $phoneFromEntra = true;
    // Mobilfunk-Bearbeiten-Modal (Trigger am Mobilfunk-Block im Content, nicht mehr in der Profil-Leiste).
    public bool    $showMobilfunk  = false;

    public function mount(AssetHolder $holder): void
    {
        // Team → 403, fremder Tenant → 404 (ADR 0016).
        $this->assertTenantBoundary($holder);
        $this->holder    = $holder;
        $this->displayName = $holder->display_name ?? '';
        $this->email       = $holder->email ?? '';
        $this->department  = $holder->department ?? '';
        $this->costCenter  = $holder->cost_center ?? '';
        $this->jobTitle    = $holder->job_title ?? '';
        $this->isActive    = $holder->is_active;
        $this->holderType     = $holder->holder_type ?? AssetHolder::TYPE_PERSON;
        $this->functionSystem = $holder->function_system ?? '';

        $this->mobilePhone    = $holder->mobile_phone ?? '';
        $this->businessPhone  = $holder->business_phone ?? '';
        $this->simNumber      = $holder->sim_number ?? '';
        $this->contractNumber = $holder->contract_number ?? '';
        $this->dataVolume     = $holder->data_volume ?? '';
        $this->phoneFromEntra = ! $holder->phone_overridden;
    }

    public function save(): void
    {
        Gate::authorize('asset-manager.manage');

        $this->validate([
            'displayName' => 'nullable|string|max:255',
            'email'       => 'nullable|email|max:255',
            'department'  => 'nullable|string|max:255',
            'costCenter'  => 'nullable|string|max:255',
            'jobTitle'    => 'nullable|string|max:255',
            'isActive'    => 'boolean',
            'functionSystem' => 'nullable|string|max:255',
            'holderType'     => 'required|in:' . implode(',', AssetHolder::TYPES),
        ]);

        // Kostenstellen-Code team-scoped auflösen und cost_center + cost_center_id KONSISTENT setzen.
        // CostAggregationService gruppiert ausschließlich über cost_center_id — ein reines String-Update
        // (wie bisher) ließe die Kosten im Pivot unter „Ohne Kostenstelle" landen. Muster: UpdateHolderTool.
        $code   = trim($this->costCenter);
        $center = $code !== ''
            ? AssetCostCenter::where('team_id', $this->holder->team_id)->where('code', $code)->first()
            : null;

        $typeBefore   = $this->holder->holder_type;
        $isFunction   = $this->holderType === AssetHolder::TYPE_FUNCTION;
        $systemBefore = $this->holder->function_system;

        $this->holder->update([
            'holder_type'    => $this->holderType,
            'display_name'   => $this->displayName ?: null,
            'email'          => $this->email ?: null,
            'department'     => $this->department ?: null,
            'cost_center'    => $center?->code ?? ($code !== '' ? $code : null),
            'cost_center_id' => $center?->id,
            'job_title'      => $this->jobTitle ?: null,
            'is_active'      => $this->isActive,
            // Nur an Funktionskonten sinnvoll; an einer Person würde das Feld nur verwirren. Bewertet
            // wird der eben gewählte Typ, nicht der alte — sonst ginge das System beim Umstellen auf
            // „Funktionskonto" im selben Speichervorgang verloren.
            'function_system' => $isFunction ? ($this->functionSystem ?: null) : null,
        ]);

        // Typ und System steuern die Lizenz-Verteilung (ADR 0019, Schritt 1). Ändert sich einer der
        // beiden, verschieben sich Mengen zwischen Trägern und Sammel-Kostenstellen — ohne erneute
        // Verteilung stünde in der Kostenaufteilung ein Stand, den die Stammdaten nicht mehr hergeben.
        // Die Regel selbst liegt im HolderTypeService, damit Detailseite, Sammelaktion und MCP-Tool
        // sie teilen — sie stand hier einmal allein und fehlte den beiden anderen Pfaden.
        $types = app(HolderTypeService::class);

        if ($types->affectsAllocation($typeBefore, $this->holderType)
            || $systemBefore !== $this->holder->function_system) {
            $types->redistribute((int) $this->holder->team_id, (int) $this->holder->tenant_id);
        }

        $this->saved = true;
    }

    /**
     * DSGVO-Einzel-Anonymisierung (E2 / ADR 0005): pseudonymisiert die PII dieses Asset-Trägers und der
     * über die UPN verknüpften Geräte/Lizenzen. Owner/Admin-gated, team-scoped. KEINE Auto-Anonymisierung.
     */
    public function anonymize(HolderService $service): void
    {
        Gate::authorize('asset-manager.manage');
        $this->assertTenantBoundary($this->holder);

        $service->anonymize($this->holder);

        $this->holder->refresh();
        $this->displayName = $this->holder->display_name ?? '';
        $this->email       = '';
        $this->anonymized  = true;
        $this->saved       = false;
    }

    /** Öffnet das Mobilfunk-Bearbeiten-Modal und lädt die Felder aus dem aktuellen Stand. */
    public function openMobilfunk(): void
    {
        Gate::authorize('asset-manager.manage');

        $this->mobilePhone    = $this->holder->mobile_phone ?? '';
        $this->businessPhone  = $this->holder->business_phone ?? '';
        $this->simNumber      = $this->holder->sim_number ?? '';
        $this->contractNumber = $this->holder->contract_number ?? '';
        $this->dataVolume     = $this->holder->data_volume ?? '';
        $this->phoneFromEntra = ! $this->holder->phone_overridden;
        $this->showMobilfunk  = true;
    }

    /** Speichert die Mobilfunk-Stammdaten (ADR 0014). phone_overridden schützt manuelle Nummern vor dem Sync. */
    public function saveMobilfunk(): void
    {
        Gate::authorize('asset-manager.manage');

        $this->validate([
            'mobilePhone'    => 'nullable|string|max:64',
            'businessPhone'  => 'nullable|string|max:64',
            'simNumber'      => 'nullable|string|max:64',
            'contractNumber' => 'nullable|string|max:64',
            'dataVolume'     => 'nullable|string|max:64',
            'phoneFromEntra' => 'boolean',
        ]);

        $this->holder->update([
            'mobile_phone'     => $this->mobilePhone ?: null,
            'business_phone'   => $this->businessPhone ?: null,
            'phone_overridden' => ! $this->phoneFromEntra,
            'sim_number'       => $this->simNumber ?: null,
            'contract_number'  => $this->contractNumber ?: null,
            'data_volume'      => $this->dataVolume ?: null,
        ]);

        $this->showMobilfunk = false;
    }

    public function render()
    {
        $teamId = $this->holder->team_id;
        $upn    = $this->holder->user_principal_name;

        // Manuelle/Intune-Items (über assignee_id)
        $items = AssetItem::with('category')
            ->where('team_id', $teamId)
            ->where('assignee_id', $this->holder->id)
            ->orderBy('name')
            ->get();

        // Intune-Devices (legacy, über UPN)
        $devices = AssetDevice::where('team_id', $teamId)
            ->where('user_principal_name', $upn)
            ->orderBy('device_name')
            ->get();

        // Lizenzen (über UPN). `contractLine` mitladen: die Liste zeigt je Seat die Bindung, und ohne
        // Eager-Loading wäre das eine Query je Lizenzzeile.
        $licenses = AssetUserLicense::with('contractLine')
            ->where('team_id', $teamId)
            ->where('user_principal_name', $upn)
            ->orderBy('sku_part_number')
            ->get();

        // Geräteausgaben dieses Asset-Trägers (Übergabeprotokolle, read-only; Anlegen von der Geräteseite).
        $handovers = AssetHandover::with('lines')
            ->where('team_id', $teamId)
            ->where('holder_id', $this->holder->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();

        // SKU-Lookup für Kosten
        $skuIds = $licenses->pluck('sku_id')->unique()->toArray();
        $skuMap = AssetLicenseSku::where('team_id', $teamId)
            ->whereIn('sku_id', $skuIds)
            ->get()
            ->keyBy('sku_id');

        // Geräte-Kosten: gated (nur Kostenart aggregation_source='asset_device') + N+1-frei über den
        // zentralen Aggregator — damit der Asset-Träger-Total mit Dashboard/Pivot übereinstimmt und
        // nicht doppelt zählt. Keyed nach device_id für den Per-Gerät-Betrag in der Liste.
        $deviceRows = app(CostAggregationService::class)->deviceCostRows($teamId)->keyBy('device_id');

        // Monatliche Kosten: gemeinsame Quelle der Wahrheit (identisch mit dem Panel der Liste).
        // $deviceRows wird durchgereicht, damit deviceCostRows() nicht ein zweites Mal läuft.
        $cost = app(CostAggregationService::class)->holderCost($teamId, $this->holder, $deviceRows);

        // Mobilfunk-Kosten dieses Asset-Trägers (ADR 0014): Summe der aktiven Mobilfunk-Kostenpositionen
        // (cost_line, cost_type.key='mobilfunk'). Nur wenn Controlling aktiv — sonst kein Preis im Block.
        // Der Preis lebt in der Kostenposition, NICHT am Asset-Träger (keine Doppelpflege).
        $controllingEnabled = app(ControllingContext::class)->enabledFor($teamId);
        $mobileCost = $controllingEnabled
            ? (float) AssetCostLine::active()->validOn(now())
                ->where('team_id', $teamId)
                ->where('assignee_id', $this->holder->id)
                ->whereHas('costType', fn ($q) => $q->where('key', 'mobilfunk'))
                ->sum('monthly_amount')
            : 0.0;

        return view('asset-manager::livewire.holders.show', [
            'holder'           => $this->holder,
            'items'              => $items,
            'devices'            => $devices,
            'handovers'          => $handovers,
            'deviceRows'         => $deviceRows,
            'licenses'           => $licenses,
            'skuMap'             => $skuMap,
            'hardwareCost'       => $cost['hardware'],
            'deviceCost'         => $cost['device'],
            'licenseCost'        => $cost['license'],
            'totalCost'          => $cost['total'],
            'controllingEnabled' => $controllingEnabled,
            'mobileCost'         => $mobileCost,
            'canManage'          => Gate::allows('asset-manager.manage'),
        ])->layout('platform::layouts.app');
    }
}
