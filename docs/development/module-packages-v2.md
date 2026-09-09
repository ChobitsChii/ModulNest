# Modul packages v2 (development)

This page documents the implemented local vertical slice. The normative design
and security boundaries live in [module-system-v2.md](module-system-v2.md).

## Build and inspect

Packages are deterministic ZIP files whose root contains `module.json`. A local
builder uses `ModulePackageBuilder`; `ModulePackageInspector` validates the
SHA-256 (when supplied), archive limits, paths, file types, UTF-8 manifest and
all supported manifest contracts before installation. Test fixtures are under
`tests/Fixtures/module-packages-v2/`; test-only material must remain below
`tests/Fixtures` and must never be used as a production trust key.

## Local lifecycle CLI

```text
php bin/modules.php list [--json]
php bin/modules.php inspect example.example-notes [--json]
php bin/modules.php install package.zip [--sha256 HASH] [--json]
php bin/modules.php update package.zip [--sha256 HASH] [--json]
php bin/modules.php activate|deactivate|uninstall|purge MODULE_ID [--json]
```

Installation and update stage immutable releases below
`modules/<id>/releases/<version>-<hash>`, validate the entrypoint in a fresh PHP
process, apply immutable module migrations, publish versioned assets and only
then switch the registry. Each request captures one `ModuleRuntimeSnapshot`.
The 1.x loader remains active during migration.

Uninstall retains registered data and migration history. Purge is explicit: it
drops only Core-registered resources and deletes `schema_migrations` rows whose
`module_key` exactly matches the immutable module ID; operation audit records
remain. Database protection is provided through
`DatabaseBackupProviderInterface`, with the verified PDO logical provider as
the built-in fallback.

Clean installation first runs Core migrations and then invokes the same
`ModuleLifecycleService` for every selected local/catalog package. No alternate
copy-or-schema bypass is supported.

## Catalog installation

The Admin entry **Modul-Katalog** is the primary interface for catalog-managed
packages. Its Discover, Installed and Updates views are read models over the
same installation registry and lifecycle service used by the technical 1.x
module administration. A catalog install is deliberately inactive. Update,
activation, deactivation, retain-uninstall, reinstall and purge all go through
`ModuleLifecycleService`; a managed module's ID, route and release cannot be
edited in the old administration.

Uninstall means “remove code, retain data”. The separate purge action requires
the exact module ID, creates and verifies a logical database backup first, and
then deletes only resources recorded in the Core ownership ledger. A retained
module remains visible as **Daten vorhanden** and can be reinstalled when its
declared data-schema constraint is compatible.

The complete signed catalog, trust, LKG and operator contract is documented in
[module-catalog-v2.md](module-catalog-v2.md).

## First product package: Wiki

`modulnest.wiki` starts its independent package lifecycle at `1.0.0`; this is
not the Core version. The self-contained sources live in
`modules-src/wiki/<version>/` and package code, views, assets, migrations and
license together. Runtime content lives only below
`storage/modules/modulnest.wiki/`; immutable release assets are published below
`public/assets/modules/modulnest.wiki/<release>/`.

Adoption recognizes exactly one known, unmodified 1.2.0 Wiki. It verifies the
legacy file inventory, all owned tables and the five historic migration rows,
copies storage before mutation, maps `wiki` to `modulnest.wiki`, records the v2
baseline without re-running DDL, and only then installs the package. Any failure
leaves the old Wiki active. The `1.0.1` catalog release proves an independent
Wiki-only update; retain/reinstall preserves sources, pages and search index,
while explicit purge removes only the declared Wiki resources.

The Wiki declares a Data Portability provider. It exports/imports safe GitHub
source configuration only. Local paths, synchronized content, cache, search
index and sync history are deliberately excluded.

## Product package wave: Logs, Systeminfo and News

The next independently versioned packages are `modulnest.logs`,
`modulnest.systeminfo` and `modulnest.news`, each with releases `1.0.0` and
`1.0.1`. Their code, views, migrations and providers live exclusively below
`modules-src/<name>/<version>/`; old product-discoverable copies are retained
only in `tests/Fixtures/legacy-modules-1.2.0/` for adoption tests.

- Logs has data schema 0 and an empty ownership declaration. It may read
  `storage/logs`, but never owns or purges Core log files.
- Systeminfo has data schema 0 and owns no diagnostic or Core data. It consumes
  existing Core health, settings and module-status services.
- News has data schema 1 and owns exactly `news_entries`. Its package migration
  creates schema and seeds on a clean install; adoption maps the historic News
  migration and records the package baselines without executing them again.

News publishes its `data_portability` provider through the installed release
manifest. The central DataPortability module discovers the provider from active
managed releases and therefore contains no concrete News class reference.
Catalog detail links appear only while the capability and central module are
actually available.

Runtime installations require writable `modules/`, `public/assets/modules/`
and `storage/modules/` roots for the web process. Adoption checks these roots
before changing registry or module data.

For all four migrated modules, adoption verifies a complete known-file hash
inventory, preserves active state and owned data, installs and health-checks a
signed release, and switches the runtime atomically. The managed route prefix
then excludes the published v1 copy from runtime discovery without requiring
the web process to modify repository files. Unknown or modified code remains
Legacy/Manual.
