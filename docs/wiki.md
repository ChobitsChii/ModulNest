# Wiki-Modul (v1)

Das optionale Wiki-Modul synchronisiert Markdown aus **einem öffentlichen GitHub-Repository** in einen lokalen, offline nutzbaren Stand. GitHub bleibt die Quelle; Seiten werden nicht bei jedem Aufruf aus dem Netzwerk geladen.

## Einrichtung

1. Wiki in der Modulverwaltung aktivieren.
2. Als Administrator `/admin/wiki` öffnen.
3. GitHub-Benutzer/Organisation, Repository, Branch/Tag und Dokumentationsordner eintragen. Für ModulNest ist `ChobitsChii`, `ModulNest`, `main` und `docs` die vollständige Beispielquelle. Der Public Export enthält auch `docs/development`; nach dessen Veröffentlichung kann dieser Unterordner bei Bedarf gezielt synchronisiert werden.
4. Quelle speichern und **Jetzt synchronisieren** wählen.

Bei einem Sync-Fehler bleibt der zuletzt erfolgreiche lokale Stand für Benutzer verfügbar.

## Unterstützte Inhalte

- Markdown: `.md`, `.markdown`
- lokale Bilder: PNG, JPEG, GIF und WebP
- Frontmatter: `title`, `order`, `hidden`

SVG, PDFs, HTML, JavaScript, Stylesheets, Archive und ausführbare Dateien werden bewusst nicht übernommen. Rohes HTML aus Markdown wird nicht gerendert. Relative Markdown-Links und erlaubte Bildpfade werden auf kontrollierte lokale Wiki-Routen abgebildet.

## Grenzen von v1

Wiki v1 unterstützt nur öffentliche GitHub-Repositories und genau eine aktive Quelle. Private Repositories, Tokens, mehrere Quellen, automatische Synchronisation, Webhooks, Suche, Bearbeitung und Pull Requests sind nicht enthalten.
