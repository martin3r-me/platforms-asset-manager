<?php

namespace Platform\AssetManager\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Platform\AssetManager\Services\TenantContext;

/**
 * Livewire-Trait für die Inventar-Sichten (M3): hält den aktiven Tenant als Property, lädt ihn beim
 * Mount aus dem TenantContext und persistiert jeden Wechsel wieder dorthin. Weil $selectedTenantId
 * eine Property DERSELBEN Komponente ist, rendert Livewire nach einem Wechsel automatisch neu —
 * kein Cross-Component-Event nötig.
 *
 * Der Selektor wird nur bei ≥2 Tenants angezeigt; bei genau einem Tenant filtern die Views still
 * auf diesen (selectedTenantId = dessen ID). Bei null filtert `forTenant` nicht (team-weit).
 */
trait ScopesToTenant
{
    public ?int $selectedTenantId = null;

    /** Pro-Request memoisierte Dropdown-Optionen (Partial fragt sie zweimal ab). Nicht persistiert. */
    protected ?Collection $tenantOptionsCache = null;

    /** Livewire-Trait-Hook: aktiven Tenant aus der gespeicherten Auswahl (oder Default) laden. */
    public function mountScopesToTenant(): void
    {
        $this->selectedTenantId = TenantContext::current($this->tenantTeamId(), $this->tenantUserId());
    }

    /** Wechsel dauerhaft speichern; bei paginierten Listen die Seite zurücksetzen. */
    public function updatedSelectedTenantId($value): void
    {
        $tenantId = ($value === null || $value === '') ? null : (int) $value;

        if ($tenantId !== null) {
            TenantContext::set($this->tenantTeamId(), $this->tenantUserId(), $tenantId);
        }

        // IMMER aus der Wahrheit re-syncen: TenantContext::set() verwirft fremde/veraltete Werte
        // (z. B. nebenläufig gelöschter Tenant, manipulierter Request). So bleibt die View nie auf
        // einem nicht zugehörigen Tenant „leer" hängen, sondern fällt auf Auswahl/Default zurück.
        $this->selectedTenantId = TenantContext::current($this->tenantTeamId(), $this->tenantUserId());

        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }
    }

    /** Dropdown-Optionen (Default zuerst), pro Request memoisiert. */
    public function tenantOptions(): Collection
    {
        return $this->tenantOptionsCache ??= TenantContext::tenantsFor($this->tenantTeamId());
    }

    /** Selektor nur zeigen, wenn es etwas zu wählen gibt (≥2 Tenants). */
    public function showTenantSelector(): bool
    {
        return $this->tenantOptions()->count() > 1;
    }

    protected function tenantTeamId(): int
    {
        return (int) Auth::user()->currentTeam->id;
    }

    protected function tenantUserId(): int
    {
        return (int) Auth::id();
    }
}
