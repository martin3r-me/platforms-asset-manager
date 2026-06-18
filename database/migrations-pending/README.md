# Vorbereitete, NOCH NICHT aktive Migrationen

Dieser Ordner wird vom ServiceProvider **nicht** geladen (`loadMigrationsFrom` zeigt nur auf
`database/migrations`). Hier liegen Migrationen, die bewusst **später** und **separat** angewendet
werden — nicht im selben Deploy wie der Code, der sie erst möglich macht.

## `2026_06_18_000003_set_tenant_id_not_null.php`

Setzt `tenant_id` auf **NOT NULL** auf den 8 Inventar-Tabellen. **Voraussetzung:**

1. **M3 ist live** — der Tenant-Selektor setzt `tenant_id` auf **allen** Anlage-Pfaden
   (Sync **und** manuelle UI-Anlage wie `Assets/Create`). Solange ein manueller Pfad `tenant_id`
   offen lässt, würde NOT NULL die Anlage mit einer Constraint-Verletzung brechen.
2. **Mindestens ein grüner Sync** lief, und `Model::whereNull('tenant_id')->count() === 0` gilt für
   alle 8 Tabellen (live geprüft).

## Aktivieren

Datei nach `database/migrations/` verschieben, lokal via
`php artisan migrate --path=vendor/martin3r/platform-asset-manager/database/migrations` auf SQLite
smoke-testen (Index-/Spalten-Umbau ist cross-DB heikel), dann normal deployen.
