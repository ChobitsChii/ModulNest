# ModulNest Export Summary

- Zielpfad: `/srv/http/modulnest`
- Erstellt am: `2026-09-09T09:44:54+02:00`
- Core-Module: `Admin Auth Modules User Updates`
- Optionale Module: `(keine)`

## Ausgeschlossen

- `.env`, `.local`, `vendor`, `storage`-Nutzdaten, Runtime-Assets, Logs, Backups, IDE-Dateien, private Testdaten.
- `app/Legacy` enthält nur `.gitkeep`.
- `app/Database/schema.sql` wurde als Kompatibilitäts-Aggregat aus Core-Schema, Core-Seeds und den ausgewählten Modul-Schemas/-Seeds erzeugt.
- Nicht ausgewählte Module bringen keine Modul-Schema-Dateien in den Export.

- Die elf öffentlichen Modul-v2-Produktmodule werden ausschließlich als signierte Katalogpakete ausgeliefert und sind nicht im Core-Export gebündelt.

- `install.php` ist als einzelner Bootstrap-Installer enthalten.

- `recovery.php` stellt den geschützten Recovery-Einstieg bereit.

- `modulnest-package.json` beschreibt die im Export enthaltenen Pflicht- und optionalen Module.

## Nächste Schritte

1. Zielordner manuell reviewen.
2. Im Zielrepo `composer install` ausführen.
3. `.env.example` nach `.env` kopieren und echte lokale Werte setzen.
4. Erst nach Review im Zielrepo committen und nach `ChobitsChii/ModulNest` pushen.
