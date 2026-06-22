# Geräte-Identität = Seriennummer; `intune_id` ist eine flüchtige Enrollment-Referenz

Status: akzeptiert (beschlossen 2026-06-22, Umsetzung steht aus)

Kontext: Der Geräte-Sync matcht eingehende Intune-Geräte heute **ausschließlich über `intune_id`** (= Intune `managedDevice`-id), und der Unique-Index ist `(tenant_id, intune_id)`. Wird ein Gerät plattgemacht / neu eingebunden / der Nutzer gewechselt, vergibt Intune eine **neue** `managedDevice`-id. Der Sync findet dann kein bestehendes Gerät → legt einen **neuen** Datensatz an, und reconcile soft-löscht den alten (alte id fehlt in der Antwort). Ergebnis: **ein physisches Gerät = zwei Zeilen** — Lifecycle, Kosten-Override, Garantie/Leasing und Verlauf gehen verloren, sogar das `owner_changed`-Event greift nicht (feuert nur bei *gleicher* id). Das **physische Gerät besteht aber weiter**; nur unser Modell verliert es.

Entscheidung: Die Identität eines Geräts ist die **(normalisierte) Seriennummer je Tenant**. `intune_id` wird zur *aktuellen*, **flüchtigen** Enrollment-Referenz. Der Sync matcht **serial-first** (gefunden → bestehendes Gerät aktualisieren, neue Enrollment-Referenz setzen, Event „neu eingebunden/Nutzer gewechselt"); nur bei fehlender/unbrauchbarer Seriennummer fällt er auf `intune_id` zurück. Der Unique-Index wandert auf `(tenant_id, serial_number)`; `intune_id` wird sekundär. Lifecycle, Kosten-Override, Zuordnungsverlauf und Garantie/Leasing bleiben am stabilen Geräte-Datensatz.

## Bewusste Abgrenzungen / Trade-offs

- **Serial-Normalisierung:** trim + uppercase, bekannte Platzhalter erkennen und als „keine Serial" behandeln (`To be filled by O.E.M.`, `System Serial Number`, `0000000`, leer).
- **Serial-Zuverlässigkeit:** Bei BROICH sind alle Seriennummern durchgängig sauber → der `intune_id`-Fallback ist nur ein Sicherheitsnetz für serial-lose Edge-Cases (VMs, Sonder-Enrollments).
- **Voraussetzung für** Lifecycle-Audit und persistierten Zuordnungsverlauf (sonst hinge die Historie an der flüchtigen `intune_id`).

## Considered Options

- *`intune_id` als Identität beibehalten* — verworfen: verliert das Gerät bei jedem Re-Enrollment/Nutzerwechsel.
- *Entra `deviceId` / Autopilot-Hardware-Hash als Identität* — verworfen: nicht durchgängig verfügbar; die Seriennummer ist provider-übergreifend vorhanden (auch Apple Business Manager) und damit der robustere Anker.
