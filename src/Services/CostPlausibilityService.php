<?php

namespace Platform\AssetManager\Services;

use Platform\AssetManager\Models\AssetCostCenter;
use Platform\AssetManager\Models\AssetCostLine;
use Platform\AssetManager\Models\AssetCostType;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetHolder;
use Platform\AssetManager\Support\CostBootstrap;
use Platform\AssetManager\Support\DeviceCostResolver;

/**
 * Plausibilitätsprüfung der Kostenpositionen — die Kostenaufteilung rechnet, aber sie prüft sich nicht.
 *
 * Anlass waren drei Funde, die alle nur durch Zufall auffielen:
 *
 *  - 29 RingCentral-Seats, die Geld kosten und keiner Person gehören (darunter ein Betrag, der exakt
 *    24 × Einzelpreis ist — ein nicht aufgeteilter Sammelposten)
 *  - „BPEvent" hing mit über 3.400 €/Monat an den Kostenstellen `SUMME` und `Typ` — Kopf- und
 *    Summenzeilen der Excel, die der Importer als Stammdaten angelegt hatte
 *  - „Lap+Dock" stand vollständig auf monatlich statt quartalsweise, rund 2.950 €/Monat zu hoch
 *
 * Alle drei sind erkennbar, ohne die Fachdomäne zu kennen: sie widersprechen dem, was im Datenmodell
 * schon steht (`allocation_level` der Kostenart, der Kostenstellen-Baum, die Frequenz der übrigen
 * Zeilen derselben Kostenart). Genau das prüft dieser Service.
 *
 * **Bewusst nur lesend und heuristisch.** Jeder Befund ist ein Hinweis mit Begründung und
 * Beispielzeilen, keine automatische Korrektur — die Beträge sind echtes Geld, und eine Heuristik,
 * die selbständig aufräumt, richtet mehr Schaden an als der Fehler, den sie sucht.
 *
 * Abgrenzung zu {@see CostAggregationService::anomalies()}: dort geht es um *Bestands*-Auffälligkeiten
 * (Hardware im Lager, ungenutzte Lizenzen). Hier geht es um die *Datenqualität der Kostenpositionen*.
 */
class CostPlausibilityService
{
    /** Maximal so viele Beispielzeilen je Befund — die Liste soll lesbar bleiben, nicht vollständig sein. */
    public const MAX_EXAMPLES = 15;

    /**
     * Alle Prüfungen ausführen. Reihenfolge = absteigende Dringlichkeit.
     *
     * @return array{findings: list<array<string, mixed>>, checked: int, total_monthly_flagged: float}
     */
    public function check(int $teamId): array
    {
        $lines = AssetCostLine::where('team_id', $teamId)
            ->where('active', true)
            ->with(['costType', 'costCenter', 'assignee'])
            ->get();

        $findings = array_values(array_filter([
            $this->perPersonWithoutHolder($lines),
            $this->costCenterLevelWithHolder($lines),
            $this->costOnInactiveHolders($teamId, $lines),
            $this->suspiciousCostCenters($teamId, $lines),
            $this->undividedBlocks($lines),
            $this->mixedFrequencies($lines),
            $this->duplicates($lines),
            $this->devicesWithSilentCost($teamId),
        ]));

        return [
            'checked'               => $lines->count(),
            'findings'              => $findings,
            // Beträge NICHT über Befunde summieren: dieselbe Zeile kann in mehreren auftauchen
            // (ein Sammelposten ohne Träger trifft zwei Regeln). Eine Summe wäre schlicht falsch.
            'total_monthly_flagged' => round(
                $lines->whereIn('id', collect($findings)->flatMap(fn ($f) => $f['line_ids'])->unique()->all())
                    ->sum('monthly_amount'),
                2,
            ),
        ];
    }

    // ---- Prüfungen -------------------------------------------------------------------------

    /**
     * Bezugsebene ist `person`, die Position hat aber keinen Träger.
     *
     * Die schärfste Regel, weil sie nichts rät: die Kostenart selbst legt die Ebene fest. Eine solche
     * Position ohne Träger ist entweder ein Sammelposten, der aufgeteilt gehört, oder ein Seat, den
     * niemand nutzt und den trotzdem jemand bezahlt.
     */
    protected function perPersonWithoutHolder($lines): ?array
    {
        $hits = $lines->filter(fn ($l) => $l->assignee_id === null && (bool) $l->costType?->isPerPerson());

        return $this->finding(
            $hits,
            key: 'per_person_without_holder',
            severity: 'high',
            title: 'Bezugsebene Person, aber kein Asset-Träger zugeordnet',
            explanation: 'Diese Kostenart fällt laut Stammsatz je Person an, die Position hängt aber an niemandem. '
                . 'Typischerweise ein nicht aufgeteilter Sammelposten oder ein Seat, den niemand mehr nutzt.',
        );
    }

    /**
     * Die Gegenrichtung: Bezugsebene ist `cost_center`, die Position hängt trotzdem an einer Person.
     *
     * Das ist der Fall, der ohne die Ebene gar nicht auffiel. Kosten, die einer Kostenstelle
     * zugeschlüsselt sind (Internet, Drucker, BPEvent, team-weite Abos), gehören keinem Menschen —
     * hängt dort ein Träger dran, erscheinen die Beträge in „Kosten je Asset-Träger" und verzerren
     * genau die Auswertung, für die diese Sicht gemacht ist.
     */
    protected function costCenterLevelWithHolder($lines): ?array
    {
        $hits = $lines->filter(
            fn ($l) => $l->assignee_id !== null && $l->costType !== null && ! $l->costType->isPerPerson(),
        );

        return $this->finding(
            $hits,
            key: 'cost_center_level_with_holder',
            severity: 'medium',
            title: 'Bezugsebene Kostenstelle, aber einer Person zugeordnet',
            explanation: 'Diese Kostenart wird nur einer Kostenstelle zugeschlüsselt und gehört keiner Person. '
                . 'Die Zuordnung an einen Asset-Träger lässt die Kosten in der Auswertung je Person auftauchen, '
                . 'wo sie nicht hingehören — entweder ist die Zuordnung zu löschen oder die Bezugsebene falsch gesetzt.',
        );
    }

    /** Laufende Kosten auf Trägern, die als inaktiv geführt sind — klassischer Offboarding-Rest. */
    protected function costOnInactiveHolders(int $teamId, $lines): ?array
    {
        $inactive = AssetHolder::where('team_id', $teamId)->where('is_active', false)->pluck('id')->flip();
        $hits     = $lines->filter(fn ($l) => $l->assignee_id !== null && $inactive->has($l->assignee_id));

        return $this->finding(
            $hits,
            key: 'cost_on_inactive_holder',
            severity: 'high',
            title: 'Kosten laufen auf inaktive Asset-Träger',
            explanation: 'Der Träger ist als inaktiv markiert, die Kostenposition läuft weiter. '
                . 'Beim Offboarding wurde die Leistung vermutlich nicht gekündigt.',
        );
    }

    /**
     * Kostenstellen, die keine sind: oberste Knoten, deren Code weder numerisch noch einer der
     * bekannten Gruppen-Slugs ist. So entstehen Import-Artefakte — `SUMME` und `Typ` sind eine
     * Summen- und eine Kopfzeile der Excel, die als Stammdaten angelegt wurden.
     */
    protected function suspiciousCostCenters(int $teamId, $lines): ?array
    {
        $known = array_keys(CostBootstrap::COST_CENTER_GROUPS);

        $suspicious = AssetCostCenter::where('team_id', $teamId)
            ->whereNull('parent_id')
            ->get()
            ->filter(fn (AssetCostCenter $c) => ! is_numeric($c->code) && ! in_array($c->code, $known, true))
            ->pluck('id')
            ->flip();

        if ($suspicious->isEmpty()) {
            return null;
        }

        $hits = $lines->filter(fn ($l) => $l->cost_center_id !== null && $suspicious->has($l->cost_center_id));

        return $this->finding(
            $hits,
            key: 'suspicious_cost_center',
            severity: 'high',
            title: 'Kosten auf einer fragwürdigen Kostenstelle',
            explanation: 'Diese obersten Knoten tragen weder einen numerischen Code noch einen bekannten '
                . 'Gruppen-Schlüssel. Fast immer sind das Kopf- oder Summenzeilen, die ein Import als '
                . 'Kostenstelle angelegt hat — die daran hängenden Beträge sind dann meist doppelt gezählt.',
        );
    }

    /**
     * Beträge, die ein glattes Vielfaches des Einzelpreises derselben Kostenart sind.
     *
     * Beispiel: 67 Positionen zu 17,95 € und eine zu 430,80 € — das sind 24 × 17,95, also ein Block,
     * der nie auf Personen aufgeteilt wurde. Bewusst konservativ: der Einzelpreis muss mindestens
     * fünfmal vorkommen, damit er als Referenz taugt, und das Vielfache muss auf den Cent aufgehen.
     */
    protected function undividedBlocks($lines): ?array
    {
        $hits = collect();

        foreach ($lines->groupBy('cost_type_id') as $group) {
            $counts = $group->countBy(fn ($l) => (string) round((float) $l->amount, 2));
            $unit   = $counts->filter(fn ($n) => $n >= 5)->keys()->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0)->min();

            if ($unit === null) {
                continue;
            }

            foreach ($group as $line) {
                $amount = round((float) $line->amount, 2);
                if ($amount <= $unit) {
                    continue;
                }
                $factor = $amount / $unit;
                if (abs($factor - round($factor)) < 0.005 && round($factor) >= 2) {
                    $line->setAttribute('plausibility_hint', round($factor) . ' × ' . number_format($unit, 2, ',', '.') . ' €');
                    $hits->push($line);
                }
            }
        }

        return $this->finding(
            $hits,
            key: 'undivided_block',
            severity: 'medium',
            title: 'Sammelposten — Betrag ist ein glattes Vielfaches des Einzelpreises',
            explanation: 'Der Betrag entspricht exakt n × dem üblichen Einzelpreis dieser Kostenart. '
                . 'Vermutlich ein Block, der nie auf die einzelnen Personen aufgeteilt wurde — '
                . 'in der Auswertung je Person fehlen diese Kosten damit.',
        );
    }

    /**
     * Eine Kostenart, deren Positionen unterschiedliche Frequenzen tragen.
     *
     * Genau so sah „Lap+Dock" aus, bevor es auffiel: alle Positionen monatlich, obwohl der Vertrag
     * quartalsweise abrechnet. Wäre eine einzige Zeile richtig gepflegt gewesen, hätte diese Prüfung
     * angeschlagen. Einmalkosten (`once`) sind ausgenommen — die stehen legitim neben laufenden.
     */
    protected function mixedFrequencies($lines): ?array
    {
        $hits = collect();
        $notes = [];

        foreach ($lines->groupBy('cost_type_id') as $typeId => $group) {
            $recurring = $group->where('frequency', '!=', 'once');
            $byFreq    = $recurring->countBy('frequency');

            if ($byFreq->count() < 2) {
                continue;
            }

            // Die Minderheit ist die interessante Seite: entweder sie ist falsch, oder sie ist die
            // einzige richtig gepflegte Zeile und der Rest ist falsch.
            $majority = $byFreq->sortDesc()->keys()->first();
            $notes[$typeId] = $byFreq->map(fn ($n, $f) => "{$n}× {$f}")->values()->implode(', ');

            foreach ($recurring->where('frequency', '!=', $majority) as $line) {
                $line->setAttribute('plausibility_hint', 'Kostenart sonst: ' . $notes[$typeId]);
                $hits->push($line);
            }
        }

        return $this->finding(
            $hits,
            key: 'mixed_frequency',
            severity: 'medium',
            title: 'Kostenart mit uneinheitlicher Frequenz',
            explanation: 'Innerhalb einer Kostenart stehen verschiedene Abrechnungsfrequenzen nebeneinander. '
                . 'Eine der beiden Seiten ist falsch gepflegt — und weil die Frequenz den Monatsbetrag '
                . 'bestimmt (quartalsweise = ein Drittel), verschiebt das die Auswertung deutlich.',
        );
    }

    /** Dieselbe Kostenart, derselbe Träger, derselbe Betrag — mehr als einmal. */
    protected function duplicates($lines): ?array
    {
        $hits = $lines
            ->filter(fn ($l) => $l->assignee_id !== null)
            ->groupBy(fn ($l) => $l->cost_type_id . '|' . $l->assignee_id . '|' . round((float) $l->amount, 2) . '|' . $l->frequency)
            ->filter(fn ($g) => $g->count() > 1)
            ->flatten(1);

        return $this->finding(
            $hits,
            key: 'duplicate_line',
            severity: 'medium',
            title: 'Mögliche Dublette',
            explanation: 'Gleiche Kostenart, gleicher Träger, gleicher Betrag, gleiche Frequenz — mehrfach vorhanden. '
                . 'Kann legitim sein (zwei Geräte, zwei Anschlüsse), ist aber häufig ein doppelter Import.',
        );
    }

    /**
     * Geräte, die einen Preis tragen, dessen aufgelöste Kostenart aber nicht `asset_device` ist —
     * ihre Kosten erscheinen in KEINER Auswertung.
     *
     * Die einzige Regel hier, die nicht auf Kostenpositionen schaut. Sie existiert, weil dieser Fall
     * **stumm** ist: {@see CostAggregationService::deviceCostRows()} zählt ein Gerät nur, wenn seine
     * aufgelöste Kostenart `aggregation_source='asset_device'` hat (das verhindert Doppelzählung mit
     * den importierten Positionen derselben Kostenart). Fehlt diese Quelle, bleibt der Hardware-Bucket
     * bei 0 — ohne Fehler, ohne Hinweis, obwohl an jedem Gerät ein Preis steht. Genau so lagen 69
     * Laptops mit hinterlegter Leasingrate monatelang mit 0 € in der Kostenaufteilung.
     *
     * Das Gegenstück (Kostenart ist `asset_device`, aber kein Gerät trägt einen Preis) braucht keine
     * Regel: dort fällt die fehlende Summe sofort auf.
     */
    protected function devicesWithSilentCost(int $teamId): ?array
    {
        $deviceTypeIds = AssetCostType::where('team_id', $teamId)
            ->where('aggregation_source', AssetCostType::SOURCE_ASSET_DEVICE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $resolver  = DeviceCostResolver::for($teamId);
        $typeNames = AssetCostType::where('team_id', $teamId)->pluck('name', 'id');

        // Feldnamen wie bei den cost_line-Befunden (label/cost_type/holder/hint/monthly) — die Anzeige
        // rendert für alle Befunde dieselbe Tabelle, ein eigenes Vokabular ergäbe dort leere Spalten.
        $hits = AssetDevice::where('team_id', $teamId)->get()
            ->map(function (AssetDevice $d) use ($resolver, $deviceTypeIds, $typeNames) {
                $monthly = $resolver->monthlyCost($d);
                if ($monthly <= 0) {
                    return null;
                }

                $typeId = $resolver->costTypeId($d);
                if ($typeId !== null && $deviceTypeIds->contains($typeId)) {
                    return null; // zählt korrekt mit
                }

                $model = trim($d->manufacturer . ' ' . $d->model);

                return [
                    'device_id' => $d->id,
                    'label'     => $d->device_name ?: ($d->serial_number ?: ('Gerät #' . $d->id)),
                    'cost_type' => $typeId !== null ? ($typeNames[$typeId] ?? null) : null,
                    'holder'    => $d->user_principal_name,
                    'monthly'   => round($monthly, 2),
                    'hint'      => ($typeId === null
                        ? 'keine Kostenart hinterlegt'
                        : 'Quelle der Kostenart ist nicht „Geräte-Kosten"')
                        . ($model !== '' ? ' · ' . $model : ''),
                ];
            })
            ->filter()
            ->values();

        if ($hits->isEmpty()) {
            return null;
        }

        return [
            'key'         => 'device_cost_not_aggregated',
            'severity'    => 'high',
            'title'       => 'Geräte tragen einen Preis, der in keiner Auswertung landet',
            'explanation' => 'Diese Geräte haben eine Monatsrate (eigener Override oder Modell-Default), ihre '
                . 'Kostenart hat aber nicht die Quelle „Geräte-Kosten" — oder es ist keine hinterlegt. '
                . 'Gerätekosten werden nur mit dieser Quelle gezählt, sonst bleiben sie stumm außen vor. '
                . 'Zu prüfen: Quelle der Kostenart in den Stammdaten umstellen (Vorsicht: importierte '
                . 'Positionen derselben Kostenart fallen dann bewusst aus der Summe) oder dem Gerät die '
                . 'richtige Kostenart geben.',
            'count'       => $hits->count(),
            // Summe der Beträge, die aktuell NICHT in der Kostenaufteilung stehen.
            'monthly'     => round($hits->sum('monthly'), 2),
            // Bewusst leer: das sind Geräte, keine Kostenpositionen — sie dürfen nicht in
            // total_monthly_flagged einfließen, das über cost_line-IDs summiert.
            'line_ids'    => [],
            'examples'    => $hits->sortByDesc('monthly')->take(self::MAX_EXAMPLES)->values()->all(),
        ];
    }

    // ---- Aufbereitung ----------------------------------------------------------------------

    /**
     * Treffer zu einem Befund verdichten. Gibt `null` zurück, wenn nichts gefunden wurde — ein
     * leerer Befund ist kein Befund und soll die Liste nicht aufblähen.
     */
    protected function finding($hits, string $key, string $severity, string $title, string $explanation): ?array
    {
        if ($hits->isEmpty()) {
            return null;
        }

        return [
            'key'         => $key,
            'severity'    => $severity,
            'title'       => $title,
            'explanation' => $explanation,
            'count'       => $hits->count(),
            'monthly'     => round($hits->sum('monthly_amount'), 2),
            'line_ids'    => $hits->pluck('id')->all(),
            'examples'    => $hits->sortByDesc('monthly_amount')->take(self::MAX_EXAMPLES)->map(fn ($l) => array_filter([
                'id'          => $l->id,
                'label'       => $l->label,
                'cost_type'   => $l->costType?->name,
                'cost_center' => $l->costCenter?->code,
                'holder'      => $l->assignee?->name,
                'amount'      => (float) $l->amount,
                'frequency'   => $l->frequency,
                'monthly'     => (float) $l->monthly_amount,
                'hint'        => $l->getAttribute('plausibility_hint'),
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }
}
