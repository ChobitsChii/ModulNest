# ModulNest Tests

Versionierte Browser-E2E-Tests liegen unter [`../tests/e2e`](../tests/e2e).

Lokale Testdaten, Browserprofile, Storage-State, Screenshots, Videos und Traces gehören nicht ins Repository. Dafür ist lokal `.local/e2e/` vorgesehen.

## Setup

```bash
python -m venv .local/e2e/.venv
.local/e2e/.venv/bin/python -m pip install -r tests/e2e/requirements.txt
.local/e2e/.venv/bin/python -m playwright install chromium
```

Optionale lokale Konfiguration:

```bash
cp tests/e2e/local.env.example .local/e2e/local.env
```

Beispielwerte:

```env
MODULON_E2E_BASE_URL=http://127.0.0.1:8080
MODULON_E2E_LOGIN=dein-testuser
MODULON_E2E_PASSWORD=dein-testpasswort
```

Die Variablennamen tragen aus Kompatibilitätsgründen noch den internen Core-/Arbeitsnamen.

## Start

```bash
./scripts/e2e.sh
```

Wenn `MODULON_E2E_BASE_URL` auf `http://127.0.0.1:8080` oder `http://localhost:8080` steht und dort kein Server läuft, startet das Script automatisch einen temporären lokalen PHP-Server mit `-t public`.
