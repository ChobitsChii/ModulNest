# Entwicklung

ModulNest ist ein persönliches Open-Source-Projekt von Jennifer Graßl. Der öffentliche Produktname ist ModulNest; Modulon bleibt der interne Core- und Arbeitsname.

Bei Planung, Code-Reviews, Refactoring, Dokumentation und einzelnen Implementierungsschritten kommen KI-Werkzeuge wie ChatGPT und Codex unterstützend zum Einsatz. ModulNest ist damit KI-unterstützt entwickelt, aber nicht einfach "von KI erstellt".

Architekturentscheidungen, Funktionsumfang, Tests, Review, Releases und Veröffentlichung liegen beim Projektmaintainer.

Wo es sinnvoll und sicherer ist, nutzt ModulNest etablierte externe Libraries statt sicherheitskritische oder komplexe Funktionen unnötig selbst zu bauen. Das betrifft zum Beispiel Bereiche wie Authentifizierung, Zwei-Faktor-Funktionen, Mail, QR-Codes und WebAuthn.

Eine Übersicht der verwendeten Libraries und Technologien steht in [Technologien und Abhängigkeiten](dependencies.md).

Ziel bleibt ein wartbares, selbst hostbares Modulsystem mit klarer Trennung zwischen Core, optionalen Modulen und lokalen Installationsdaten.

Für den verbindlichen Vertrag und die praktische Erstellung nativer Module siehe
die [Developer-Dokumentation](development/README.md).
