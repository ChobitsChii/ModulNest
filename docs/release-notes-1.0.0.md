# ModulNest 1.0.0

ModulNest 1.0.0 ist der erste Stable-Release des öffentlichen Produkts.

## Seit 0.9.0

- Zentraler CSRF-Schutz für native und Legacy-Schreibrouten.
- CSRF-Migration für Auth, Admin, Dashboard, Mail und die übrigen nativen Module.
- Sichere Standard-Session- und Remember-Me-Cookies, Auth-Rate-Limiting und verbesserte Redaktion sensibler Logwerte.
- Mindest-Security-Headers sowie produktionssichere WebAuthn-RP-ID-Konfiguration.
- Aktualisierte, auditierte Composer-Abhängigkeiten.
- Fail-Closed-Vertrag für fehlgeschlagene Updates und Migrationen.

## Upgrade von 0.9.0

Der Migrationsstand ist unverändert; für dieses Release sind keine neuen Datenbankmigrationen erforderlich. Vor jedem Update bleiben ein Datenbankbackup und die Prüfung des Dateibackups empfohlen.
