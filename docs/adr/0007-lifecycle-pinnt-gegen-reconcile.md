# Terminaler Lifecycle pinnt ein Gerät gegen den Reconcile-Delete

Status: akzeptiert und umgesetzt (beschlossen 2026-06-22; gebaut in 8fc6098/0e1bb2f).
Der optionale Marker „zuletzt aus der Quelle verschwunden am …" ist als Verlaufs-Event `removed` (bzw.
`restored` bei Wiederkehr) umgesetzt (2026-10-19, #963), nicht als eigene Spalte.

Kontext: `reconcileDelete()` soft-löscht **jedes** Tenant-Gerät, dessen Schlüssel nicht in der aktuellen Provider-Antwort steht — **ohne** den `lifecycle_status` zu beachten. Ein Admin, der ein Gerät bewusst ausmustert (`retired`), als defekt (`defect`) oder verloren (`lost`) führt und es dabei aus dem MDM entrollt, würde es so beim nächsten Sync **lautlos verlieren**, obwohl er es als Inventar weiterführen will. Umgekehrt ist der manuelle Lifecycle die fachliche Wahrheit, die Provider-Präsenz nur ein Sync-Signal.

Entscheidung: Geräte mit **terminalem Lifecycle (`retired` / `lost` / `defect`)** werden vom Reconcile-Delete **ausgenommen** — sie bleiben als getrackte, nicht mehr gesyncte Inventar-Datensätze erhalten. Geräte mit `in_use` / `spare` / `repair` / ohne Status folgen weiterhin der Provider-Präsenz. Statt eines stillen Soft-Deletes wird (optional) ein abgeleiteter Marker „zuletzt aus Quelle verschwunden am …" geführt, damit sichtbar ist, *warum* ein Gerät nicht mehr aktualisiert wird.

## Bewusste Abgrenzungen / Trade-offs

- **Lifecycle (manuell) = Inventar-Wahrheit, Provider-Präsenz = Sync-Signal.**
- **`spare`-Geräte im Lager**, die aus dem MDM entrollt sind, folgen vorerst weiter der Provider-Präsenz (kein Pin). Sobald eine **Eigentums-Quelle** (Apple Business Manager, ADR 0010) angebunden ist, bleibt ein solches Gerät ohnehin erhalten, weil Reconcile dann **pro Quelle** wirkt und ein Gerät nur entfällt, wenn es aus **allen** seinen Quellen verschwindet (ADR 0009).
