# Deploy dreht den Lock-Zeiger weiter, statt `composer update` zu laufen

Status: akzeptiert (beschlossen 2026-09-02, Max)

Kontext: Der bisherige Deploy-Weg (`demo.bhgdigital.de/update.sh`) macht in der Host-App
`git pull` → **`composer update`** → `composer.lock` committen → `push`. Dieser Weg ist seit
einiger Zeit **komplett blockiert**:

```
Failed to execute git clone --mirror -- https://…@github.com/martin3r-me/platforms-avatar.git
remote: Repository not found.
```

`martin3r/platforms-avatar` steht in der `composer.json` der Host-App als `dev-main`-Requirement
samt VCS-Repository, ist auf GitHub aber nicht mehr erreichbar — weder über den Composer-Token
noch über den GitHub-Account (`gh api repos/martin3r-me/platforms-avatar` → 404; das Repo taucht
auch in der Liste der 85 sichtbaren `martin3r-me`-Repos nicht auf). Ob gelöscht, umbenannt oder
privat gestellt: die Ursache liegt außerhalb dieses Moduls.

Entscheidend ist, **warum das jeden Deploy killt**: `composer update` lädt die Metadaten *aller*
VCS-Repositories, bevor es überhaupt auflöst. Deshalb scheitert auch ein **partielles**
`composer update martin3r/platform-asset-manager` — obwohl es Avatar inhaltlich gar nicht anfasst.

`composer install` verhält sich anders: es liest ausschließlich die `composer.lock` und fragt kein
VCS-Repository ab. Ein Paket, dessen Lock-Eintrag unverändert ist, wird nicht angerührt. Genau das
läuft auf dem Server beim Deploy.

Entscheidung: **Der Deploy dreht in der `composer.lock` der Host-App den Commit-Zeiger dieses
einen Pakets weiter — kein `composer update`.**

Umgesetzt in [`tools/deploy-lock.py`](../../tools/deploy-lock.py):

```bash
python tools/deploy-lock.py --dry-run   # prüfen
python tools/deploy-lock.py             # deployen
```

Geändert werden im Lock-Eintrag von `martin3r/platform-asset-manager` genau vier Zeilen:
`source.reference`, `dist.url`, `dist.reference`, `time`. Der Rest der Datei bleibt Byte für Byte
gleich — insbesondere der `content-hash`, der aus der `composer.json` der Host-App stammt und von
einem Paket-Zeiger nicht berührt wird. Danach `commit` + `push origin main`, der Server zieht per
`composer install`.

Das ist derselbe Weg, den die anderen Module im Haus schon fahren (die
`chore: platforms-reservations auf …`-Commits in der Host-App ändern ausschließlich diese vier
Zeilen). Wir schreiben ihn hier nur als Werkzeug fest, statt ihn jedes Mal von Hand nachzubauen.

## Bewusste Abgrenzungen / Trade-offs

- **Der Drift-Check ist der Kern, nicht Beiwerk.** Ein Zeiger-Update friert die *alten*
  Paket-Metadaten ein. Ändert sich die `composer.json` des Moduls — eine neue Abhängigkeit, ein
  zusätzlicher `extra.laravel.providers`-Eintrag, ein neuer PSR-4-Namensraum —, dann wäre der
  Lock-Eintrag inhaltlich falsch und der Fehler auf dem Server **still**: der ServiceProvider würde
  einfach nicht registriert. Deshalb vergleicht das Skript vor dem Patch die `composer.json` des
  Ziel-Commits gegen den Lock-Eintrag (`require`, `autoload`, `extra`, `type`, … ) und **bricht ab**,
  wenn etwas abweicht. In diesem Fall hilft nur ein echtes `composer update` — und damit erst die
  Klärung der Avatar-Ursache.
- **Migrationen sind nicht betroffen.** Sie laufen im Deploy-Skript der Host-App, unabhängig davon,
  wie der Lock-Zeiger dorthin kam.
- **Wir fassen die `composer.json` der Host-App nicht an.** Avatar dort aus `require` zu entfernen
  wäre der naheliegende „richtige" Fix — aber die Host-App ist gemeinsam genutzt, und ein Modul
  aus ihren Requirements zu werfen ist keine Entscheidung dieses Moduls
  (Goldene Regel 3: keine modulübergreifenden Änderungen im Alleingang). Vorschlag an Martin:
  entweder Avatar-Repo wieder erreichbar machen bzw. Zugriff auf den Composer-Token legen, oder
  Avatar aus der Host-`composer.json` nehmen. Bis dahin gilt dieses ADR.
- **Lokal spielt das keine Rolle.** Die lokale Host-App kann `composer install` ohnehin nicht
  ausführen (PHP 8.2 statt 8.3, `ext-gd` fehlt); lokal getestet wird über `tests/local/`.
