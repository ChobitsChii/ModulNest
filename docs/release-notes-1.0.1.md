# ModulNest 1.0.1

ModulNest 1.0.1 ist ein Hotfix für den Updateprozess auf Servern mit aktivem
PHP OPcache.

## Enthalten

- Nach erfolgreichen Updates werden kopierte PHP-Dateien best effort über
  `opcache_invalidate(..., true)` invalidiert.
- Falls einzelne Invalidierungen scheitern, kann best effort `opcache_reset()`
  verwendet werden.
- Fehlende oder nicht erlaubte OPcache-Funktionen lassen ein erfolgreiches
  Update nicht fehlschlagen.
- Das Ergebnis des Runtime-Refresh wird im Update-Status und Update-Log
  dokumentiert.
- Eine zusätzliche Regression schützt die Redaction des Cookies
  `modulnest_remember`.

## Datenbankmigrationen

Für dieses Release sind keine Datenbankmigrationen erforderlich.

## Upgrade-Hinweis

Bei einem direkten Update von 1.0.0 auf 1.0.1 kann der bereits laufende
1.0.0-Updater auf Servern mit sehr restriktivem OPcache – insbesondere bei
`opcache.validate_timestamps=0` – die neue Invalidierungslogik noch nicht
verwenden. In diesem seltenen Fall kann direkt nach dem Update einmalig ein
PHP-FPM-/Apache-/OPcache-Reload oder ein erneutes Laden erforderlich sein.

Ab installierter Version 1.0.1 wird der Runtime-Refresh für zukünftige Updates
automatisch verwendet.
