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

## Zweck

Diese Datei ist ein Maintainer-/Review-Artefakt für den Public-Export. Sie bleibt in `docs/`, damit der Repo-Root übersichtlich bleibt.

## Nächste Schritte

1. Zielordner manuell reviewen.
2. Release-Pakete bauen.
3. `build/update/stable.json` prüfen.
4. Erst nach Review committen und nach `ChobitsChii/ModulNest` pushen.
