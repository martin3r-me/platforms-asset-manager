# Vorbereitete, NOCH NICHT aktive Migrationen

Dieser Ordner wird vom ServiceProvider **nicht** geladen (`loadMigrationsFrom` zeigt nur auf
`database/migrations`). Hier liegen Migrationen, die bewusst **später** und **separat** angewendet
werden — nicht im selben Deploy wie der Code, der sie erst möglich macht.

Derzeit liegt hier keine Migration. Die frühere `2026_06_18_000003_set_tenant_id_not_null.php` ist
entfernt (#880): die Verschärfung lief längst über `database/migrations/2026_08_07_000004_tenant_id_not_null.php`
(14 Tabellen, mit bewussten Ausnahmen), und die alte Datei nannte noch `asset_employees` — eine Tabelle,
die es seit der Umbenennung in `asset_holders` nicht mehr gibt.

## Aktivieren (künftige Dateien)

Datei nach `database/migrations/` verschieben, lokal auf SQLite smoke-testen (Index-/Spalten-Umbau ist
cross-DB heikel), dann normal deployen.
