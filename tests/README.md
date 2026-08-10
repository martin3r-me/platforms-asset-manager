# Tests — Asset Manager

Dieses Verzeichnis enthält **zwei voneinander unabhängige** Test-Ebenen.

## 1. `tests/guardrails.php` — lokaler, framework-freier Statik-Guard

Reines PHP, **ohne** Laravel-/Core-Bootstrap. Läuft von überall:

```bash
php tests/guardrails.php
```

Prüft Architektur-Invarianten statisch (Tool-Registrierung vollständig, Abhängigkeitsrichtung
Models/Services → keine UI/Tools/Http, keine Blade-Alias-Manglung). Exit 0 = grün, 1 = verletzt.
Bleibt die einzige Sache, die **im Modul-Repo selbst** ausführbar ist.

## 2. `tests/Feature/**` — Host-Feature-Tests (post-deploy)

PHPUnit-Klassen, die `Tests\TestCase` der **Host-App** erweitern und `RefreshDatabase` nutzen. Sie
brauchen den vollen Core-Bootstrap (`AUTH_MODEL='Platform\Core\Models\User'`, `team_user`-Pivot,
Migrationen aller Module, Gate `asset-manager.manage`) und sind daher **im Modul-Repo nicht
ausführbar** — nur in der eingebundenen App (z. B. `demo.bhgdigital.de`) nach dem Deploy.

| Datei | Deckt ab |
|---|---|
| `CostLineScopingTest` | M2 Cross-Team-FK-Ablehnung (Kostenart/Kreditor fremdes Team) + M3 Member-vs-Owner-Gating |
| `ExcelImportIdempotencyTest` | **End-to-End** über `CostExcelImportService::import()` mit im Test erzeugter `.xlsx` (Tenant-Stempel auf allen Schreibpfaden, Doppellauf ohne Dubletten, Ziel-Tenant ≠ Default, Baum-Auflösung der Kostenstellen-Codes) **plus** die Formel-Invarianten (`import_hash`-Stabilität, Prune + Empty-Set-Guard, `CostResetService`) |
| `GraphReconcileDeleteTest` | M4 Reconcile-Soft-Delete, Empty-Keyset-Guard, Tenant-Scope, withTrashed-Restore mit Kosten-Override |
| `CostReconciliationTest` | Invariante `totalMonthly()['total'] === costCenterByType()['grandTotal']` — flach **und** auf einem dreistufigen Kostenstellen-Baum (S5): kein Doppelzählen im `grandTotal`/`colTotals`, Rollup je Knoten, Auffangzeilen (ohne/unbekannte Kostenstelle), Tenant-Isolation zweier Kostenstrukturen |

### Factory-Autoloading

Die Tests bauen Fixtures über Modell-Factories (`AssetCostLine::factory()`, …). Damit die Host-App
die Package-Factories findet:

1. **PSR-4-Mapping** in `composer.json` des Moduls:
   `"Platform\\AssetManager\\Database\\Factories\\": "database/factories/"`.
2. Jedes der sechs Kern-Modelle (`AssetHolder`, `AssetDevice`, `AssetCostLine`, `AssetCostType`,
   `AssetCostCenter`, `AssetTenant`) bindet `HasFactory` ein und hat einen `newFactory()`-Resolver,
   der die zugehörige `…Factory` zurückgibt (statt Laravels Default-Namens-Auflösung
   `Database\Factories\<Model>Factory`, die für ein Package nicht greift).

`HasFactory` + `newFactory()` sind in Produktion inert — sie wirken nur, wenn `::factory()` in Tests
gerufen wird.

### Ausführen (in der Host-App, nach Deploy)

```bash
# nur die Asset-Manager-Feature-Tests
php artisan test vendor/martin3r/platform-asset-manager/tests/Feature
```

Wird das Paket per Composer eingebunden, muss die Host-App `tests/Feature` ggf. in ihre
`phpunit.xml`-Testsuite aufnehmen (oder den Pfad wie oben direkt adressieren). Die Klassen liegen im
Namespace `Platform\AssetManager\Tests\Feature`.

### Bekannte host-abhängige Stellen (`TODO(host)`)

- **TeamFactory-Pflichtfelder:** Die Tests legen Teams über `Team::factory()` an. Verlangt die
  Host-`TeamFactory` Pflichtfelder (z. B. `user_id`), in den `makeTeam()`/`Team::factory()`-Aufrufen
  ergänzen.
- **Echte .xlsx-Fixture:** erledigt. `ExcelImportIdempotencyTest::writeXlsx()` baut die Mappe im Test
  über `ZipArchive` — der Reader ist selbst geschrieben, wir kontrollieren also beide Seiten. Braucht
  `ext-zip`/`ext-simplexml` (im `composer.json` deklariert).

### Warum der End-to-End-Import wichtig ist

Solange der Importer im Test nie lief, blieb unbemerkt, dass er seit S1 (`tenant_id NOT NULL`) in
eine Constraint-Verletzung lief: `import()` setzte den Ziel-Tenant und überschrieb ihn sechs Zeilen
später wieder mit `null` — ein Überbleibsel aus der Zeit, als das Feld ein Memo-Cache war. Die
Formel-Invarianten waren dabei durchgehend grün, weil sie die Hash-Berechnung **nachbauen**, statt
den Service zu rufen. Neue Tests für diesen Bereich sollten deshalb immer mindestens einen Pfad
haben, der `import()` wirklich ausführt.
