<?php

namespace Platform\AssetManager\Livewire\Handovers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Platform\AssetManager\Concerns\ResolvesCurrentTeam;
use Platform\AssetManager\Concerns\ScopesToTenant;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetEmployee;
use Platform\AssetManager\Models\AssetHandover;
use Platform\AssetManager\Models\AssetHandoverLine;

/**
 * Geräteausgaben — globale Liste + personen-first Mehrgeräte-Editor (eine Unterschrift je Protokoll).
 * Schreiblogik liegt zentral hier; Geräte-/Mitarbeiterseite verlinken nur hierher (Deep-Link
 * ?device=ID&new=1 bzw. ?employee=ID). Owner/Admin-gated (ADR 0004), team-/tenant-scoped.
 */
class Index extends Component
{
    use ResolvesCurrentTeam;
    use WithPagination;
    use ScopesToTenant;

    public string $search       = '';
    public string $filterStatus = ''; // ''|open|partially_returned|returned

    // Editor
    public bool  $showEditor    = false;
    public ?int  $editId        = null;
    public bool  $includeIssued = false; // belegte Geräte (mit offener Ausgabe) im Picker zeigen

    // Kopf-Formular
    public ?int    $fEmployeeId    = null;
    public string  $fIssuedAt      = '';
    public string  $fSignerName    = '';
    public ?string $fSignatureData = null;
    public string  $fNotes         = '';

    /** Zeilen: [['id'=>?int, 'device_id'=>?int, 'accessories'=>string, 'notes'=>string,
     *            'returned_at'=>?string, 'return_condition'=>?string], …] */
    public array $fLines = [];

    // Rückgabe einer Zeile (inline)
    public ?int   $returningLineId = null;
    public string $returnCondition = '';

    public ?string $flash = null;

    protected $queryString = [
        'search'       => ['except' => ''],
        'filterStatus' => ['except' => ''],
    ];

    public function mount(): void
    {
        $new      = (bool) request()->boolean('new');
        $deviceId = request()->integer('device') ?: null;
        $empId    = request()->integer('employee') ?: null;

        if ($new || $deviceId || $empId) {
            $this->startNew($deviceId, $empId);
        }
    }

    public function updatingSearch(): void       { $this->resetPage(); }
    public function updatingFilterStatus(): void { $this->resetPage(); }

    // ---- Editor öffnen -------------------------------------------------------------------------

    public function newHandover(): void
    {
        $this->startNew();
    }

    protected function startNew(?int $deviceId = null, ?int $empId = null): void
    {
        $this->resetEditor();
        $teamId = $this->teamId();

        // Gerät vorbefüllen (Geräte-Shortcut): Empfänger = aktueller Intune-Assignee.
        if ($deviceId) {
            $device = AssetDevice::where('team_id', $teamId)->forTenant($this->selectedTenantId)->find($deviceId);
            if ($device) {
                $this->fLines = [$this->emptyLine($device->id)];
                if (! $empId && $device->user_principal_name) {
                    $assignee = AssetEmployee::where('team_id', $teamId)
                        ->where('user_principal_name', $device->user_principal_name)
                        ->first();
                    $empId = $assignee?->id;
                }
            }
        }

        if ($empId) {
            $emp = AssetEmployee::where('team_id', $teamId)->find($empId);
            $this->fEmployeeId = $emp?->id;
        }

        if (empty($this->fLines)) {
            $this->fLines = [$this->emptyLine()];
        }

        $this->fIssuedAt  = now()->toDateString();
        $this->showEditor = true;
        $this->dispatch('open-activity');
    }

    public function edit(int $id): void
    {
        $teamId   = $this->teamId();
        $handover = AssetHandover::where('team_id', $teamId)->with('lines')->findOrFail($id);

        $this->resetEditor();
        $this->editId         = $handover->id;
        $this->fEmployeeId    = $handover->employee_id;
        $this->fIssuedAt      = $handover->issued_at?->format('Y-m-d') ?? '';
        $this->fSignerName    = $handover->signer_name ?? '';
        $this->fSignatureData = $handover->signature_data;
        $this->fNotes         = $handover->notes ?? '';

        $this->fLines = $handover->lines->map(fn (AssetHandoverLine $l) => [
            'id'               => $l->id,
            'device_id'        => $l->asset_device_id,
            'device_label'     => $l->deviceName() . ($l->serialNumber() ? ' · ' . $l->serialNumber() : ''),
            'accessories'      => is_array($l->accessories) ? implode(', ', $l->accessories) : '',
            'notes'            => $l->notes ?? '',
            'returned_at'      => $l->returned_at?->format('Y-m-d'),
            'return_condition' => $l->return_condition,
        ])->values()->all();

        if (empty($this->fLines)) {
            $this->fLines = [$this->emptyLine()];
        }

        $this->showEditor = true;
        $this->dispatch('open-activity');
    }

    // ---- Zeilen im Editor ----------------------------------------------------------------------

    public function addLine(): void
    {
        $this->fLines[] = $this->emptyLine();
    }

    public function removeLine(int $i): void
    {
        // Persistierte (gespeicherte) Zeilen werden nicht über den Editor entfernt — nur über Rückgabe.
        if (! empty($this->fLines[$i]['id'])) {
            return;
        }
        unset($this->fLines[$i]);
        $this->fLines = array_values($this->fLines);
        if (empty($this->fLines)) {
            $this->fLines = [$this->emptyLine()];
        }
    }

    protected function emptyLine(?int $deviceId = null): array
    {
        return [
            'id'               => null,
            'device_id'        => $deviceId,
            'accessories'      => '',
            'notes'            => '',
            'returned_at'      => null,
            'return_condition' => null,
        ];
    }

    // ---- Speichern -----------------------------------------------------------------------------

    public function save(): void
    {
        Gate::authorize('asset-manager.manage');
        $teamId = $this->teamId();

        $this->validate([
            'fEmployeeId'         => ['required', 'integer', Rule::exists('asset_employees', 'id')->where('team_id', $teamId)],
            'fIssuedAt'           => 'nullable|date',
            'fSignerName'         => 'nullable|string|max:255',
            'fNotes'              => 'nullable|string|max:2000',
            'fLines'              => 'required|array|min:1',
            'fLines.*.device_id'  => ['required', 'integer', Rule::exists('asset_devices', 'id')->where('team_id', $teamId)],
        ], [], [
            'fEmployeeId'        => 'Empfänger',
            'fLines.*.device_id' => 'Gerät',
        ]);

        // Kein Gerät doppelt im selben Protokoll.
        $deviceIds = collect($this->fLines)->pluck('device_id')->filter()->map(fn ($v) => (int) $v);
        if ($deviceIds->count() !== $deviceIds->unique()->count()) {
            $this->addError('fLines', 'Ein Gerät darf im selben Protokoll nur einmal vorkommen.');
            return;
        }

        $employee = AssetEmployee::where('team_id', $teamId)->findOrFail($this->fEmployeeId);

        $isNew = $this->editId === null;
        $signatureJustAdded = $this->fSignatureData
            && (! $this->editId || ! AssetHandover::where('team_id', $teamId)->find($this->editId)?->signature_data);

        $closed = [];

        DB::transaction(function () use ($teamId, $employee, $signatureJustAdded, &$closed) {
            $header = [
                'team_id'            => $teamId,
                'tenant_id'          => $employee->tenant_id,
                'employee_id'        => $employee->id,
                'issued_at'          => $this->fIssuedAt ?: null,
                'signer_name'        => $this->fSignerName ?: null,
                'signature_data'     => $this->fSignatureData ?: null,
                'signed_at'          => $signatureJustAdded ? now() : null,
                'notes'              => $this->fNotes ?: null,
            ];

            if ($this->editId) {
                $handover = AssetHandover::where('team_id', $teamId)->findOrFail($this->editId);
                // signed_at nur überschreiben, wenn gerade neu unterschrieben wurde.
                if (! $signatureJustAdded) {
                    unset($header['signed_at']);
                }
                $handover->update($header);
            } else {
                $header['created_by_user_id'] = Auth::id();
                $header['status']             = AssetHandover::STATUS_OPEN;
                $handover = AssetHandover::create($header);
            }

            foreach ($this->fLines as $row) {
                $accessories = $this->parseAccessories($row['accessories'] ?? '');
                $notes       = ($row['notes'] ?? '') !== '' ? $row['notes'] : null;

                if (! empty($row['id'])) {
                    // Bestehende, noch nicht zurückgegebene Zeile: nur Zubehör/Notiz aktualisieren.
                    $line = $handover->lines()->where('id', $row['id'])->whereNull('returned_at')->first();
                    $line?->update(['accessories' => $accessories, 'notes' => $notes]);
                    continue;
                }

                $device = AssetDevice::where('team_id', $teamId)->find($row['device_id']);
                if (! $device) {
                    continue;
                }

                // Invariante "eine offene Ausgabe je Gerät": vorherige offene Zeile(n) dieses Geräts schließen.
                $priorOpen = AssetHandoverLine::whereHas('handover', fn ($q) => $q->where('team_id', $teamId))
                    ->where('asset_device_id', $device->id)
                    ->whereNull('returned_at')
                    ->get();

                foreach ($priorOpen as $p) {
                    $p->update([
                        'returned_at'         => now()->toDateString(),
                        'status'              => AssetHandoverLine::STATUS_RETURNED,
                        'returned_by_user_id' => Auth::id(),
                    ]);
                    AssetDeviceEvent::record($device, 'returned', 'Automatisch zurückgebucht (Neu-Ausgabe)', userId: Auth::id());
                    $p->handover?->recomputeStatus();
                    $closed[] = $device->device_name ?? ('#' . $device->id);
                }

                $handover->lines()->create([
                    'asset_device_id' => $device->id,
                    'accessories'     => $accessories,
                    'notes'           => $notes,
                    'device_snapshot' => AssetHandoverLine::captureDeviceSnapshot($device),
                    'status'          => AssetHandoverLine::STATUS_ISSUED,
                ]);
                AssetDeviceEvent::record($device, 'issued', 'Ausgegeben an ' . ($employee->display_name ?: $employee->user_principal_name) . ' (Protokoll #' . $handover->id . ')', userId: Auth::id());
            }

            $handover->load('lines');
            $handover->recomputeStatus();
        });

        $this->flash = ($isNew ? 'Ausgabe angelegt.' : 'Ausgabe gespeichert.')
            . (count($closed) ? ' Vorherige Ausgabe(n) automatisch zurückgebucht: ' . implode(', ', array_unique($closed)) . '.' : '');

        $this->resetEditor();
        $this->showEditor = false;
    }

    // ---- Rückgabe einer Zeile ------------------------------------------------------------------

    public function startReturn(int $lineId): void
    {
        $this->returningLineId = $lineId;
        $this->returnCondition = '';
    }

    public function cancelReturn(): void
    {
        $this->returningLineId = null;
        $this->returnCondition = '';
    }

    public function confirmReturn(): void
    {
        Gate::authorize('asset-manager.manage');
        $teamId = $this->teamId();

        $line = AssetHandoverLine::whereHas('handover', fn ($q) => $q->where('team_id', $teamId))
            ->whereNull('returned_at')
            ->find($this->returningLineId);

        if ($line) {
            $line->update([
                'returned_at'         => now()->toDateString(),
                'return_condition'    => $this->returnCondition ?: null,
                'returned_by_user_id' => Auth::id(),
                'status'              => AssetHandoverLine::STATUS_RETURNED,
            ]);

            if ($device = $line->device) {
                AssetDeviceEvent::record($device, 'returned', 'Zurückgenommen (Protokoll #' . $line->handover_id . ')', userId: Auth::id());
            }
            $line->handover?->recomputeStatus();
            $this->flash = 'Gerät als zurückgegeben markiert.';
        }

        $this->cancelReturn();

        // Editor ggf. aktualisieren, wenn dieses Protokoll offen ist.
        if ($this->editId && $line) {
            $this->edit($this->editId);
        }
    }

    public function delete(int $id): void
    {
        Gate::authorize('asset-manager.manage');

        $handover = AssetHandover::where('team_id', $this->teamId())->findOrFail($id);
        $handover->delete(); // cascade: Zeilen
        if ($this->editId === $id) {
            $this->resetEditor();
            $this->showEditor = false;
        }
        $this->flash = 'Ausgabe gelöscht.';
    }

    public function cancelEdit(): void
    {
        $this->resetEditor();
        $this->showEditor = false;
        $this->resetValidation();
    }

    protected function resetEditor(): void
    {
        $this->reset(['editId', 'fEmployeeId', 'fSignerName', 'fSignatureData', 'fNotes', 'fLines', 'returningLineId', 'returnCondition']);
        $this->fIssuedAt = now()->toDateString();
    }

    /** Zubehör-Freitext (kommagetrennt) → bereinigtes Array. */
    protected function parseAccessories(string $text): array
    {
        return collect(explode(',', $text))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }

    public function render()
    {
        $teamId = $this->teamId();

        $handovers = AssetHandover::where('team_id', $teamId)
            ->forTenant($this->selectedTenantId)
            ->with(['employee', 'lines'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, function ($q) {
                $term = '%' . $this->search . '%';
                $q->where(function ($w) use ($term) {
                    $w->where('signer_name', 'like', $term)
                      ->orWhereHas('employee', fn ($e) => $e->where('display_name', 'like', $term)
                          ->orWhere('user_principal_name', 'like', $term))
                      ->orWhereHas('lines.device', fn ($d) => $d->where('device_name', 'like', $term)
                          ->orWhere('serial_number', 'like', $term));
                });
            })
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(25);

        // Geräte des aktiven Tenants + Set der aktuell ausgegebenen (offene Zeile).
        $devices = AssetDevice::where('team_id', $teamId)
            ->forTenant($this->selectedTenantId)
            ->orderBy('device_name')
            ->get(['id', 'device_name', 'serial_number', 'model', 'user_principal_name', 'tenant_id']);

        $openDeviceIds = AssetHandoverLine::whereHas('handover', fn ($q) => $q->where('team_id', $teamId))
            ->whereNull('returned_at')
            ->pluck('asset_device_id')
            ->unique()
            ->values()
            ->all();

        $employees = AssetEmployee::where('team_id', $teamId)
            ->forTenant($this->selectedTenantId)
            ->orderByRaw('COALESCE(display_name, user_principal_name)')
            ->get(['id', 'display_name', 'user_principal_name']);

        return view('asset-manager::livewire.handovers.index', [
            'handovers'     => $handovers,
            'devices'       => $devices,
            'openDeviceIds' => $openDeviceIds,
            'employees'     => $employees,
        ])->layout('platform::layouts.app');
    }
}
