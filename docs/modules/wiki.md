# Wiki

**Zugriff:** angemeldete Benutzer; Konfiguration und Synchronisierung nur für Administratoren.

Das optionale Wiki synchronisiert Markdown aus genau einem öffentlichen GitHub-Repository in einen lokalen, offline nutzbaren Cache. GitHub wird nicht bei jedem Seitenaufruf abgefragt.

Administratoren wählen unter `/admin/wiki` genau eine Quelle und starten die Synchronisierung. `Quelle aktiviert` schaltet diese eine Quelle nur allgemein an oder aus; sie ist keine zweite Aktivierung pro Quelltyp. Bei einem Fehler bleibt der letzte erfolgreiche lokale Stand sichtbar.

Die Administration trennt bewusst die **konfigurierte Quelle** vom **aktiven Wiki-Stand**. Nach einem Wechsel bleibt der bisherige Cache sichtbar, bis die neu konfigurierte Quelle erfolgreich synchronisiert wurde. Erst dann wird der aktive Stand atomar ersetzt.

## Öffentliches GitHub Repository

GitHub-Quellen verwenden Besitzer, Repository, Branch oder Tag und einen Dokumentationsordner. Unterstützt werden ausschließlich öffentliche `github.com`-Repositories.

## Lokales Verzeichnis

Lokale Quellen speichern ausschließlich einen relativen Pfad wie `docs` oder `docs/development`. Der Pfad wird gegen den aktuellen ModulNest-Installationsroot mit `realpath()` geprüft. Absolute Pfade, Traversal, Symlink-Ausbrüche sowie Runtime- und Secret-Verzeichnisse sind nicht verfügbar.

Unterstützt werden Markdown sowie PNG, JPEG, GIF und WebP. Rohes HTML, SVG, PDFs, JavaScript und ausführbare Inhalte werden nicht übernommen. Relative Dokumentationslinks und Bilder werden auf kontrollierte Wiki-Routen abgebildet.

Wiki v1 unterstützt keine privaten Repositories, Tokens, mehrere Quellen, automatische Synchronisierung, Suche oder Bearbeitung.
