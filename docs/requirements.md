# ModulNest Anforderungen

Diese Datei beschreibt die globalen Anforderungen des aktuellen ModulNest-Stands. Einzelne Module sollen später eigene Requirements deklarieren können; aktuell sind einige Abhängigkeiten noch global in `composer.json` gebündelt.

Eine lesbare Übersicht der verwendeten Libraries, Frameworks und externen Bausteine steht in [Technologien und Abhängigkeiten](dependencies.md).

## PHP

- PHP `^8.3`

## PHP-Extensions

Aktuell bekannte globale Anforderungen:

- `ext-iconv`
- `pdo`
- `pdo_mysql`
- `mbstring`
- `openssl`
- `json`
- `curl`
- `zip`
- `fileinfo`
- `session`

Hinweise:

- `ext-iconv` steht bereits explizit in `composer.json`.
- `pdo_mysql` wird für die ModulNest-Datenbank benötigt.
- `zip`/`ZipArchive` wird vom Bootstrap-Installer für Release-Pakete benötigt.
- `fileinfo` wird für sichere Upload-/Dateityp-Prüfungen verwendet.

## Composer-Pakete

Aus `composer.json`:

- `robthree/twofactorauth` `^3.0`
- `lbuchs/webauthn` `^2.2`
- `chillerlan/php-qrcode` `^6.0`
- `webklex/php-imap` `^6.2`
- `symfony/mailer` `^8.0`
- `symfony/mime` `^8.0`

## Installationsvarianten

- Source-Release:
  - enthält kein `vendor/`
  - benötigt Composer auf dem Zielsystem
  - Installer führt nur `composer install --no-dev --optimize-autoloader` aus
  - kein `composer update`
- Bundled-Release:
  - enthält `vendor/`
  - benötigt keinen Composer auf dem Zielsystem

## Webserver und PHP-Limits

Der Webserver muss im Produktivbetrieb mit dem DocumentRoot/Webroot auf `public/` zeigen. ModulNest unterstützt PHP-FPM/FastCGI. Deshalb dürfen `php_value`- und `php_flag`-Direktiven nicht in `public/.htaccess` verwendet werden, weil sie unter PHP-FPM/FastCGI einen HTTP-500-Fehler auslösen können.

PHP-Limits wie `upload_max_filesize`, `post_max_size`, `memory_limit` und `max_execution_time` sollen bei Bedarf über das Hosting-Panel, `php.ini`, die PHP-FPM-Pool-Konfiguration oder eine `.user.ini` gesetzt werden. `public/.user.ini.example` ist nur eine Vorlage mit Beispielwerten und enthält keine Secrets.

## Secrets und lokale Konfiguration

- `MAIL_CREDENTIAL_KEY` wird für die Verschlüsselung gespeicherter Mail-Zugangsdaten verwendet.
- Der Key wird pro Installation generiert und darf nicht veröffentlicht oder ins Repository übernommen werden.
- Wenn der Key nachträglich geändert wird, können bestehende gespeicherte Mail-Zugangsdaten ggf. nicht mehr entschlüsselt werden.

## Modulnahe Abhängigkeiten

Einige Composer-Pakete sind fachlich eher modulnah, z. B. Mail-, QR- und 2FA/WebAuthn-Abhängigkeiten. Diese werden aktuell bewusst noch nicht aufgetrennt. Später sollen Module eigene Requirements anmelden können, damit optionale Module sauberer installierbar und aktualisierbar werden.
