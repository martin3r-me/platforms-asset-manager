# Geräteausgabe: Signatur optional, eine offene je Gerät, Abdeckung als Kennzahl

Status: akzeptiert (2026-10-19) — hält fest, was seit Juni gebaut ist, und ergänzt die Abdeckung (#887)
sowie die Sperre nach der Unterschrift (#956)

Kontext: Die **Geräteausgabe** (`AssetHandover` + `AssetHandoverLine`, Übergabeprotokoll) war bisher nur in
`CONTEXT.md` beschrieben, in keinem ADR. Drei Regeln standen im Code, ohne dass jemand sie entschieden
nachlesen konnte; und die Frage, wofür das Protokoll eigentlich da ist — die Übergabe zu **belegen** —,
beantwortete keine Auswertung.

Entscheidung:

- **Signatur ist optional und nachholbar.** Ein Protokoll entsteht mit oder ohne Unterschrift; die Unterschrift
  kann später im selben Protokoll ergänzt werden. Ohne Unterschrift ist es eine dokumentierte Ausgabe, kein Beleg.
- **Nach der Unterschrift ist das Protokoll gesperrt** (#956). Empfänger, Datum, Geräte, Zubehör und die
  Unterschrift selbst ändern sich nicht mehr — das PDF zeigte sonst eine Unterschrift unter Angaben, die
  nachträglich geändert wurden. Offen bleiben Rückgaben und Zeilen-Notizen. Weitere Geräte bekommen ein neues
  Protokoll. `signed_at` folgt der Unterschrift.
- **Eine offene Ausgabe je Gerät.** Eine Neu-Ausgabe bucht eine noch offene Zeile desselben Geräts automatisch
  zurück (Verlaufs-Event `returned`).
- **Unabhängig von der Zuordnung.** Die [[Zuordnung]] kommt bei Intune-Geräten aus dem MDM, die Geräteausgabe
  ist das Dokument dazu. Keine Seite schreibt die andere — eine Abweichung ist eine Information.
- **Abdeckung als Kennzahl** (#887): Basis sind Geräte im Lifecycle `in_use` oder ohne gepflegten Lifecycle
  (bei Intune-Geräten der Normalfall) **mit offener Zuordnung**. Abgedeckt ist ein Gerät, wenn eine noch nicht
  zurückgegebene Protokollzeile in einem **unterschriebenen** Protokoll existiert. Reserve, Reparatur,
  terminale Geräte und Geräte ohne Träger zählen nicht. Umsetzung: `Services\HandoverCoverageService`, Anzeige
  auf der Seite Geräteausgaben.

## Bewusste Abgrenzungen

- Keine Pflicht-Signatur: ein Gerät muss ausgegeben werden können, auch wenn die Person gerade nicht
  unterschreiben kann. Die Kennzahl macht die Lücke sichtbar, statt den Vorgang zu blockieren.
- Keine Automatik „Zuordnung ⇒ Protokoll": das Protokoll ist ein bewusster Vorgang mit Zubehör und Zustand,
  den kein Sync ersetzen kann.
- Die Unterschrift wird nur als PNG-Data-URL aus dem Unterschriftenfeld angenommen (max. 400 KB).
