#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENV_PY="$ROOT_DIR/.local/e2e/.venv/bin/python"
VENV_PYTEST="$ROOT_DIR/.local/e2e/.venv/bin/pytest"
LOCAL_ENV_FILE="$ROOT_DIR/.local/e2e/local.env"

if [[ ! -x "$VENV_PY" ]]; then
  echo "Fehlend: $VENV_PY"
  echo "Bitte zuerst Setup ausfuehren (venv + pip + playwright install chromium)."
  exit 1
fi

if [[ ! -x "$VENV_PYTEST" ]]; then
  echo "Fehlend: $VENV_PYTEST"
  echo "Bitte pytest im lokalen E2E-venv installieren."
  exit 1
fi

read_local_env() {
  local key="$1"
  if [[ -f "$LOCAL_ENV_FILE" ]]; then
    local line
    line="$(grep -E "^${key}=" "$LOCAL_ENV_FILE" | tail -n 1 || true)"
    if [[ -n "$line" ]]; then
      echo "${line#*=}"
      return 0
    fi
  fi
  return 1
}

BASE_URL="${MODULON_E2E_BASE_URL:-$(read_local_env MODULON_E2E_BASE_URL || echo "http://127.0.0.1:8080")}"

SERVER_PID=""
cleanup() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

if [[ "$BASE_URL" == "http://127.0.0.1:8080" || "$BASE_URL" == "http://localhost:8080" ]]; then
  if ! curl -fsS "${BASE_URL}/" >/dev/null 2>&1; then
    mkdir -p "$ROOT_DIR/.local/e2e/tmp"
    nohup php -S 127.0.0.1:8080 -t "$ROOT_DIR/public" > "$ROOT_DIR/.local/e2e/tmp/php-server.log" 2>&1 &
    SERVER_PID="$!"
    sleep 1
    echo "Lokaler PHP-Server gestartet: ${BASE_URL} (PID ${SERVER_PID})"
  fi
fi

exec "$VENV_PYTEST" "$@"
