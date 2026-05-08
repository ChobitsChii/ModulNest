# Technologien und Abhängigkeiten

ModulNest besitzt einen eigenen Core, nutzt aber bewusst etablierte Libraries und Werkzeuge für Spezialthemen. Das hält den Core schlank und vermeidet, sicherheitskritische oder komplexe Funktionen unnötig selbst zu bauen.

## Backend / PHP

- PHP `^8.3`
- Composer
- PSR-4 Autoloading
- PDO / `pdo_mysql`

## Security / Auth

- PHP `password_hash()` / `password_verify()`
- `robthree/twofactorauth` für TOTP
- `lbuchs/webauthn` für WebAuthn/Passkeys
- `chillerlan/php-qrcode` für QR-Codes

## Mail

- `webklex/php-imap`
- `symfony/mailer`
- `symfony/mime`

## Frontend

- serverseitiges PHP-Rendering
- Bootstrap
- DataTables, soweit im Public-Stand genutzt
- Vanilla JavaScript
- kein verpflichtendes SPA-Framework

## Installation / Updates / Releases

- `ZipArchive`
- GitHub Releases
- `stable.json`
- Source- und Bundled-ZIP-Pakete

## Quellen

`composer.json` bleibt die technische Quelle für Composer-Abhängigkeiten.

`docs/requirements.md` bleibt die Quelle für Systemanforderungen wie PHP-Version, PHP-Extensions und Webserver-Hinweise.
