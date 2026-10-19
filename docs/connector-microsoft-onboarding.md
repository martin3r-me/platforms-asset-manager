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
   - Redirect-URI: muss in der App **registriert** sein (Microsoft verlangt eine für den Consent-Link), wird von
     uns aber **nicht verarbeitet** (manueller Consent, kein Callback). Default in der Config:
     `https://login.microsoftonline.com/common/oauth2/nativeclient` — überschreibbar via
     `ASSET_MANAGER_AZURE_REDIRECT_URI`. Wert muss exakt mit der App-Registrierung übereinstimmen.

2. **API-Berechtigungen → hinzufügen → Microsoft Graph → Anwendungsberechtigungen** (nicht „delegiert"!):

   | Berechtigung | Wofür |
   |---|---|
   | `DeviceManagementManagedDevices.Read.All` | Intune-Geräte lesen (**nicht** `Device.Read.All` — das liest nur Azure-AD-Geräte, keine Intune-Verwaltungsdaten) |
   | `User.Read.All` | Benutzer + deren zugewiesene Lizenzen lesen |
   | `Organization.Read.All` | Abonnierte SKUs / Lizenz-Bestand lesen (`/subscribedSkus`) |

   > Admin-Consent hier **nicht** in BHGs Tenant klicken — der Consent erfolgt pro **Kunden**-Tenant (Teil 2).

3. **Credential anlegen:** **Client-Secret** — das ist der einzige Weg, den der Code heute kann
   (`IntuneGraphService::fetchToken()`, Client-Credentials-Flow mit `client_secret`). Das Secret läuft ab →
   Rotation einplanen. Ein Zertifikat wäre sicherer, ist aber nicht implementiert (siehe Anhang).

4. **Werte hinterlegen** (einmalig, geteilt über alle Connectoren) in `.env` der Host-App:
   - `ASSET_MANAGER_AZURE_CLIENT_ID` = Application (client) ID
   - `ASSET_MANAGER_AZURE_CLIENT_SECRET` = das Secret
   - `ASSET_MANAGER_AZURE_REDIRECT_URI` = die registrierte Redirect-URI (optional; Default s. o.)
   - (Tenant-GUIDs der Kunden werden **nicht** hier, sondern pro Connector gespeichert)

   _Übergang:_ Ein bestehender Connector mit **eigenen** `client_id`/`client_secret` läuft unverändert weiter
   (Connector-Werte haben Vorrang). Erst neue Connectoren ohne eigene Credentials nutzen die zentrale App.

---

## Teil 2 — Pro Kunde: Connector verbinden (du)

1. Im Asset Manager den **Tenant** wählen (oder über **„+ Tenant"** neu anlegen).
2. Rechts im Tenant-Detail, Abschnitt **„Microsoft-Anbindung"**, das Formular ausfüllen und **„Connector hinzufügen"**
   klicken (Seite `asset-manager.connectors.index`; nur Team-Owner/-Admin).
3. **Kunden-Verzeichnis** angeben: Domain (`kunde.de`) **oder** Tenant-GUID. (Eigene App-Credentials nur unter
   „Erweitert", falls nicht die zentrale App genutzt wird.) Speichern → Status **„Consent ausstehend"**.
4. Es erscheint ein **Admin-Consent-Link** (zum Kopieren). Der Link hat die Form:
   ```
   https://login.microsoftonline.com/{kunden-tenant}/v2.0/adminconsent
     ?client_id={unsere-client-id}
     &scope=https://graph.microsoft.com/.default
     &redirect_uri={registrierte-redirect-uri}
     &state={zufallswert}
   ```
5. **Link an den Admin des Kunden schicken** (Mail/Teams) — Textbaustein in Teil 3.
6. Sobald der Admin zugestimmt hat (er landet danach auf der registrierten Redirect-URI — die wird von uns
   **nicht** verarbeitet), im Asset Manager auf **„Anbindung prüfen"** klicken. Das macht einen Token-Test
   gegen Graph; bei Erfolg wird der Connector **„aktiv"** und kann synchronisiert werden.
   → Es gibt **keinen** automatischen Callback — die Aktivierung ist bewusst ein manueller Klick.

Bist du selbst Admin des Kunden-Tenants, klickst du den Link direkt und danach „Anbindung prüfen" — sofort fertig.

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
| „Anbindung prüfen" scheitert, obwohl der Admin zugestimmt hat | Domain/GUID gehört zu einem anderen Verzeichnis als das, in dem zugestimmt wurde → Kunden-Verzeichnis prüfen (im Zweifel die Tenant-GUID aus dem Entra-Portal des Kunden eintragen). Nach einer Änderung des Verzeichnisses muss der Consent erneut geprüft werden. |

---

## Anhang — Offene Punkte

- **Credential-Typ:** implementiert ist nur das Client-Secret. Zertifikat (empfohlen für app-only Produktion) wäre
  eine Erweiterung von `IntuneGraphService::fetchToken()` — noch nicht entschieden.
- **Redirect-URI:** es gibt **keine** eigene Callback-Route; die Redirect-URI muss nur in der App-Registrierung
  stehen und exakt `ASSET_MANAGER_AZURE_REDIRECT_URI` (Default `…/oauth2/nativeclient`) entsprechen.
- **Wer darf Connectoren anlegen/verwalten:** Team-`owner`/`admin` — `AssetDevicePolicy::manageConnector()` bzw.
  `sync()` (siehe ADR 0004).
