<?php

namespace Platform\AssetManager\Console\Commands;

use Platform\AssetManager\Jobs\ImportTenantUsersJob;
use Platform\AssetManager\Models\AssetConnectorConfig;
use Illuminate\Console\Command;

/**
 * Verzeichnis-Abgleich der Asset-Träger — planbar statt auf Klick.
 *
 * **Warum es diesen Command gibt.** Zwei Dinge passieren ausschließlich im
 * {@see ImportTenantUsersJob}: `accountEnabled` wird auf `is_active` abgebildet, und der Reconcile
 * legt Träger still, die das Verzeichnis nicht mehr kennt (docs/adr/0023). Beides hing bis hierher
 * an einem Klick auf der Konnektoren-Seite bzw. am MCP-Tool `asset-manager.sync.POST` mit
 * `target=users` — der Job hatte weder Zeitplan noch Kommando.
 *
 * Das ist keine Kosmetik: die **Zählregel der Weiterberechnung sieht nur aktive Träger**
 * ({@see \Platform\AssetManager\Services\BillableHolderResolver}). Klickt niemand, zählt der
 * Rechnungslauf ausgeschiedene Träger weiter mit — und dieser Fehler erscheint nicht als Meldung,
 * sondern als falscher Betrag auf einer Kundenrechnung. ADR 0023 formuliert das Stilllegen als
 * Folge der Entscheidung, nicht als Handarbeit; erst der Zeitplan löst das ein.
 *
 * **Abgrenzung.** Abteilung, Position, Rufnummern und Träger-Typ veralten auch ohne diesen Lauf
 * nicht: der reguläre Lizenz-Sync reichert bei jedem Durchgang an
 * ({@see \Platform\AssetManager\Jobs\SyncLicensesJob}, `applyGraphProfile()`) und
 * {@see \Platform\AssetManager\Services\HolderService} klassifiziert bei jedem Treffer. Es hängt
 * genau das Stilllegen — deshalb läuft dieser Command **vor** dem Lizenz-Sync (Zeitplan im
 * ServiceProvider): der Lizenz-Sync arbeitet dann auf einem Bestand, dessen Ausgeschiedene bereits
 * stillgelegt sind.
 *
 * **Consent-Prüfung wie bei den Geschwister-Commands.** Ohne bestätigten Consent liefert Graph
 * ohnehin nichts; ein Lauf ohne Consent hinterließe nur einen `sync_error` am Connector. Wir
 * überspringen ihn sichtbar, statt ihn ins Leere laufen zu lassen — dasselbe Verhalten wie
 * {@see SyncLicensesCommand}.
 *
 * Die drei Sicherungen des Reconcile liegen im Job und gelten hier unverändert: eine leere
 * Verzeichnis-Antwort legt **nichts** still, angefasst werden nur Träger mit `source='graph'`, und
 * nur bereits aktive.
 */
class ImportTenantUsersCommand extends Command
{
    protected $signature = 'asset-manager:import-users {--team= : Nur ein bestimmtes Team importieren}';

    protected $description = 'Importiert die Verzeichnis-User je Connector, reichert die Asset-Träger an und legt Ausgeschiedene still (ADR 0023)';

    public function handle(): int
    {
        $teamId = $this->option('team');

        $query = AssetConnectorConfig::where('enabled', true);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        $configs = $query->get();

        if ($configs->isEmpty()) {
            $this->info('Keine aktiven Connectors gefunden.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($configs as $config) {
            if (!$config->isConfigured()) {
                $this->warn("Connector {$config->id} (Team {$config->team_id}): nicht vollständig konfiguriert, übersprungen.");
                continue;
            }

            if (!$config->isConsentConfirmed()) {
                $this->warn("Connector {$config->id} (Team {$config->team_id}): Consent ausstehend, übersprungen.");
                continue;
            }

            // Der Job selbst prüft den Tenant nochmals — hier vorgezogen, damit der Grund in der
            // Ausgabe steht und nicht nur im Log eines stillen Abbruchs landet.
            if (!$config->tenant_id) {
                $this->warn("Connector {$config->id} (Team {$config->team_id}): kein Tenant zugeordnet, übersprungen.");
                continue;
            }

            ImportTenantUsersJob::dispatch($config->id);
            $dispatched++;
            $this->info("Connector {$config->id} (Team {$config->team_id}): Verzeichnis-Abgleich dispatched.");
        }

        if ($dispatched === 0) {
            $this->warn('Kein Connector war abgleichbereit — kein Job dispatched.');
        }

        return self::SUCCESS;
    }
}
