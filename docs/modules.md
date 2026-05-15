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
- `Dashboard`: persönliche Widgets
- `DataPortability`: Adminbereich für Export und Import von Moduldaten
- `News`: öffentliche News und Admin-Verwaltung
- `Systeminfo`: Systeminformationen und Systemcheck
- `Updates`: Adminbereich für offizielle ModulNest-Updates aus `stable.json`
- `User`: Profil, Sicherheit und Einstellungen

Weitere private oder experimentelle Module können im Entwicklungsstand existieren, werden aber nur dann öffentlich exportiert, wenn sie beim Release-Build ausgewählt wurden.

Im Entwicklungsstand ist zusätzlich `Homepage` für den nächsten Public-Export vorbereitet: eine konfigurierbare Root-Startseite mit sicherem Fallback auf die Standard-Startseite. Sie wird nur dann live auf `/` genutzt, wenn sie im Adminbereich ausdrücklich veröffentlicht wird.

## Modul-Autodiscovery

Neue native Modulordner unter `app/Modules` können in der Modulverwaltung erkannt werden. Initiale Metadaten kommen aus dem Modul selbst und können anschließend in der Modulverwaltung angepasst werden.

## Homepage / Startseite

Das Homepage-Modul bereitet eine konfigurierbare Startseite für `/` vor. Es rendert nur veröffentlichte, aktivierte Blöcke. Wenn das Modul deaktiviert ist, nicht veröffentlicht wurde, keine gültigen Blöcke vorhanden sind oder beim Rendern ein Fehler auftritt, wird automatisch die bisherige Standard-Startseite angezeigt.

V1 unterstützt die Blocktypen `custom_content`, `feature_list` und `module_list`. Blöcke können im Adminbereich erstellt, bearbeitet, aktiviert/deaktiviert und sortiert werden. Für Inhaltsblöcke sind mehrere strukturierte Buttons möglich; Feature-Listen verwenden strukturierte Items statt freier HTML-Eingabe. Die Admin-Vorschau rendert Markdown, Feature-Listen und Modul-Listen zielgruppenbezogen für Gäste, User und Admins. Freie HTML-Eingabe ist nicht vorgesehen; Markdown-Inhalte werden über den Core-Markdown-Renderer sicher gerendert.

## Legacy-Anbindung

Legacy-Anwendungen können über Modul-Einträge angebunden werden. Im Public-Export enthält `app/Legacy` nur einen Platzhalter, keine privaten Legacy-Apps.

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

## Banking und weitere Module

Banking, Tools, FantasyCards und andere Module können in späteren Releases freigegeben werden. Release-Pakete enthalten nur Module, die beim Export ausdrücklich ausgewählt wurden.
