# Systeminfo

**Zugriff:** Administratoren.

Zeigt Systeminformationen und Healthchecks für den Betrieb.

Systeminfo ist das eigenständige Paket `modulnest.systeminfo`, aktuell Version
`1.1.0`. Es verwendet die
stabilen Core-Verträge für Health, Einstellungen und Modulstatus, besitzt aber
keine Core-Diagnosedaten (Schema 0, leeres Ownership-Ledger). Adoption erhält
den bisherigen Aktivzustand; Deinstallation und Purge entfernen keine
Systeminformationen.
