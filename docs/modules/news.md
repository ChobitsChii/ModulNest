# News

**Zugriff:** öffentliche Anzeige, Pflege durch Administratoren.

Veröffentlicht News und Updatehinweise. Inhalte unterstützen sicher gerendertes Markdown; rohe HTML-Eingabe wird nicht ausgeführt.

News ist das eigenständige Paket `modulnest.news`, aktuell Version `1.2.0`.
Es besitzt exakt die Tabelle `news_entries`
(Data-Schema 1), einschließlich Paketmigration und Seeds. Öffentliche Routen,
Admin-CRUD und Markdown bleiben unverändert.

Der Export-/Import-Provider wird über die Manifest-Capability
`data_portability` entdeckt; das zentrale Modul kennt keine konkrete
News-Klasse. Bei der 1.2.0-Adoption bleiben Einträge und Aktivzustand erhalten,
und die historische Migration wird auf die Paket-History gemappt, ohne erneut
zu laufen. Retain/Reinstall erhält die Einträge; erst der explizit bestätigte
Purge löscht `news_entries`.
