# Modulon E2E (lokal)

Dieses Verzeichnis enthält **versionierte** Browser-E2E-Tests (pytest + Playwright).

Lokale, nicht versionierte Artefakte liegen unter:

- `.local/e2e/artifacts` (Screenshots/Videos/Traces)
- `.local/e2e/state` (optional Storage-State)
- `.local/e2e/tmp` (temporäre Hilfsdaten)

## Voraussetzungen

1. Python 3
2. Lokales venv in `.local/e2e/.venv`
3. Pakete aus `tests/e2e/requirements.txt`
4. Playwright Chromium installiert
5. Modulon lokal erreichbar (z. B. `http://127.0.0.1:8080` oder Intranet-URL)

## Konfiguration

Die Tests lesen Einstellungen aus Umgebungsvariablen oder optional aus:

- `.local/e2e/local.env`

Unterstützte Variablen:

- `MODULON_E2E_BASE_URL` (Default: `http://127.0.0.1:8080`)
- `MODULON_E2E_LOGIN`
- `MODULON_E2E_PASSWORD`

Beispiel `.local/e2e/local.env` (nicht versioniert):

```env
MODULON_E2E_BASE_URL=http://lenovo-tc-m910q.lan
MODULON_E2E_LOGIN=dein-testuser
MODULON_E2E_PASSWORD=dein-testpasswort
```

## Start

```bash
.local/e2e/.venv/bin/pytest
```

Nur Public-Smoke:

```bash
.local/e2e/.venv/bin/pytest tests/e2e/test_smoke_public.py
```

Nur Mail/Auth-Smoke:

```bash
.local/e2e/.venv/bin/pytest -m auth
```

