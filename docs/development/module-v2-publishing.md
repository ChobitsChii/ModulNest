# Neues offizielles Modul-v2 veröffentlichen

Diese Seite beschreibt den aktuellen Maintainer-Workflow für ein neues offizielles
Modul im Repository
[ChobitsChii/ModulNest-Modules](https://github.com/ChobitsChii/ModulNest-Modules).
Sie ergänzt die [kanonische Authoring-Anleitung](module-v2-authoring.md).

## Erstaufnahme

1. Den vollständigen Workspace als `modules-src/<slug>/<version>/` aufnehmen.
   `module.json`, `CHANGELOG.md`, `LICENSE`, Entrypoint und PSR-4-Code müssen im
   Workspace liegen. Ein neues Modul ohne v1-Vorgänger erhält kein `adoption/`.
2. Modul-, Route-, Security- und Lifecycle-Tests ausführen. Manifest-ID und
   Publisher müssen übereinstimmen, beispielsweise
   `modulnest.repository-manager` für den ausdrücklich autorisierten Publisher
   `modulnest`.
3. Eine vollständige Staging-Kopie bzw. einen Checkout des zuletzt veröffentlichten
   `ModulNest-Modules`-Stands anlegen. Nicht mit einem leeren Veröffentlichungsbaum
   beginnen: frühere Paketversionen bleiben unveränderliche historische Artefakte.
4. Den Production-Publisher mit dem vollständigen Workspace-Root ausführen. Er
   entdeckt neue IDs; keine PHP-Allowlist muss erweitert werden:

```bash
php tools/build-module-catalog.php \
  --target /path/to/complete-ModulNest-Modules-staging \
  --published-root /path/to/current-ModulNest-Modules \
  --catalog-id modulnest.production \
  --publisher modulnest \
  --workspace-root /path/to/ModulNest-Modules/modules-src \
  --root-key-file /private/root.key --root-key-id ROOT_ID \
  --release-key-file /private/release.key --release-key-id RELEASE_ID \
  --sequence-state /private/catalog-sequence \
  --cache-root /private/catalog-cache \
  --expires-at RFC3339
```

`--published-root` ist im Production-Modus Pflicht. Es zeigt auf den letzten
veröffentlichten Repositorybestand und ist die Referenz für die Unveränderlichkeit
bereits publizierter Paketversionen. Target und Published Root dürfen identisch sein;
bevorzugt wird jedoch die vollständige getrennte Staging-Kopie, die erst nach allen
Prüfungen atomar veröffentlicht wird.

Der explizite Parameter `--publisher modulnest` autorisiert nur diesen Namespace
für die zusätzlich entdeckten Workspaces. Ein anderer Publisher erhält dadurch
keinen Trust. Vertrauen entsteht weiterhin ausschließlich durch den konfigurierten
Katalog-Root und seine Production-Signaturen.

## Publisher-Gates

Vor einem Katalogeintrag validiert der Publisher das Manifest mit dem aktuellen
v2-Validator, SemVer und Constraints, Ownership, Changelog und `LICENSE`. Er baut
das ZIP zweimal deterministisch, verlangt gleiche SHA-256-Werte, inspiziert die
Paketstruktur und signiert Paket und Katalog getrennt mit Ed25519.

Existiert `<published-root>/packages/<id>/<version>/<id>-<version>.zip`, muss dessen
SHA-256 exakt dem Neubuild entsprechen. Identische Bytes werden wiederverwendet;
abweichende Bytes brechen den Build ab und lassen das veröffentlichte Paket
unangetastet. Eine Korrektur innerhalb derselben Version ist nicht zulässig:
Workspace-Version und Changelog müssen erhöht werden.

Die elf historischen v1→v2-Module bleiben in ihren bestehenden Definitionen und
Adoptionsmetadaten unverändert. Automatisch entdeckte Workspaces dürfen diese
Definitionen nicht ersetzen.

## Spätere unabhängige Releases

Für eine neue Modulversion:

1. neues Verzeichnis `modules-src/<slug>/<new-version>/` anlegen;
2. frühere veröffentlichte Workspaces und Migrationen unverändert lassen;
3. `module.json` und `CHANGELOG.md` auf die neue SemVer ergänzen;
4. Modul-/Lifecycle-Test ausführen;
5. denselben Publisherbefehl mit erhöhtem Katalog-Sequence-State ausführen;
6. Paket, Modulindex, Root/Signatur verifizieren und atomar veröffentlichen;
7. Modul-Tag `<module-id>-v<version>` setzen.

Das veröffentlicht ausschließlich einen neuen Modul- und Katalogstand. Ein
ModulNest-Core-Release ist dafür nicht erforderlich. Private Keys bleiben außerhalb
von Repository, Workspace, Webroot und Buildartefakten.
