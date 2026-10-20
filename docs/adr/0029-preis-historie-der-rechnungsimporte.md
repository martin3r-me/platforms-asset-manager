# Preis-Historie der Rechnungsimporte

Status: akzeptiert (2026-10-20)

Kontext: Die Rechnungsimporter (Vodafone Mobilfunk, ADR 0018; Vodafone Festnetz/RingCentral, ADR 0026)
aktualisieren ihre Kostenpositionen jeden Monat **in place** — der `import_hash` trägt die Identität (Konto,
Rufnummer bzw. Posten), nicht den Betrag, damit die Zeile ihre ID, ihre gepflegte Kostenstelle und eine bewusste
Löschung behält. Die Kehrseite: der Vormonatsbetrag war nach dem nächsten Import weg. Auslöser war die Frage
„was ist der Regelpreis von 0173 3185736?" — in der Kostenposition stand nur der letzte Monat (4,06 € statt der
üblichen 21,00 €), und der Monatsstand (ADR 0027) kennt nur Summen je Kostenart × Kostenstelle.

Entscheidung:

- **Eine Tabelle `asset_cost_line_history`**: je Kostenposition und Rechnung ein Eintrag mit Betrag (nach dem
  Rundungsausgleich), Abrechnungszeitraum, Leistungsmonat, Bezeichnung, Kostenart/-stelle und Träger zum Zeitpunkt.
  Unique je Tenant × Identität × Rechnung — ein erneuter Lauf derselben Rechnung überschreibt seinen Eintrag.
- **Gruppiert wird über `import_hash`, nicht über `cost_line_id`.** Der Prune löscht abgängige Nummern hart; ihr
  Verlauf bleibt (`cost_line_id` wird dann null) und ist über die Bezeichnung weiter auffindbar.
- **Gilt für alle Rechnungsimporte, die Kostenpositionen schreiben** — ein Mechanismus
  (`Services\CostLineHistoryService`), angebunden über `$written` beider Importer. Die Lizenz-Abrechnung
  (ADR 0019) braucht ihn nicht: ihre Vertragszeilen sind schon je Abrechnungsmonat abgelegt.
- **Regelpreis = häufigster Betrag** im betrachteten Zeitraum (bei Gleichstand der jüngere), erst ab drei
  Monaten. Weicht der letzte Betrag ab, wird es angezeigt — kein Alarm, eine Information.
- **„Historie nachtragen"**: ältere Rechnungen werden mit `historyOnly` durch denselben Importer gerechnet, die
  Transaktion wird zurückgerollt, geschrieben werden nur die Verlaufszeilen. Deshalb gilt die Sperre gegen ältere
  Rechnungen (#948) hier nicht, und weder Kosten noch Monatsstände noch DATEV ändern sich. Mehrere Dateien, der
  Typ (Mobilfunk/Festnetz) wird je Datei an der Kopfzeile erkannt.
- **Sichtbar nur mit Controlling** (ADR 0008): Mobilfunk-Block der Träger-Detailseite, Editor der Kostenposition,
  MCP `asset-manager.cost-lines.history.GET` (Suche per Rufnummer in beliebiger Schreibweise).

## Bewusste Abgrenzungen

- Festnetz nachgetragen: die Plätze kommen aus der **heutigen** RingCentral-Benutzerliste. Die Beträge je Platz
  stimmen, die Zuordnung zur Person ist für alte Monate eine Näherung.
- Beim Nachtragen hängt eine Verlaufszeile an der **heutigen** Kostenposition gleicher Identität; eine im UI
  bewusst gelöschte Kostenposition bekommt keinen nachgetragenen Verlauf (der Importer überspringt sie).
- Bis die Migration live ist (Forge spielt sie im zweiten Deploy-Lauf ein), schreibt der Import schlicht keinen
  Verlauf — er scheitert nicht an der fehlenden Tabelle.
- Der Team-Reset löscht den Verlauf mit (Kostendaten wie die Monatsstände).
