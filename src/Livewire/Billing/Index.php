<?php

namespace Platform\AssetManager\Livewire\Billing;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Concerns\AuthorizesTeamRole;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Models\AssetBillingProfile;
use Platform\AssetManager\Models\AssetBillingRun;
use Platform\AssetManager\Services\BillingGateway;
use Platform\AssetManager\Services\BillingRunService;
use Platform\AssetManager\Services\TenantContext;
use Throwable;

/**
 * Weiterberechnung: Abrechnungsprofile pflegen und daraus Rechnungsentwürfe erzeugen
 * (siehe docs/adr/0021).
 *
 * Die Seite ist bewusst zweigeteilt. Oben die **Regel** (an wen, wofür, für wen), unten der
 * **Lauf** — erst als Vorschau, die nichts sendet, dann als Entwurf. Dass man die Zahl sieht, bevor
 * irgendetwas nach draußen geht, ist der ganze Punkt: eine Kopfpauschale ist ein Produkt aus zwei
 * Zahlen, und wenn eine davon falsch ist, fällt es sonst erst dem Kunden auf.
 */
class Index extends Component
{
    use ResolvesCurrentTeam;
    use AuthorizesTeamRole;

    /** Profil, dessen Lauf gerade betrachtet wird. */
    public ?int $selectedProfileId = null;

    public string $period = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    /** Übersprungene Träger sind standardmäßig eingeklappt — sie sind eine Begründung, kein Inhalt. */
    public bool $showSkipped = false;

    /** Aufstellung der abzurechnenden Träger — hinter der Kachel, nicht daneben. */
    public bool $showBillable = false;

    public ?string $flash = null;

    public ?string $error = null;

    // ---- Formularfelder ----------------------------------------------------------------------

    public string $fName = '';

    public ?string $fCustomerId = null;

    public string $fCustomerName = '';

    public string $fOrderNumber = '';

    public ?string $fDueInDays = null;

    public string $fSku = '';

    public ?string $fFallbackPrice = null;

    public string $fBasis = AssetBillingProfile::BASIS_LICENSED;

    public string $fExcludeDomains = '';

    public ?string $fVat = '19';

    public bool $fActive = true;

    public function mount(BillingRunService $runs): void
    {
        $this->period = $runs->normalizePeriod(null);

        $this->selectedProfileId = AssetBillingProfile::query()
            ->where('team_id', $this->teamId())
            ->where('active', true)
            ->value('id');
    }

    protected function canManage(): bool
    {
        return $this->isTeamOwnerOrAdmin(Auth::user());
    }

    protected function activeTenantId(): int
    {
        return TenantContext::resolveForWrite($this->teamId(), (int) Auth::id());
    }

    // ---- Profil pflegen ----------------------------------------------------------------------

    public function startCreate(): void
    {
        abort_unless($this->canManage(), 403);

        $this->resetForm();
        $this->showForm = true;
    }

    public function startEdit(int $id): void
    {
        abort_unless($this->canManage(), 403);

        $profile = $this->profileOrFail($id);

        $this->editingId       = $profile->id;
        $this->fName           = $profile->name;
        $this->fCustomerId     = $profile->easybill_customer_id ? (string) $profile->easybill_customer_id : null;
        $this->fCustomerName   = (string) $profile->easybill_customer_name;
        $this->fOrderNumber    = (string) $profile->order_number;
        $this->fDueInDays      = $profile->due_in_days !== null ? (string) $profile->due_in_days : null;
        $this->fSku            = (string) $profile->commerce_sku;
        $this->fFallbackPrice  = $profile->fallback_unit_price_cents !== null
            ? number_format($profile->fallback_unit_price_cents / 100, 2, '.', '')
            : null;
        $this->fBasis          = $profile->basis;
        $this->fExcludeDomains = implode(', ', $profile->normalizedExcludeDomains());
        $this->fVat            = $profile->vat_percent !== null ? (string) $profile->vat_percent : null;
        $this->fActive         = (bool) $profile->active;
        $this->showForm        = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function saveProfile(BillingGateway $gateway): void
    {
        abort_unless($this->canManage(), 403);

        $this->validate([
            'fName'          => 'required|string|max:255',
            'fCustomerId'    => 'nullable|numeric',
            'fSku'           => 'nullable|string|max:64',
            'fFallbackPrice' => 'nullable|numeric|min:0',
            'fBasis'         => 'required|in:' . implode(',', AssetBillingProfile::BASES),
            'fVat'           => 'nullable|numeric|min:0|max:100',
            'fOrderNumber'   => 'nullable|string|max:255',
            'fDueInDays'     => 'nullable|integer|min:0|max:365',
        ], [], [
            'fName'          => 'Name',
            'fCustomerId'    => 'easybill-Kundennummer',
            'fSku'           => 'Artikelnummer',
            'fFallbackPrice' => 'Rückfallpreis',
            'fBasis'         => 'Zählregel',
            'fVat'           => 'Steuersatz',
            'fOrderNumber'   => 'Auftragsnummer',
            'fDueInDays'     => 'Zahlungsziel',
        ]);

        // Eingegeben wird meist die Kundennummer vom easybill-Kundenblatt (6010200), gebraucht wird
        // die interne ID (2636491004). Hier aufzulösen ist der einzige Moment, in dem ein Mensch
        // danebensteht — beim Rechnungslauf wäre daraus ein „Kunde nicht gefunden" ohne Hinweis
        // darauf, welche der beiden Zahlen gemeint war.
        $customerId   = $this->fCustomerId !== null && $this->fCustomerId !== '' ? (int) $this->fCustomerId : null;
        $customerName = $this->fCustomerName ?: null;

        if ($customerId !== null && $gateway->isAvailable()) {
            $customer = $gateway->findCustomer((string) $this->fCustomerId);

            if ($customer === null) {
                $this->addError('fCustomerId', 'In easybill nicht gefunden — weder als Kunden-ID noch als Kundennummer.');

                return;
            }

            $customerId = $customer['id'];
            // Bezeichnung nur füllen, wenn nichts eingetragen ist: eine bewusst gewählte behält Vorrang.
            $customerName ??= $customer['name'] ?: null;
        }

        $domains = collect(preg_split('/[\s,;]+/', $this->fExcludeDomains) ?: [])
            ->map(fn ($d) => strtolower(trim($d)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $attributes = [
            'name'                   => $this->fName,
            'easybill_customer_id'   => $customerId,
            'easybill_customer_name' => $customerName,
            'order_number'           => $this->fOrderNumber ?: null,
            'due_in_days'            => $this->fDueInDays !== null && $this->fDueInDays !== ''
                ? (int) $this->fDueInDays
                : null,
            'commerce_sku'           => $this->fSku ?: null,
            // Der Rückfallpreis wird in Euro eingegeben und in Cent gehalten — die Umrechnung
            // gehört an die Eingabe, damit im Rest des Ablaufs nur noch Cent vorkommt.
            'fallback_unit_price_cents' => $this->fFallbackPrice !== null && $this->fFallbackPrice !== ''
                ? (int) round(((float) $this->fFallbackPrice) * 100)
                : null,
            'basis'           => $this->fBasis,
            'exclude_domains' => $domains ?: null,
            'vat_percent'     => $this->fVat !== null && $this->fVat !== '' ? (int) $this->fVat : null,
            'active'          => $this->fActive,
        ];

        if ($this->editingId) {
            $this->profileOrFail($this->editingId)->update($attributes);
            $this->flash = 'Profil gespeichert.';
        } else {
            $profile = AssetBillingProfile::create($attributes + [
                'team_id'   => $this->teamId(),
                'tenant_id' => $this->activeTenantId(),
                'currency'  => 'EUR',
            ]);

            $this->selectedProfileId = $profile->id;
            $this->flash = 'Profil angelegt.';
        }

        $this->cancelForm();
    }

    public function selectProfile(int $id): void
    {
        $this->selectedProfileId = $this->profileOrFail($id)->id;
        $this->showSkipped  = false;
        $this->showBillable = false;
        $this->flash = null;
        $this->error = null;
    }

    // ---- Lauf --------------------------------------------------------------------------------

    public function createDraft(BillingRunService $runs): void
    {
        abort_unless($this->canManage(), 403);

        $profile = $this->profileOrFail((int) $this->selectedProfileId);

        $this->flash = null;
        $this->error = null;

        try {
            $run = $runs->createDraft($profile, $this->period);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->flash = 'Entwurf angelegt (easybill #' . $run->easybill_document_id . '). '
            . 'Festschreiben und Versenden passieren in easybill.';
    }

    protected function profileOrFail(int $id): AssetBillingProfile
    {
        return AssetBillingProfile::query()
            ->where('team_id', $this->teamId())
            ->findOrFail($id);
    }

    protected function resetForm(): void
    {
        $this->editingId       = null;
        $this->fName           = '';
        $this->fCustomerId     = null;
        $this->fCustomerName   = '';
        $this->fOrderNumber    = '';
        $this->fDueInDays      = null;
        $this->fSku            = '';
        $this->fFallbackPrice  = null;
        $this->fBasis          = AssetBillingProfile::BASIS_LICENSED;
        $this->fExcludeDomains = '';
        $this->fVat            = '19';
        $this->fActive         = true;
        $this->resetValidation();
    }

    public function render(BillingRunService $runs, BillingGateway $gateway)
    {
        $teamId = $this->teamId();

        $profiles = AssetBillingProfile::query()
            ->where('team_id', $teamId)
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        $selected = $this->selectedProfileId
            ? $profiles->firstWhere('id', $this->selectedProfileId)
            : null;

        $preview = $selected ? $runs->preview($selected, $this->period) : null;

        $recentRuns = $selected
            ? AssetBillingRun::query()
                ->withoutTenantScope()
                ->where('billing_profile_id', $selected->id)
                ->latest('id')
                ->limit(12)
                ->get()
            : collect();

        // Einmal ermitteln, nicht je Zeile: das Gate cacht seine Callbacks nicht, und `currentTeam`
        // ist ein ungecachter Accessor mit eigener Query.
        $canManage = $this->canManage();

        return view('asset-manager::livewire.billing.index', [
            'profiles'          => $profiles,
            'selected'          => $selected,
            'preview'           => $preview,
            'recentRuns'        => $recentRuns,
            'canManage'         => $canManage,
            'gatewayAvailable'  => $gateway->isAvailable(),
            'gatewayReason'     => $gateway->unavailableReason(),
            'periodOptions'     => $this->periodOptions($runs),
        ])->layout('platform::layouts.app');
    }

    /** Laufender Monat und die elf davor — weiter zurück zu rechnen ergibt bei einer Pauschale keinen Sinn. */
    protected function periodOptions(BillingRunService $runs): array
    {
        $options = [];
        $cursor  = now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $key = $cursor->format('Y-m');
            $options[$key] = $runs->periodLabel($key);
            $cursor->subMonthNoOverflow();
        }

        return $options;
    }
}
