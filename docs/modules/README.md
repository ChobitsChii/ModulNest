# ModulNest-Module

ModulNest 2 besteht aus Core und optionalen, unabhängig versionierten Modul-v2-
Paketen. Diese werden über den signierten Modul-Katalog und denselben zentralen
Lifecycle installiert, aktualisiert und aktiviert. `app/Modules`-Discovery bleibt
nur für Core-/v1-Kompatibilität erhalten. Zugriff wird zentral als `public`, `user`
oder `admin` im Router durchgesetzt.

## Produktmodule

- [Admin](admin.md), [Authentifizierung](auth.md), [Modulverwaltung](modules.md) und [Benutzerprofil](user.md)
- [Banking](banking.md), [Dashboard](dashboard.md), [Export / Import](data-portability.md)
- [Startseite](homepage.md), [News](news.md), [Pages](pages.md), [Sneak Preview](sneak-preview.md)
- [Systeminfo](systeminfo.md), [Tools](tools.md), [Updates](updates.md), [Logs](logs.md)
- [Wiki](wiki.md)

Für neue Module gilt die kanonische
[Modul-v2-Authoring-Anleitung](../development/module-v2-authoring.md).
Legacy-Anwendungen bleiben bewusst getrennt und verwenden die zentrale
Legacy-CSRF-Bridge.

Die elf öffentlichen Produktmodule werden im separaten Repository
`ChobitsChii/ModulNest-Modules` unabhängig vom Core veröffentlicht.
