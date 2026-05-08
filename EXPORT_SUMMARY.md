# ModulNest Export Summary

- Zielpfad: `/srv/http/modulnest`
- Erstellt am: `2026-05-08T14:12:13+02:00`
- Core-Module: `Admin Auth Modules`
- Optionale Module: `Dashboard News Systeminfo User`

## Ausgeschlossen

- `.env`, `.local`, `vendor`, `storage`-Nutzdaten, Runtime-Assets, Logs, Backups, IDE-Dateien, private Testdaten.
- `app/Legacy` enthält nur `.gitkeep`.
- `app/Database/schema.sql` wurde für den Export sanitisiert: keine News-/Karten-Demo-Seeds.

- `install.php` ist als einzelner Bootstrap-Installer enthalten.

- `modulnest-package.json` beschreibt die im Export enthaltenen Pflicht- und optionalen Module.

## Nächste Schritte

1. Zielordner manuell reviewen.
2. Im Zielrepo `composer install` ausführen.
3. `.env.example` nach `.env` kopieren und echte lokale Werte setzen.
4. Erst nach Review im Zielrepo committen und nach `ChobitsChii/ModulNest` pushen.
