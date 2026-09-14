# Logs

**Zugriff:** Administratoren.

Zeigt erlaubte Tages- und Archivlogs aus `storage/logs/` sicher an. Gzip-Archive sind lesbar; beliebige Pfade werden nicht akzeptiert.

Logs ist das eigenständige Paket `modulnest.logs`, aktuell Version `1.2.0`.
Es besitzt keine persistenten Daten (Schema 0,
leeres Ownership-Ledger). Insbesondere bleiben die gelesenen Core-Logs bei
Deinstallation und Purge immer unangetastet. Eine bekannte unveränderte
1.2.0-Kopie kann unter Erhalt ihres Aktivzustands adoptiert werden.
