# ModulNest-Module

ModulNest besteht aus Core- und optionalen nativen Modulen. Native Module werden unter `app/Modules/` auto-discovered, in der Modulverwaltung registriert und können je nach Modul aktiviert werden. Zugriff wird zentral als `public`, `user` oder `admin` im Router durchgesetzt.

## Produktmodule

- [Admin](admin.md), [Authentifizierung](auth.md), [Modulverwaltung](modules.md) und [Benutzerprofil](user.md)
- [Banking](banking.md), [Dashboard](dashboard.md), [Export / Import](data-portability.md)
- [Startseite](homepage.md), [News](news.md), [Pages](pages.md), [Sneak Preview](sneak-preview.md)
- [Systeminfo](systeminfo.md), [Tools](tools.md), [Updates](updates.md), [Logs](logs.md)
- [Wiki](wiki.md)

Für die Entwicklung eigener nativer Module gelten die verbindlichen Regeln unter [Entwicklung](../development/README.md). Legacy-Anwendungen bleiben bewusst getrennt und verwenden die [Legacy-CSRF-Bridge](../development/README.md#wichtigster-einstieg).

Mitgelieferte Produktmodule werden heute mit dem ModulNest-Gesamtpaket versioniert. Eigene Marketplace-Pakete und unabhängige Modulversionen sind noch nicht implementiert.
