# ModulNest Module

ModulNest ist modular aufgebaut. Native Module registrieren ihre Routen, Untermenüs, Adminbereiche und optionale Systemchecks selbst.

## Zugriffsebenen

Module können mit unterschiedlichen Zugriffsstufen betrieben werden:

- `public`: öffentlich erreichbar
- `user`: nur für eingeloggte Benutzer
- `admin`: nur für Admins

Die Zugriffskontrolle läuft zentral im Router-Guard.

## Native Module

Der aktuelle Public-Export enthält:

- `Admin`: Admin-Grundbereich
- `Auth`: Login, Sessions, Remember-Me, 2FA und Passkeys
- `Modules`: Modulverwaltung und Auto-Discovery
- `Dashboard`: persönliche Widgets, Links, Aufgaben und Notizen; Aufgaben/Notizen können archiviert und wiederhergestellt werden
- `DataPortability`: Adminbereich für Export und Import von Moduldaten
- `Homepage`: konfigurierbare Root-Startseite mit sicherem Fallback
- `News`: öffentliche News und Admin-Verwaltung
- `Banking`: persönliche Konten, Transaktionen, Kategorien, Import und wiederkehrende Regeln
- `SneakPreview`: öffentliche Sneak-Preview-Liste mit Adminpflege
- `Systeminfo`: Systeminformationen und Systemcheck
- `Tools`: kleine Werkzeuge und Hilfsfunktionen
- `Logs`: Admin-Logviewer
- `Updates`: Adminbereich für offizielle ModulNest-Updates aus `stable.json`
- `User`: Profil, Sicherheit und Einstellungen

Weitere private oder experimentelle Module können im Entwicklungsstand existieren, werden aber nur dann öffentlich exportiert, wenn sie beim Release-Build ausgewählt wurden.

## Modul-Autodiscovery

Neue native Modulordner unter `app/Modules` können in der Modulverwaltung erkannt werden. Initiale Metadaten kommen aus dem Modul selbst und können anschließend in der Modulverwaltung angepasst werden.

## Homepage / Startseite

Das Homepage-Modul bereitet eine konfigurierbare Startseite für `/` vor. Es rendert nur veröffentlichte, aktivierte Blöcke. Wenn das Modul deaktiviert ist, nicht veröffentlicht wurde, keine gültigen Blöcke vorhanden sind oder beim Rendern ein Fehler auftritt, wird automatisch die bisherige Standard-Startseite angezeigt.

V1 unterstützt die Blocktypen `custom_content`, `feature_list` und `module_list`. Blöcke können im Adminbereich erstellt, bearbeitet, aktiviert/deaktiviert und sortiert werden. Für Inhaltsblöcke sind mehrere strukturierte Buttons möglich; Feature-Listen verwenden strukturierte Items statt freier HTML-Eingabe. Die Admin-Vorschau rendert Markdown, Feature-Listen und Modul-Listen zielgruppenbezogen für Gäste, User und Admins. Freie HTML-Eingabe ist nicht vorgesehen; Markdown-Inhalte werden über den Core-Markdown-Renderer sicher gerendert.

Homepage ist seit ModulNest `0.8.0 alpha` im Public-Default enthalten. Sie übernimmt `/` nach Clean Install oder Update nicht automatisch, weil `homepage.is_published` standardmäßig deaktiviert bleibt.

## Dashboard

Das Dashboard bietet userbezogene Widgets für Links, Aufgaben und Notizen. Aufgaben und Notizen unterstützen seit ModulNest `0.8.1 alpha` einen Archivstatus über `archived_at`: aktive Listen zeigen nur nicht archivierte Einträge, archivierte Einträge bleiben wiederherstellbar im Archivbereich. Aktive und archivierte Einträge werden im Widget gezählt.

DataPortability übernimmt den Archivstatus für Dashboard-Aufgaben und -Notizen beim Export/Import. Ältere Exporte ohne `archived_at` bleiben importierbar; fehlende Werte gelten als nicht archiviert.

## Legacy-Anbindung

Legacy-Anwendungen können über Modul-Einträge angebunden werden. Im Public-Export enthält `app/Legacy` nur einen Platzhalter, keine privaten Legacy-Apps.

### Legacy-CSRF-Vertrag ab Modulon 1.0

Legacy-Unterstützung erhält die Einbindung lokaler PHP-Anwendungen, bedeutet aber nicht, dass beliebiger alter unsicherer PHP-Code unverändert funktionieren muss.

- `GET`, `HEAD` und `OPTIONS` dürfen keine Zustandsänderungen auslösen.
- `POST`, `PUT`, `PATCH` und `DELETE` laufen über den zentralen CSRF-Guard.
- Normale HTML-Formulare verwenden bewusst die Bridge:

  ```php
  <form method="post">
      <?= \Modulon\Core\LegacyCsrf::field() ?>
      <!-- weitere Felder -->
  </form>
  ```

- Für `fetch`/XHR wird der aktuelle Token bewusst in der Legacy-View, etwa in einem `data-csrf-token`-Attribut oder Meta-Tag, ausgegeben und als Header übertragen:

  ```php
  <meta name="modulon-csrf-token" content="<?= htmlspecialchars(\Modulon\Core\LegacyCsrf::token(), ENT_QUOTES, 'UTF-8') ?>">
  ```

  ```js
  const csrfToken = document.querySelector('meta[name="modulon-csrf-token"]')?.content || '';
  fetch('/mein-legacy-modul/action.php', {
      method: 'POST',
      headers: {
          'X-CSRF-Token': csrfToken,
          'Accept': 'application/json'
      }
  });
  ```

- Tokens dürfen niemals in URLs, Query-Strings oder Logs übertragen werden und nicht über Requests hinweg gecacht werden. Nach Login oder Session-Rotation muss die View den aktuellen Token erneut ausgeben.
- Eine Legacy-App ohne zentralen `_csrf`-Feldwert oder `X-CSRF-Token` erhält HTTP 419. Das ist beabsichtigt.
- Modulon injiziert Tokens nicht automatisch in Legacy-HTML. Jede App bindet die Bridge bewusst ein.
- ModulNest liefert keine konkreten Legacy-Apps aus; die Bridge dient lokalen oder privaten Integrationen.

## News und Markdown

News-/Changelog-Inhalte unterstützen Markdown für Grundformatierungen:

- `**fett**`
- `*kursiv*`
- `` `Inline-Code` ``
- Listen
- Links
- Überschriften

Markdown wird über den Core-Service `Modulon\Core\MarkdownRenderer` gerendert. Raw HTML wird aus Sicherheitsgründen entfernt, unsichere Links wie `javascript:` werden nicht als klickbare Links ausgegeben, Markdown-Bilder werden entfernt und die Verschachtelung ist begrenzt. Titel, Slugs, Kurzbeschreibungen und Metadaten bleiben normale escaped Textfelder.

## Mail-Modul

Das Mail-Modul ist nicht Teil des aktuellen defensiven Public-Exports, aber vorbereitet. Relevante Abhängigkeiten:

- `webklex/php-imap`
- `symfony/mailer`
- `symfony/mime`
- PHP-Extension `iconv`

Die PHP-Extension `imap` ist nicht erforderlich.

## Nicht Im Public Release

Nicht Teil des aktuellen Public Releases sind:

- `Mail`
- `FantasyCards`
- Legacy-Module

Release-Pakete enthalten nur Module, die beim Export ausdrücklich ausgewählt wurden.
