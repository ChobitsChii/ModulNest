# ModulNest Datenbank

Die verbindliche Schemaquelle ist [`../app/Database/schema.sql`](../app/Database/schema.sql).

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
- `webauthn_credentials`
- `recovery_codes`
- `news_entries`
- Dashboard-Tabellen für Widgets, Links, Aufgaben und Notizen
- Mail-Tabellen, falls das Mail-Modul in einem Release enthalten ist
- Banking-Tabellen, falls das Banking-Modul in einem Release enthalten ist

Der Public-Export sanitisiert das Schema: Demo-, Test- und private Nutzdaten gehören nicht in das öffentliche Repository.

## Migrationen

Der Bootstrap-Installer führt aktuell das zentrale Schema aus. Ein späteres Release kann ein feineres Migrationssystem ergänzen.
