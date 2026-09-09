# Systeminfo

**Zugriff:** Administratoren.

Zeigt Systeminformationen und Healthchecks für den Betrieb.

Auf `develop/2.0` ist Systeminfo das eigenständige Paket
`modulnest.systeminfo` in den Versionen `1.0.0` und `1.0.1`. Es verwendet die
stabilen Core-Verträge für Health, Einstellungen und Modulstatus, besitzt aber
keine Core-Diagnosedaten (Schema 0, leeres Ownership-Ledger). Adoption erhält
den bisherigen Aktivzustand; Deinstallation und Purge entfernen keine
Systeminformationen.
