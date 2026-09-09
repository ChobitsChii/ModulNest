# Statische Release-Mirrors

GitHub bleibt Source of Truth. Die vorgesehenen statischen Endpunkte sind:

- `https://updates.modulnest.de/core/` für Core-Feeds und Core-Artefakte
- `https://repo.modulnest.de/` für den signierten Modulkatalog und Modul-Pakete

Der vorbereitete Core-Sync lädt beide GitHub-Feeds und ihre Pakete, prüft
SHA-256 und veröffentlicht einen vollständigen Staging-Stand atomar. Der
Repository-Sync aus `ChobitsChii/ModulNest-Modules` prüft Root-Signatur,
Sequence/Replay-Schutz, Indexhashes sowie SHA-256, Größe und Signatur jedes
Pakets. Bei jedem Fehler bleibt der Last-Known-Good-Stand aktiv. Auf dem
Webserver sind keine privaten Signing-Keys erforderlich.

Vorgesehene DocumentRoots:

- `updates.modulnest.de`: `/srv/http/modulnest-updates`
- `repo.modulnest.de`: `/srv/http/modulnest-repository`

Beide Hosts liefern ausschließlich statische Dateien via HTTPS. Vor der
Umschaltung müssen passende VHosts und Zertifikate mit dem jeweiligen Hostnamen
eingerichtet werden. Bis dahin bleiben die Anwendungskonstanten auf den
verifizierbaren GitHub-Endpunkten; ein fehlerhafter oder unvollständiger Mirror
wird nicht als Runtime-Abhängigkeit aktiviert.

Core-Sync:

```bash
php tools/release/sync-core-update-mirror.php \
  --target /srv/http/modulnest-updates/core \
  --public-base https://updates.modulnest.de/core
```

Der Modul-Sync wird im Modulrepository ausgeführt:

```bash
php tools/sync-repository-mirror.php \
  --target /srv/http/modulnest-repository
```
