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
- `News`: öffentliche News und Admin-Verwaltung
- `Systeminfo`: Systeminformationen und Systemcheck
- `Updates`: Adminbereich für offizielle ModulNest-Updates aus `stable.json`
- `User`: Profil, Sicherheit und Einstellungen

Weitere private oder experimentelle Module können im Entwicklungsstand existieren, werden aber nur dann öffentlich exportiert, wenn sie beim Release-Build ausgewählt wurden.

## Modul-Autodiscovery

Neue native Modulordner unter `app/Modules` können in der Modulverwaltung erkannt werden. Initiale Metadaten kommen aus dem Modul selbst und können anschließend in der Modulverwaltung angepasst werden.

## Legacy-Anbindung

Legacy-Anwendungen können über Modul-Einträge angebunden werden. Im Public-Export enthält `app/Legacy` nur einen Platzhalter, keine privaten Legacy-Apps.

## Mail-Modul

Das Mail-Modul ist nicht Teil des aktuellen defensiven Public-Exports, aber vorbereitet. Relevante Abhängigkeiten:

- `webklex/php-imap`
- `symfony/mailer`
- `symfony/mime`
- PHP-Extension `iconv`

Die PHP-Extension `imap` ist nicht erforderlich.

## Banking und weitere Module

Banking, Tools, FantasyCards und andere Module können in späteren Releases freigegeben werden. Release-Pakete enthalten nur Module, die beim Export ausdrücklich ausgewählt wurden.
