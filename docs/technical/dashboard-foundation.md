# Dashboard Foundation (Technische Grundlage)

Status: Basis-Architektur für das native Modul `Dashboard` ist vorbereitet.  
Scope: Datenmodell + Routing + sichere Grundannahmen. Noch kein vollständiger Feature-Ausbau.

## Zielmodell

- `Dashboard` ist ein **natives Modul** unter `/dashboard` mit Zugriff `user`.
- Inhalte sind **immer benutzerbezogen**.
- Widgets sind Instanzen pro Benutzer und können mehrfach pro Typ vorkommen:
  - `links`
  - `tasks`
  - `notes`

Beispiel:

- User A: 1x `links`
- User B: `links` + `tasks` + `notes`
- User C: 2x `links` für unterschiedliche Bereiche

## Tabellen (Grundlage)

1. `dashboard_widgets`
- Widget-Instanzen pro Benutzer
- Kernfelder:
  - `user_id`
  - `widget_type` (`links|tasks|notes`)
  - `title`
  - `sort_order`
  - `layout_width` (z. B. 12/6/4)
  - `is_active`

2. `dashboard_link_folders`
- Ordner je Links-Widget
- bindet an `dashboard_widgets.id`

3. `dashboard_links`
- Links je Links-Widget/Ordner
- bindet an `dashboard_widgets.id`
- optional an `dashboard_link_folders.id`
- vorbereitet für Favicon-Metadaten:
  - `favicon_url`
  - `favicon_host`
  - `favicon_last_checked_at`

4. `dashboard_tasks`
- Aufgaben je Tasks-Widget
- bindet an `dashboard_widgets.id`

5. `dashboard_notes`
- Notizen je Notes-Widget
- bindet an `dashboard_widgets.id`

## Warum widget_id statt nur user_id?

So ist Mehrfachverwendung pro Typ sauber möglich.  
Alle Datensätze (Links/Aufgaben/Notizen) hängen an einer konkreten Widget-Instanz und sind dadurch:

- klar einer Dashboard-Konfiguration zugeordnet
- unabhängig sortierbar
- pro Instanz aktivierbar/deaktivierbar

## Sicherheitsannahmen für Links/Favicons (vorbereitet)

Bei späteren CRUD-/Fetch-Features gelten diese Regeln:

1. URL-Protokolle hart einschränken:
- nur `http` und `https`
- blockieren: `javascript:`, `data:`, `file:` usw.

2. Link-Titel ausschließlich als Text behandeln:
- kein HTML speichern
- Ausgabe immer escaped

3. Favicon-Ermittlung nur serverseitig:
- keine clientseitigen unsicheren Direkt-Fetches
- Host/URL validieren und whitelisten
- Netzwerkzugriffe begrenzen (Timeout, Redirect-Limit, Größenlimit)

4. Nutzerisolation:
- jede Operation auf Widgets/Items muss gegen `user_id` autorisiert werden

## Nicht Teil dieser Ausbaustufe

- vollständige Dashboard-UI mit Drag/Drop
- Favicon-/Title-Fetch-Engine
- Aufgaben-Wiederholungslogik
- vollständige Notizen-/Aufgaben-/Links-CRUD-Oberflächen
- Share-Funktion zwischen Benutzern

