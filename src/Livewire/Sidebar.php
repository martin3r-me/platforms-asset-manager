<?php

namespace Platform\AssetManager\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Platform\AssetManager\Services\ControllingContext;
use Platform\AssetManager\Services\TenantContext;

/**
 * Modul-Sidebar — und der **globale Tenant-Umschalter** (docs/adr/0016).
 *
 * Der Core bettet diese Komponente auf **jeder** Modulseite ein. Genau deshalb lebt der Umschalter
 * hier: vorher steckte er als `@include` im actions-Slot von 10 Inventar-Actionbars und fehlte damit
 * auf Kosten-, Stammdaten- und Detailseiten. Ein Wechsel wirkt jetzt überall.
 *
 * Der Umschalter schreibt **nur** in {@see TenantContext} und lädt neu — das Filtern übernimmt der
 * Global Scope. Unterm Strich weniger Mechanik als die frühere Kombination aus Livewire-Trait,
 * Komponenten-Property und zehn Includes.
 */
class Sidebar extends Component
{
    /** Aktive Auswahl: Tenant-ID, oder self::ALL für „Alle Tenants". */
    public string $selectedTenant = '';

    /** Umschalter-Wert für tenant-übergreifende Auswertungen. */
    public const ALL = 'all';

    public function mount(): void
    {
        $this->selectedTenant = $this->currentSelection();
    }

    /**
     * Auswahl übernehmen und die Seite neu laden.
     *
     * Vollreload statt Livewire-Rerender, weil der Tenant **jede** Komponente der Seite betrifft
     * (Listen, Kacheln, Detailpanels) — ein Teil-Rerender der Sidebar ließe den Rest der Seite auf
     * dem alten Tenant stehen. Genau dieser halbe Zustand war am alten Actionbar-Selektor das Problem.
     */
    public function updatedSelectedTenant(string $value): void
    {
        $teamId = $this->teamId();
        $userId = (int) Auth::id();

        if (! $teamId) {
            return;
        }

        if ($value === self::ALL) {
            TenantContext::setAllTenants($teamId, $userId);
        } elseif ($value !== '') {
            TenantContext::set($teamId, $userId, (int) $value);
        }

        // Aus der Wahrheit re-syncen: set() verwirft fremde/veraltete Werte still (nebenläufig
        // gelöschter Tenant, manipulierter Request) — so bleibt die Anzeige nie auf einem nicht
        // zugehörigen Tenant hängen.
        $this->selectedTenant = $this->currentSelection();

        ControllingContext::forget();

        $this->redirect(request()->header('Referer') ?: route('asset-manager.dashboard'), navigate: true);
    }

    /** Auswahl-Optionen (Default zuerst, dann alphabetisch). */
    public function tenantOptions(): Collection
    {
        $teamId = $this->teamId();

        return $teamId ? TenantContext::tenantsFor($teamId) : collect();
    }

    /**
     * Umschalter nur zeigen, wenn es etwas zu wählen gibt — ab **2 Tenants**.
     *
     * Bewusst nicht „ab 2 Connectoren": ein Manuell-Kunde ohne Microsoft-Anbindung ist ein
     * vollwertiger Tenant und muss anwählbar bleiben.
     */
    public function showTenantSwitcher(): bool
    {
        return $this->tenantOptions()->count() > 1;
    }

    /**
     * Darf „Alle Tenants" auf der aktuellen Seite gewählt werden?
     *
     * Nur Dashboard und Auswertungen — dort weisen die Zahlen den Tenant je Zeile aus. Auf Listen und
     * Detailseiten wäre eine gemischte Ansicht nicht zuordenbar (welcher Kunde hat dieses Gerät?),
     * deshalb fällt der Umschalter dort auf den zuletzt gewählten konkreten Tenant zurück.
     */
    public function allowsAllTenants(): bool
    {
        return request()->routeIs(
            'asset-manager.dashboard',
            'asset-manager.costs',
            'asset-manager.costs.allocation',
            'asset-manager.reports.*',
        );
    }

    public function render()
    {
        // Controlling-Schicht (Auswertungen, Stammdaten, Kosten-Import) ist abschaltbar (ADR 0008),
        // seit ADR 0016 je Team × Tenant.
        $controllingEnabled = false;
        $teamId = $this->teamId();
        if ($teamId) {
            $controllingEnabled = app(ControllingContext::class)->enabledFor($teamId);
        }

        return view('asset-manager::livewire.sidebar', [
            'controllingEnabled' => $controllingEnabled,
            'tenantOptions'      => $this->tenantOptions(),
            'showTenantSwitcher' => $this->showTenantSwitcher(),
            'allowsAllTenants'   => $this->allowsAllTenants(),
        ]);
    }

    /**
     * Aktuelle Auswahl als Select-Wert. „Alle Tenants" nur dort anzeigen, wo es erlaubt ist — sonst
     * den zuletzt gewählten konkreten Tenant, damit Anzeige und tatsächliche Filterung übereinstimmen.
     */
    protected function currentSelection(): string
    {
        $teamId = $this->teamId();
        if (! $teamId) {
            return '';
        }

        $userId = (int) Auth::id();

        if ($this->allowsAllTenants() && TenantContext::prefersAllTenants($teamId, $userId)) {
            return self::ALL;
        }

        return (string) (TenantContext::current($teamId, $userId) ?? '');
    }

    protected function teamId(): ?int
    {
        $id = Auth::user()?->currentTeam?->id;

        return $id ? (int) $id : null;
    }
}
