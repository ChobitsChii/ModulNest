# ModulNest Datenbank

Die verbindliche Schemaquelle für neue Installationen ist seit ModulNest 0.7.0 aufgeteilt:

- Core-Schema: [`../app/Database/schema/core.sql`](../app/Database/schema/core.sql)
- Core-Seeds: [`../app/Database/seeds/core.sql`](../app/Database/seeds/core.sql)
- Modul-Schemas und Modul-Seeds: `app/Modules/<Modul>/Database/schema.sql` und optional `seeds.sql`

[`../app/Database/schema.sql`](../app/Database/schema.sql) bleibt als Kompatibilitäts-/Gesamtschema im Entwicklungsrepo erhalten. Der Bootstrap-Installer nutzt aber die aufgeteilte Struktur und führt nur Schemas/Seeds der ausgewählten Module aus.

## Tabellenüberblick

Wichtige Tabellen im aktuellen Stand:

- `users`
- `roles`
- `permissions`
- `user_role`
- `role_permission`
- `remember_tokens`
- `modules`
- `app_settings`
- `schema_migrations`
- `webauthn_credentials`
- `recovery_codes`
- `news_entries`
- Dashboard-Tabellen für Widgets, Links, Aufgaben und Notizen; Aufgaben und Notizen verwenden `archived_at` für den Archivstatus
- Mail-Tabellen, falls das Mail-Modul in einem Release enthalten ist
- Banking-Tabellen, falls das Banking-Modul in einem Release enthalten ist

Der Public-Export erzeugt ein kompatibles Aggregat nur aus Core und den ausgewählten öffentlichen Modulen. Demo-, Test- und private Nutzdaten gehören nicht in das öffentliche Repository.

## Migrationen

Seit der 0.7.0-Vorbereitung gibt es eine erste echte Migrationsgrundlage:

- Migrationstabelle: `schema_migrations`
- Core-Migrationen: `app/Database/migrations/*.php`
- Modul-Migrationen: `app/Modules/<Modul>/Database/Migrations/*.php`
- Zentraler Runner: `Modulon\Core\Database\MigrationRunner`

Migrationen sind PHP-Dateien, führen idempotente Strukturänderungen aus und werden über `migration_key` nur einmal markiert. Für 0.7.0 sichern sie Core/Auth/User/Modules sowie News, Dashboard, Banking und SneakPreview ab. Seit 0.8.1 ergänzt eine Dashboard-Migration `archived_at` für Aufgaben und Notizen. Mail und FantasyCards gehören nicht zum Public-Default und werden nur migriert, wenn ihre Modul-Migrationen ausdrücklich im Paket/Modulset enthalten sind.

Der Bootstrap-Installer führt weiterhin Core-Schema, ausgewählte Modul-Schemas, Core-Seeds und ausgewählte Modul-Seeds aus. Der Updater führt nach dem Dateiupdate während Maintenance den MigrationRunner aus, sofern eine Datenbankverbindung verfügbar ist.

Zusätzlich führt der App-Start den MigrationRunner best-effort einmal pro Code-Version aus und schreibt danach `storage/migrations/<version>.done`. Das ist bewusst als Brücke für Installationen gedacht, deren alter Updater die neue MigrationRunner-Integration beim Sprung auf 0.7.0 noch nicht selbst ausführen kann.
