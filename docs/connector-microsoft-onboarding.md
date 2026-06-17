# Microsoft-Connector anbinden — Anleitung

Wie ein **Tenant** (Kundenkontext) mit seinem Microsoft-365-/Intune-Verzeichnis verbunden wird,
damit Geräte und Lizenzen automatisch synchronisiert werden.

> **Wichtig:** Du brauchst dafür **keine** Admin-Rechte im Kunden-Tenant. Du *startest* die Anbindung
> und verschickst einen **Consent-Link**; den eigentlichen Consent klickt **ein Admin des Kunden** einmal.
> Tenants ohne Microsoft-Anbindung werden rein manuell gepflegt — diese Anleitung betrifft nur die optionale Anbindung.

---

## Teil 1 — Einmalig: Azure-App registrieren (Plattform-Betreiber)

Das macht **einmal** jemand mit Rechten in **BHGs** Azure AD. Es entsteht **eine** Multi-Tenant-App,
die für **alle** Kunden-Connectoren genutzt wird.

1. **Azure Portal → App-Registrierungen → Neue Registrierung**
   - Name: z. B. `Asset Manager – Intune Sync`
   - Unterstützte Kontotypen: **„Konten in einem beliebigen Organisationsverzeichnis (mehrmandantenfähig)"**
   - Redirect-URI (Plattform „Web"): `https://office.bhgdigital.de/asset-manager/connectors/microsoft/callback`
     _(finale URL ergibt sich aus der M2-Implementierung — hier Platzhalter)_

2. **API-Berechtigungen → hinzufügen → Microsoft Graph → Anwendungsberechtigungen** (nicht „delegiert"!):

   | Berechtigung | Wofür |
   |---|---|
   | `DeviceManagementManagedDevices.Read.All` | Intune-Geräte lesen (**nicht** `Device.Read.All` — das liest nur Azure-AD-Geräte, keine Intune-Verwaltungsdaten) |
   | `User.Read.All` | Benutzer + deren zugewiesene Lizenzen lesen |
   | `Organization.Read.All` | Abonnierte SKUs / Lizenz-Bestand lesen (`/subscribedSkus`) |

   > Admin-Consent hier **nicht** in BHGs Tenant klicken — der Consent erfolgt pro **Kunden**-Tenant (Teil 2).

3. **Credential anlegen** (Entscheidung offen — siehe Anhang):
   - **Zertifikat** (empfohlen für app-only Produktion), **oder**
   - **Client-Secret** (einfacher; läuft ab → Rotation einplanen)

4. **Werte hinterlegen** (einmalig, geteilt über alle Connectoren) in `env`/`config`:
   - `Application (client) ID`
   - das Secret bzw. das Zertifikat
   - (Tenant-GUIDs der Kunden werden **nicht** hier, sondern pro Connector gespeichert)

---

## Teil 2 — Pro Kunde: Connector verbinden (du)

1. Im Asset Manager den **Tenant** wählen (oder neu anlegen).
2. Links **„Konnektoren" → „Connector hinzufügen → Microsoft"**.
3. **Kunden-Verzeichnis** angeben: Domain (`kunde.de`) **oder** Tenant-GUID.
4. Es erscheint ein **Admin-Consent-Link**. Status des Connectors: **„Consent ausstehend"**.
   Der Link hat die Form:
   ```
   https://login.microsoftonline.com/{kunden-tenant}/v2.0/adminconsent
     ?client_id={unsere-client-id}
     &scope=https://graph.microsoft.com/.default
     &redirect_uri={callback}
     &state={zufallswert}
   ```
5. **Link an den Admin des Kunden schicken** (Mail/Teams) — Textbaustein in Teil 3.
6. Sobald der Admin zugestimmt hat, ruft Microsoft unseren Callback mit `admin_consent=True&tenant={guid}` auf.
   → Connector wird **„aktiv"**, GUID bestätigt, der **erste Sync** startet automatisch.

Bist du selbst Admin des Kunden-Tenants, klickst du den Link direkt — gleicher Ablauf, sofort.

---

## Teil 3 — Was der Kunden-Admin tun muss (zum Weiterleiten)

> Hallo, um eure Intune-Geräte und Microsoft-365-Lizenzen für die Asset-Verwaltung **lesend**
> anzubinden, bitte den folgenden Link **einmal** mit einem **Global Administrator** (oder
> „Privilegierter Rollenadministrator" / „Administrator für Cloudanwendungen") eures Tenants öffnen
> und auf **„Akzeptieren"** klicken:
>
> `{Admin-Consent-Link}`
>
> Es werden ausschließlich **lesende** Berechtigungen angefragt (Geräte, Benutzer, Lizenzbestand).
> Nach der Zustimmung erscheint in eurem Tenant eine Unternehmens-App; ihr könnt die Anbindung dort
> jederzeit wieder entziehen.

---

## Teil 4 — Troubleshooting

| Symptom | Ursache / Lösung |
|---|---|
| Admin sieht „Benötigt Administratorzustimmung" und kann nicht zustimmen | Der Klickende ist kein Admin → von einem Global/Cloud-App-Admin öffnen lassen. |
| `AADSTS65004` / Consent abgebrochen | Admin hat „Ablehnen" geklickt oder Vorgang abgebrochen → Link erneut öffnen. |
| Graph **403** beim Sync trotz aktivem Connector | Eine der drei Anwendungsberechtigungen fehlt oder Consent unvollständig → Berechtigungen prüfen, Consent erneut erteilen. |
| Sync liefert 0 Geräte, aber kein Fehler | Tenant hat keine Intune-verwalteten Geräte, oder falsche GUID/Domain hinterlegt. |
| `tenant`-GUID im Callback ≠ eingegebene Domain | Domain gehört zu anderem Verzeichnis → GUID aus dem Callback ist maßgeblich; Eingabe korrigieren. |

---

## Anhang — Offene Punkte

- **Credential-Typ:** Zertifikat (empfohlen) vs. Client-Secret — noch zu entscheiden.
- **Finale Redirect-/Callback-URL:** ergibt sich aus der Route in M2; muss exakt mit der App-Registrierung übereinstimmen.
- **Wer darf Connectoren anlegen/verwalten:** aktuell Team-`owner`/`admin` (bestehende Policy) — bestätigen.
