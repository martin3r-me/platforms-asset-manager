# Tests — Asset Manager

Dieses Verzeichnis enthält **drei voneinander unabhängige** Test-Ebenen.

## 1. `tests/guardrails.php` — lokaler, framework-freier Statik-Guard

Reines PHP, **ohne** Laravel-/Core-Bootstrap. Läuft von überall:

```bash
php tests/guardrails.php
```

Prüft Architektur-Invarianten statisch — neun Checks:

1. **Tool-Registrierung vollständig** — jede `src/Tools/**/*Tool.php` steht in
   `AssetManagerServiceProvider::registerTools()` (kein Auto-Discovery).
2. **Abhängigkeitsrichtung** — nichts in `src/Models`/`src/Services` importiert Livewire/Tools/Http.
3. **Kein Blade-Alias-Mangling** — keine `<livewire:…>`-/`@livewire('…')`-Stringform, nur `@livewire(Klasse::class)`.
4. **Eager-Loads existieren** — jeder in `with()`/`load()` genannte Relationsname ist eine echte Relation.
5. **Modell-Kosten mit Kostenart + Kreditor geladen** (kein nacktes `with('cost')` in unpaginierten Listen).
6. **Kostenauflösung je Instanz** (`AssetDevice::resolvedMonthlyCost()` & Co.) nur auf Einzel-Objekt-Pfaden;
   Listen/Aggregationen bauen einen `DeviceCostResolver` vorab.
7. **Team-Reset vollständig** — jedes Model mit `team_id` wird von `AssetResetService` gelöscht oder steht
   mit Grund in der Ausnahmeliste des Checks (#953).
8. **Design-Tokens definiert** — jedes in einer Blade benutzte `var(--am-*)` ist in
   `resources/views/components/theme.blade.php` definiert (#893; Tippfehler-Tokens lösen sonst still zu nichts auf).
9. **MCP-Tools: Tenant + Rolle** — jedes Tool löst den Tenant über `resolveTenant()` auf, jedes schreibende Tool
   prüft das Gate `asset-manager.manage` gegen das Kontext-Team (#976, ADR 0004/0016; Legacy-Aliase ausgenommen).

Exit 0 = grün, 1 = verletzt. Die einzige Ebene, die **ganz ohne Host-App** läuft (in `tests/local`
gilt das nur für `table-view-state.php` und `pivot-grid.mjs`).

## 2. `tests/local/**` — Logik- und Blade-Prüfungen gegen eine Host-App als Library

Zwischenstufe zwischen 1 und 3: die Skripte booten eine **vorhandene** Host-App (z. B.
`demo.bhgdigital.de`) als Library und legen ihr Schema in einer SQLite-In-Memory-DB an. Damit ist
Fachlogik prüfbar, ohne auf den Deploy zu warten — aber es ist **keine** zweite Testsuite und
ersetzt die Feature-Tests nicht.

42 Prüfskripte (41 × PHP, 1 × Node) plus `blade-lint.php` und den gemeinsamen `bootstrap.php`.
Aufruf jeweils aus dem Modul-Root, z. B. `php tests/local/cost-lines-grouping.php`:

```bash
# Kostenpositionen, Kostenaufteilung, Stammdaten
php tests/local/cost-lines-grouping.php       # gruppierte Ansicht, Kennzahlen, Auswahl, Export
php tests/local/cost-lines-editor.php         # Editor-Pfad inkl. Test-Vertrag von CostLineScopingTest
php tests/local/cost-line-write.php           # CostLineWriteService: tenant_id, Pflichtfelder, Teil-Update, strikte Kostenart (#938)
php tests/local/cost-line-reassign.php        # CostLineReassignService (UI + MCP-Tool teilen ihn)
php tests/local/cost-center-delete.php        # Kostenstelle löschen: Sperre bei Einstellungs-Rollen, dry_run (#966)
php tests/local/decimal-input.php             # Betragseingaben: Komma/Punkt, Tausender, €, Ungültiges → null (Support\DecimalInput, #958)
php tests/local/table-view-state.php          # anpassbare Tabellen-Ansicht: Zustand, Sanitizing, Faltung (ohne Host-App)
php tests/local/table-view-service.php        # deren Speicherpfad (Scoping, Pruefen beim Schreiben)
node tests/local/pivot-grid.mjs               # deren Browser-Logik (CSS-Regel, Zaehler, Drop-Ziel)
php tests/local/list-state-clamp.php          # Listen-Zustand aus URL/Client geklemmt (Concerns\ClampsListState, #957)
php tests/local/n-plus-1.php                  # Query-Budgets der Listen + Geraete-Modell-Zuordnung
php tests/local/team-role-memo.php            # Memoisierung von TeamRole::isOwnerOrAdmin() (Gate pro Zeile)
php tests/local/plausibility-foreign-source.php # Plausibilitätsregel: Position auf Kostenart mit fremder Quelle (#899)
php tests/local/tenant-isolation.php          # Global Scope über ALLE TenantScopable-Models + bewusster Ausstieg (#901, ADR 0016)
php tests/local/mcp-tools-smoke.php           # MCP-Tools im echten Aufruf: Rechte, tenant_id, fremder Tenant, Lese-Tools (#976)

# Geräte & Inventar
php tests/local/device-columns.php            # Spaltenreihenfolge der Intune-Geräteliste (neue Spalten nicht ans Ende)
php tests/local/device-cost-rules.php         # Regeln der Geräte-Kostenfelder, gleich in UI und MCP (#959)
php tests/local/device-cost-visibility.php    # Plausibilitätsregel device_cost_not_aggregated (stumme 0-€-Geräte)
php tests/local/device-serial-dedupe.php      # Doppel-Enrollment: SyncIntuneDevicesJob::dedupeBySerial() (#945)
php tests/local/item-status.php               # Status manueller Assets, Zuordnung lösen (#941)
php tests/local/handover-coverage.php         # Abdeckung der Übergabeprotokolle (HandoverCoverageService, #887, ADR 0028)

# Asset-Träger & Verzeichnis
php tests/local/holder-backfill.php           # Träger-Backfill nach den Syncs: keine Dubletten, klassifiziert, idempotent (#939)
php tests/local/holder-lifecycle.php          # Papierkorb-UPNs, Dubletten zusammenführen, Stilllegen (ADR 0023)
php tests/local/holder-type-bulk.php          # Trägertyp-Sammelaktion auf Service-Ebene (HolderTypeService)
php tests/local/holders-bulk-type.php         # Trägerliste: filteredQuery(), Mehrfachauswahl, Gates
php tests/local/import-users-schedule.php     # Verzeichnis-Abgleich: Kommando registriert, im Zeitplan, VOR dem Lizenz-Sync

# Rechnungsimporte & Monatsstände
php tests/local/vodafone-invoice-validity.php # Mobilfunk zaehlt auch nach Ende des Rechnungszeitraums (Bug #930)
php tests/local/fixednet-invoice-csv.php      # Festnetz-"Rechnung CSV": Bloecke, Rabatt-Zuordnung, SaaS-Erkennung (#931)
php tests/local/telephony-seat-import.php     # RingCentral-Benutzerliste: Abgleich, Snapshot, zweite Durchwahl (#931)
php tests/local/fixednet-invoice-import.php   # Festnetz-Import: Plaetze/Pool, Sammelstelle, Excel-Abloesung, Prune je Konto (#931)
php tests/local/license-invoice-import.php    # Lizenz-Rechnung: Preisableitung, Zuordnung, Verteilungs-Kaskade, Preisbuch (ADR 0019)
php tests/local/license-contract-insights.php # Vertragszeilen: Überhang-Ersparnis + Kündigungs-Radar (#882, #883)
php tests/local/invoice-snapshot.php          # Monatsstand je Rechnung (Buchungsmonat, Verteilung je KST)

# Weiterberechnung (easybill)
php tests/local/billing-run.php               # Zählregel, Preisermittlung, Beleg-Aufbau (Cent), Lauf-Protokoll (ADR 0021)
php tests/local/billing-exclusion.php         # die drei Stufen der Zählregel, Ausnahme mit Grund (ADR 0024)
php tests/local/billing-attachment.php        # User-Pauschale (PDF): Snapshot-Zeilen, Namen nur ergänzen

# DATEV-Umbuchung (ADR 0027)
php tests/local/datev-reallocation-calculator.php # Rechner: Quell-/Zielzeilen je KST, S/H, Gegenkonto
php tests/local/datev-reallocation-service.php    # Block-Bildung je Monat aus den Monatsständen (Largest Remainder)
php tests/local/datev-extf-writer.php             # EXTF-Datei + Checkliste, Buchungstext
php tests/local/datev-batch.php                   # Stapel-Protokoll: Schutz gegen Doppel-Umbuchung
php tests/local/datev-header-settings.php         # Stapelkopf in den Moduleinstellungen
php tests/local/datev-reallocation-page.php       # Seite „DATEV-Umbuchung" (Livewire ohne Runtime)

# Blade
php tests/local/blade-lint.php resources/views/livewire/cost-lines/*.blade.php
```

Die Host-App wird aufwärts gesucht (Verzeichnis mit `artisan` + `vendor/autoload.php` +
`bootstrap/app.php`); abweichender Pfad über `AM_HOST_APP=…`. Modul-Klassen kommen per
vorgeschaltetem Autoloader aus dem **lokalen** `src/`, nicht aus `vendor/` der Host-App — das
funktioniert auch aus einem Worktree.

**`blade-lint.php`** kompiliert Blade echt zu PHP und lintet das Ergebnis. Reine `@if`/`@endif`-Zähler
reichen nicht: ein nicht erkanntes Direktiv oder ein unbalanciertes `<x-…>`-Tag fällt erst beim
Kompilieren auf und legt die Seite sonst live mit HTTP 500 lahm.

**Grenzen, die man kennen muss:**

- SQLite ist bei `GROUP BY` **lax** — MySQLs `ONLY_FULL_GROUP_BY` wird hier **nicht** aufgedeckt.
  Label-Spalten in Aggregat-Queries deshalb immer bewusst mit-gruppieren.
- Die Modul-Migrationen laufen nicht mit; jedes Skript legt nur die Spalten an, die es anfasst.
  Ein neues Feld muss dort ergänzt werden, sonst schlägt der Insert fehl.
- Der Blade-Lint prüft **nicht**, ob eine Komponente oder ein Heroicon existiert (das löst Blade
  erst zur Laufzeit auf) und nicht, ob die verwendeten Variablen gesetzt sind.
- Es gibt keinen authentifizierten User. Schreibpfade laufen deshalb ins Gate — die Skripte weisen
  das zuerst nach und setzen dann `Gate::before(fn (?object $u = null) => true)`. Der **nullable**
  Parameter ist Pflicht: Laravel ruft before-Callbacks für Gäste nur auf, wenn der erste Parameter
  `null` zulässt.

## 3. `tests/Feature/**` — Host-Feature-Tests (post-deploy)

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
| `LifecycleAuditTest` | Lifecycle-Änderung über die Inventar-Detailseite schreibt ein `lifecycle_changed`-Event mit Akteur; kein Event ohne Änderung |
| `SerialIdentityTest` | ADR 0006: Re-Enrollment mit gleicher Seriennummer aktualisiert dieselbe Zeile (Intune-id rotiert), serial-lose Geräte fallen auf `intune_id` zurück, eine Intune-Quelle je Gerät, Zuordnungs-Zeiträume aus der UPN |
| `VodafoneInvoiceImportTest` | ADR 0018: Rufnummern-Normalisierung, Zuordnung zu Trägern, ungematchte Nummern markiert, Preisänderung aktualisiert dieselbe Zeile, entfallene Nummern entfernt, Kostenstellen-Abweichung gemeldet, Excel-Mobilfunk abgelöst, Dry-Run schreibt nichts |

### Factory-Autoloading

Die Tests bauen Fixtures über Modell-Factories (`AssetCostLine::factory()`, …). Damit die Host-App
die Package-Factories findet:

1. **PSR-4-Mapping** in `composer.json` des Moduls:
   `"Platform\\AssetManager\\Database\\Factories\\": "database/factories/"`.
2. Jedes der acht Modelle mit Factory (`AssetHolder`, `AssetDevice`, `AssetCostLine`, `AssetCostType`,
   `AssetCostCenter`, `AssetTenant`, `AssetHandover`, `AssetHandoverLine` — Factories unter
   `database/factories/`) bindet `HasFactory` ein und hat einen `newFactory()`-Resolver,
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
