<?php

namespace Platform\AssetManager\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Platform\AssetManager\Models\AssetAssignment;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetDeviceEvent;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Models\AssetItem;

/**
 * Einzige Schreib-Orchestrierung für Inventar-Objekte.
 *
 * Stateless und bewusst UI-frei: KEINE Gate-/Auth-/Request-/Livewire-Imports. Der aufrufende
 * Livewire-Component prüft Rechte (Gate) und validiert die Eingaben; dieser Service persistiert
 * nur. Damit teilen sich Anlage-Modal (Inventar-Liste) und künftige Edit-Modals (Detailseite)
 * dieselbe Wahrheit, statt die Logik aus Assets/Create + Assets/Show zu duplizieren
 * (Guardrail: Services importieren keine UI/Http/Tools — vgl. tests/guardrails.php).
 *
 * Phase 1: nur `createItem` (manuelle Assets). Geräte-Schreibpfade folgen in Phase 4.
 */
class AssetWriteService
{
    /** Gültige Status eines manuellen Assets. */
    public const ITEM_STATUSES = ['in_stock', 'assigned', 'retired', 'lost'];

    /**
     * Legt ein manuelles Asset an (+ erste Zuordnung, falls ein Asset-Träger gesetzt ist).
     * Quelle: vormals Livewire\Assets\Create::save(). Gate/Validierung liegen beim Aufrufer.
     *
     * @param array{
     *     categoryId:int, name:string, manufacturer?:?string, model?:?string,
     *     serialNumber?:?string, assigneeId?:?int, status?:string, purchaseDate?:?string,
     *     purchasePrice?:?string, depreciationMonths?:?int, notes?:?string
     * } $data
     */
    public function createItem(array $data, int $teamId, int $userId): AssetItem
    {
        $assigneeId = $data['assigneeId'] ?? null;

        // Asset-Träger gesetzt → Status auf „assigned" forcieren + Zuordnungs-Zeitpunkt setzen.
        $status     = $assigneeId ? 'assigned' : ($data['status'] ?? 'in_stock');
        $assignedAt = $assigneeId ? now()      : null;

        $item = AssetItem::create([
            'team_id'             => $teamId,
            'tenant_id'           => TenantContext::resolveForWrite($teamId, $userId),
            'category_id'         => $data['categoryId'],
            'source'              => 'manual',
            'name'                => $data['name'],
            'manufacturer'        => ($data['manufacturer'] ?? '') ?: null,
            'model'               => ($data['model'] ?? '') ?: null,
            'serial_number'       => ($data['serialNumber'] ?? '') ?: null,
            'assignee_id'         => $assigneeId,
            'assigned_at'         => $assignedAt,
            'status'              => $status,
            'purchase_date'       => ($data['purchaseDate'] ?? null) ?: null,
            'purchase_price'      => ($data['purchasePrice'] ?? null) ?: null,
            'depreciation_months' => $data['depreciationMonths'] ?? null,
            'notes'               => ($data['notes'] ?? '') ?: null,
        ]);

        if ($assigneeId) {
            AssetAssignment::create([
                'asset_item_id'   => $item->id,
                'assignable_type' => AssetAssignment::SUBJECT_ITEM,
                'assignable_id'   => $item->id,
                'holder_id'     => $assigneeId,
                'assigned_at'     => now(),
                'source'          => AssetAssignment::SOURCE_MANUAL,
            ]);
        }

        return $item;
    }

    /**
     * Sammelanlage: $quantity gleiche manuelle Assets in einem Rutsch — jedes über {@see createItem()},
     * damit Status-/Zuordnungsregel und Tenant für die Einzel- und die Sammelanlage dieselben sind
     * (vorher schrieb `Assets/Index::createBulk` Item und Zuordnung direkt an diesem Service vorbei).
     * Eine Seriennummer identifiziert genau ein Gerät — bei mehr als einem Stück bleibt sie leer.
     * Alles oder nichts: bricht ein Stück ab, entsteht keins.
     *
     * @param  array<string, mixed>  $data  wie {@see createItem()}
     * @return Collection<int, AssetItem>
     */
    public function createItems(array $data, int $quantity, int $teamId, int $userId): Collection
    {
        $quantity = max(1, $quantity);

        if ($quantity > 1) {
            $data['serialNumber'] = null;
        }

        return DB::transaction(function () use ($data, $quantity, $teamId, $userId) {
            $items = collect();
            for ($i = 0; $i < $quantity; $i++) {
                $items->push($this->createItem($data, $teamId, $userId));
            }

            return $items;
        });
    }

    /**
     * Bearbeiten (Details) eines manuellen Assets. Bei `source=intune` bleiben die Hardware-Felder
     * read-only (nur Status editierbar) — Verzweigung aus Assets/Show::save übernommen.
     * Zuweisung und Kosten laufen über eigene Methoden (assignItem / updateItemDepreciation).
     *
     * @param array{categoryId?:?int, name?:string, manufacturer?:?string, model?:?string, serialNumber?:?string, status?:string} $data
     */
    public function updateItemDetails(AssetItem $item, array $data): void
    {
        // Status über dieselbe Regel wie der Sammelweg — vorher setzte die Detailseite nur das Feld,
        // und ein verlorener Laptop belastete seinen Träger weiter mit AfA (#941).
        if (isset($data['status']) && $data['status'] !== $item->status) {
            $this->setItemStatus($item, $data['status']);
        }

        $payload = [];

        if ($item->source !== 'intune') {
            $payload += [
                'category_id'   => $data['categoryId'] ?? $item->category_id,
                'name'          => $data['name'] ?? $item->name,
                'manufacturer'  => ($data['manufacturer'] ?? '') ?: null,
                'model'         => ($data['model'] ?? '') ?: null,
                'serial_number' => ($data['serialNumber'] ?? '') ?: null,
            ];
        }

        if ($payload !== []) {
            $item->update($payload);
        }
    }

    /**
     * Status eines Assets setzen — die eine Regel für Detailseite und Sammelaktion (#941):
     *
     *  - `retired`, `lost`, `in_stock` lösen eine bestehende Zuordnung (offene Zuordnung wird mit
     *    „jetzt" geschlossen, Träger entfernt). Ein ausgemustertes oder verlorenes Gerät hat keinen
     *    Träger mehr, und ein Gerät im Lager auch nicht.
     *  - `assigned` ohne Träger wird abgelehnt — „zugewiesen an niemanden" gibt es nicht; zuordnen
     *    läuft über {@see assignItem()}.
     *
     * @return bool false, wenn der Status nicht gesetzt wurde (unbekannt oder `assigned` ohne Träger)
     */
    public function setItemStatus(AssetItem $item, string $status): bool
    {
        if (! in_array($status, self::ITEM_STATUSES, true)) {
            return false;
        }

        if ($status === 'assigned') {
            if (! $item->assignee_id) {
                return false;
            }
            $item->update(['status' => 'assigned']);

            return true;
        }

        if ($item->assignee_id) {
            $item->assignments()->whereNull('returned_at')->update(['returned_at' => now(), 'updated_at' => now()]);
            $item->update(['status' => $status, 'assignee_id' => null, 'assigned_at' => null]);
        } else {
            $item->update(['status' => $status]);
        }

        return true;
    }

    /** Abschreibung eines manuellen Assets (Kaufdatum/Kaufpreis/AfA-Monate). */
    public function updateItemDepreciation(AssetItem $item, array $data): void
    {
        $price = $data['purchasePrice'] ?? null;

        $item->update([
            'purchase_date'       => ($data['purchaseDate'] ?? null) ?: null,
            'purchase_price'      => ($price !== null && $price !== '') ? $price : null,
            'depreciation_months' => $data['depreciationMonths'] ?? null,
        ]);
    }

    /** Freitext-Notiz eines manuellen Assets. */
    public function updateItemNotes(AssetItem $item, ?string $notes): void
    {
        $item->update(['notes' => ($notes !== null && $notes !== '') ? $notes : null]);
    }

    /**
     * Manuelles Asset einem Asset-Träger zuordnen (oder ins Lager zurück bei $holder=null).
     * Schließt die offene Zuordnung und schreibt Historie — wie {@see AssetItem::assignTo()}, aber
     * mit optional wählbarem Zeitraum (Gültig ab/bis, E7). $holder MUSS vom Caller team-geprüft sein.
     */
    public function assignItem(AssetItem $item, ?AssetHolder $holder, ?string $validFrom = null, ?string $validUntil = null): void
    {
        // Vorherige offene Zuordnung schließen (Rückgabe = jetzt).
        $item->assignments()->whereNull('returned_at')->update(['returned_at' => now(), 'updated_at' => now()]);

        if ($holder) {
            $assignedAt = $validFrom ?: now();
            $item->update([
                'assignee_id' => $holder->id,
                'assigned_at' => $assignedAt,
                'status'      => 'assigned',
            ]);
            AssetAssignment::create([
                'asset_item_id'   => $item->id,
                'assignable_type' => AssetAssignment::SUBJECT_ITEM,
                'assignable_id'   => $item->id,
                'holder_id'     => $holder->id,
                'assigned_at'     => $assignedAt,
                'returned_at'     => $validUntil ?: null,
                'source'          => AssetAssignment::SOURCE_MANUAL,
            ]);
        } else {
            $item->update(['assignee_id' => null, 'assigned_at' => null, 'status' => 'in_stock']);
        }
    }

    // =====================================================================
    // Intune-Geräte (Phase 4) — Spiegel der Devices/Show-Schreibpfade. Validierung + Gate
    // beim Aufrufer. Leere Strings/0-FKs werden zu null normalisiert (wie Devices/Show).
    // =====================================================================

    /**
     * Fachliche Regeln der Geräte-Kostenfelder — dieselben, die die Detailseite per Livewire prüft
     * (#959). Schlüssel = Spaltennamen; nur übergebene Felder werden geprüft.
     *
     * @param  array<string, mixed>  $fields  monthly_cost, purchase_price, depreciation_months, purchase_date
     * @return array<string, string> Feld → Meldung (leer = ok)
     */
    public static function deviceCostErrors(array $fields): array
    {
        $errors = [];

        foreach (['monthly_cost' => 'Monatsrate', 'purchase_price' => 'Kaufpreis'] as $f => $label) {
            if (array_key_exists($f, $fields) && $fields[$f] !== null && $fields[$f] !== '') {
                if (! is_numeric($fields[$f]) || (float) $fields[$f] < 0) {
                    $errors[$f] = "{$label} muss eine Zahl ab 0 sein.";
                }
            }
        }

        if (array_key_exists('depreciation_months', $fields) && $fields['depreciation_months'] !== null && $fields['depreciation_months'] !== '') {
            $v = $fields['depreciation_months'];
            if (! is_numeric($v) || (int) $v != $v || (int) $v < 1) {
                $errors['depreciation_months'] = 'Abschreibungsdauer muss eine ganze Zahl ab 1 Monat sein.';
            }
        }

        if (array_key_exists('purchase_date', $fields) && $fields['purchase_date'] !== null && $fields['purchase_date'] !== '') {
            try {
                \Carbon\Carbon::parse((string) $fields['purchase_date']);
            } catch (\Throwable) {
                $errors['purchase_date'] = 'Kaufdatum ist kein gültiges Datum (YYYY-MM-DD).';
            }
        }

        return $errors;
    }

    /** Kosten-Override eines Geräts (Leasing-Rate ODER Kauf/AfA + Kostenart/-stelle). */
    public function updateDeviceCost(AssetDevice $device, array $data): void
    {
        $device->update([
            'monthly_cost'        => $this->blankToNull($data['monthly'] ?? null),
            'purchase_price'      => $this->blankToNull($data['purchase'] ?? null),
            'depreciation_months' => ($data['dep'] ?? null) ?: null,
            'purchase_date'       => ($data['purchaseDate'] ?? null) ?: null,
            'cost_type_id'        => ($data['costType'] ?? null) ?: null,
            'cost_center_id'      => ($data['costCenter'] ?? null) ?: null,
        ]);
    }

    /**
     * Lifecycle/Beschaffung eines Geräts. Schreibt bei Status-Wechsel ein `lifecycle_changed`-Audit
     * mit Akteur ($userId) — identisch zu Devices/Show::saveLifecycle (Track B 2a, ADR 0007).
     * Intune liefert den Lifecycle nicht; er wird manuell gepflegt → „wer/wann" sonst nicht nachvollziehbar.
     */
    public function updateDeviceLifecycle(AssetDevice $device, array $data, ?int $userId = null): void
    {
        $oldStatus = $device->lifecycle_status;

        $device->update([
            'lifecycle_status' => ($data['status'] ?? null) ?: null,
            'warranty_until'   => ($data['warranty'] ?? null) ?: null,
            'lease_until'      => ($data['lease'] ?? null) ?: null,
            'vendor_id'        => ($data['vendor'] ?? null) ?: null,
            'order_no'         => ($data['orderNo'] ?? null) ?: null,
            'order_date'       => ($data['orderDate'] ?? null) ?: null,
            'location'         => ($data['location'] ?? null) ?: null,
        ]);
        $device->refresh();

        $newStatus = $device->lifecycle_status;
        if ($oldStatus !== $newStatus) {
            AssetDeviceEvent::record(
                $device,
                'lifecycle_changed',
                'Lifecycle-Status geändert',
                AssetDevice::lifecycleLabelFor($oldStatus),
                AssetDevice::lifecycleLabelFor($newStatus),
                $userId,
            );
        }
    }

    /** Operative Freitext-Notiz eines Geräts. */
    public function updateDeviceNotes(AssetDevice $device, ?string $notes): void
    {
        $device->update(['notes' => ($notes !== null && $notes !== '') ? $notes : null]);
    }

    /** Leerer String/null → null (sonst Wert unverändert; "0" bleibt erhalten wie in Devices/Show). */
    private function blankToNull($value)
    {
        return ($value === '' || $value === null) ? null : $value;
    }
}
