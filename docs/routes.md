# ModulNest Routen

Diese Übersicht nennt wichtige Routen des aktuellen Public-Exports. Module können eigene Routen registrieren.

## Start und Auth

- `GET /`
- `GET|POST /login`
- `GET /login/2fa`
- `POST /login/2fa/totp`
- `POST /login/2fa/recovery`
- `POST /webauthn/login/options`
- `POST /webauthn/login/verify`
- `GET|POST /internal/register`
- `POST /logout`

## Benutzerbereich

- `GET /profil`
- `GET /profil/security`
- `GET /profil/settings`
- `POST /profil/update`
- `POST /profil/password`
- `POST /profil/settings`

Kompatible Sicherheitspfade können unter `/account/security/*` existieren. Die primäre UI nutzt `/profil/security`.

## Dashboard

- `GET /dashboard`
- `POST /dashboard/links/*`
- `POST /dashboard/tasks/*`
- `POST /dashboard/notes/*`

## News

- `GET /news`
- `GET /news/{slug}`

## Admin

- `GET /admin`
- `GET /admin/modules`
- `GET /admin/modules/{id}/edit`
- `GET /admin/users`
- `GET /admin/users/{id}/edit`
- `POST /admin/settings/registration/toggle`
- `GET /admin/news`
- `GET /admin/news/create`
- `GET /admin/news/{id}/edit`
- `GET /systeminfo`

## Weitere Module

Andere Module können je nach Release-Auswahl weitere Routen bereitstellen. Die
tatsächlich enthaltenen Module beschreibt die beim Release erzeugte
`modulnest-package.json`; Aufbau und Export sind in
[Release & Export](release.md) dokumentiert.
