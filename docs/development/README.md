# Modulentwicklung

## Modul v2 – aktueller Standard

Neue öffentliche Produktmodule für ModulNest 2 sind unabhängig versionierte,
paket- und katalogverwaltete Modul-v2. Der verbindliche Einstieg ist:

- [Modul-v2-Authoring](module-v2-authoring.md) – kanonischer, vollständiger
  Vertrag für Manifest, Entrypoint, Daten, Capabilities, Tests und Veröffentlichung.
- [AI-Quickstart](module-v2-ai-checklist.md) – kompakte Checkliste für Coding-KIs.
- [Modul-v2-Pakete](module-packages-v2.md) – Laufzeit- und Lifecyclesicht.
- [Modul-v2 veröffentlichen](module-v2-publishing.md) – Erstaufnahme und
  unabhängige Folgereleases offizieller Module.
- [Signierter Modulkatalog](module-catalog-v2.md) – Format, Trust Store und LKG.
- [Statische Release-Mirrors](static-release-mirrors.md) – Distribution und
  atomare Spiegelung.

Neuer Modulcode gehört nicht nach `app/Modules`, globale `app/Views` oder globale
Assetpfade. Das 1.x-ExampleNotes ist keine Modul-v2-Vorlage.

## Modul v1 und Legacy – nur Kompatibilität

Die folgenden Dokumente erklären den weiterhin unterstützten historischen
Native-/Legacy-Vertrag. Sie dürfen nicht als Startpunkt für ein neues Katalogmodul
verwendet werden:

- [Historischer Modul-v1-Vertrag](module-spec.md)
- [Historischer v1-Generator](create-module.md)
- [Historischer v1-Lifecycle](lifecycle.md)
- [v1-ExampleNotes](example-module.md)
- [Security-Grundlagen](security.md) und [Test-Grundlagen](testing.md), soweit ihre
  Regeln nicht durch die kanonische v2-Anleitung präzisiert werden.

Ergänzend: [Technische Architektur](../technical/tech-architecture.md),
[Datenbank](../database.md), [Release/Public Export](../release.md) und
[projektweite E2E-Tests](../testing.md).
