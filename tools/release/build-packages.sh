#!/usr/bin/env bash
set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
readonly DEFAULT_PUBLIC_TARGET="/srv/http/modulnest"
readonly DEFAULT_CHANNEL="alpha"

PUBLIC_TARGET="$DEFAULT_PUBLIC_TARGET"
OUTPUT_DIR=""
METADATA_FILE=""
VERSION=""
CHANNEL="$DEFAULT_CHANNEL"
BASE_URL="https://github.com/ChobitsChii/ModulNest/releases/download"
ASSUME_YES=0
KEEP_WORK=0

usage() {
    cat <<EOF
Usage: $SCRIPT_NAME [--public-target /srv/http/modulnest] [--output /pfad] [--metadata /pfad]
                   [--version 0.7.3] [--channel alpha] [--base-url URL] [--yes] [--keep-work]

Baut installierbare ModulNest-ZIP-Pakete aus einem bereits bereinigten Public-Export.

Pakete:
  modulnest-source-VERSION.zip    Source ohne vendor/, benötigt Composer auf dem Zielsystem.
  modulnest-bundled-VERSION.zip   Source plus vendor/, benötigt keinen Composer auf dem Zielsystem.

Optionen:
  --public-target PATH  Bereinigter ModulNest-Export. Default: $DEFAULT_PUBLIC_TARGET
  --output PATH         Release-Ausgabeordner. Default: PUBLIC_TARGET/build/releases
  --metadata PATH       Update-Metadaten-JSON. Default: PUBLIC_TARGET/build/update/stable.json
  --version VERSION     Version überschreiben. Default: modulnest-package.json bzw. app/Config/version.php
  --channel CHANNEL     Release-Channel. Default: $DEFAULT_CHANNEL
  --base-url URL        Basis-URL für spätere Paket-Downloads.
                        Default: $BASE_URL
  --yes, -y             Nicht interaktiv bestätigen.
  --keep-work           Temporäre Staging-Verzeichnisse behalten.
  -h, --help            Hilfe anzeigen.
EOF
}

fail() {
    printf 'FEHLER: %s\n' "$*" >&2
    exit 1
}

parse_args() {
    while (($# > 0)); do
        case "$1" in
            --public-target)
                [[ $# -ge 2 ]] || fail "--public-target benötigt einen Pfad."
                PUBLIC_TARGET="$2"
                shift 2
                ;;
            --output)
                [[ $# -ge 2 ]] || fail "--output benötigt einen Pfad."
                OUTPUT_DIR="$2"
                shift 2
                ;;
            --metadata)
                [[ $# -ge 2 ]] || fail "--metadata benötigt einen Pfad."
                METADATA_FILE="$2"
                shift 2
                ;;
            --version)
                [[ $# -ge 2 ]] || fail "--version benötigt eine Version."
                VERSION="$2"
                shift 2
                ;;
            --channel)
                [[ $# -ge 2 ]] || fail "--channel benötigt einen Wert."
                CHANNEL="$2"
                shift 2
                ;;
            --base-url)
                [[ $# -ge 2 ]] || fail "--base-url benötigt eine URL."
                BASE_URL="${2%/}"
                shift 2
                ;;
            --yes|-y)
                ASSUME_YES=1
                shift
                ;;
            --keep-work)
                KEEP_WORK=1
                shift
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                fail "Unbekannte Option: $1"
                ;;
        esac
    done
}

require_tools() {
    command -v rsync >/dev/null 2>&1 || fail "rsync ist nicht verfügbar."
    command -v sha256sum >/dev/null 2>&1 || fail "sha256sum ist nicht verfügbar."
    command -v php >/dev/null 2>&1 || fail "php-cli ist nicht verfügbar."
    if ! command -v zip >/dev/null 2>&1 && ! command -v python3 >/dev/null 2>&1; then
        fail "Weder zip noch python3 ist verfügbar. Eines davon wird für den Paketbau benötigt."
    fi
}

require_public_export() {
    [[ "$PUBLIC_TARGET" = /* ]] || fail "--public-target muss absolut sein."
    [[ -d "$PUBLIC_TARGET" ]] || fail "Public-Export nicht gefunden: $PUBLIC_TARGET"
    [[ -f "$PUBLIC_TARGET/composer.json" ]] || fail "composer.json fehlt im Public-Export."
    [[ -f "$PUBLIC_TARGET/composer.lock" ]] || fail "composer.lock fehlt im Public-Export."
    [[ -f "$PUBLIC_TARGET/modulnest-package.json" ]] || fail "modulnest-package.json fehlt. Bitte Export-Script neu ausführen."
    [[ -f "$PUBLIC_TARGET/install.php" ]] || fail "install.php fehlt im Public-Export."
}

detect_version() {
    if [[ -n "$VERSION" ]]; then
        return
    fi
    VERSION="$(php -r '$j=json_decode((string) file_get_contents($argv[1]), true); echo (string) ($j["version"] ?? "");' "$PUBLIC_TARGET/modulnest-package.json")"
    if [[ -z "$VERSION" && -f "$PUBLIC_TARGET/app/Config/version.php" ]]; then
        VERSION="$(grep -E "'version'[[:space:]]*=>" "$PUBLIC_TARGET/app/Config/version.php" | head -n 1 | sed -E "s/.*'([^']+)'.*/\\1/" || true)"
    fi
    [[ -n "$VERSION" ]] || fail "Version konnte nicht ermittelt werden. Bitte --version setzen."
}

normalize_paths() {
    OUTPUT_DIR="${OUTPUT_DIR:-$PUBLIC_TARGET/build/releases}"
    METADATA_FILE="${METADATA_FILE:-$PUBLIC_TARGET/build/update/stable.json}"
    [[ "$OUTPUT_DIR" = /* ]] || fail "--output muss absolut sein."
    [[ "$METADATA_FILE" = /* ]] || fail "--metadata muss absolut sein."
}

confirm_build() {
    printf 'ModulNest Release-Pakete\n'
    printf '  Version:       %s\n' "$VERSION"
    printf '  Channel:       %s\n' "$CHANNEL"
    printf '  Public Export: %s\n' "$PUBLIC_TARGET"
    printf '  Output:        %s\n' "$OUTPUT_DIR"
    printf '  Metadata:      %s\n' "$METADATA_FILE"
    printf '  Pakete:        source, bundled\n\n'

    if [[ "$ASSUME_YES" -eq 1 ]]; then
        return
    fi
    read -r -p "Pakete bauen? [y/N] " answer
    [[ "$answer" =~ ^[JjYy]$ ]] || fail "Abgebrochen."
}

copy_public_export_to_staging() {
    local staging="$1"
    mkdir -p "$staging"
    rsync -a \
        --exclude='.git' \
        --exclude='build' \
        --exclude='vendor' \
        --exclude='.env' \
        --exclude='.local' \
        --exclude='**/__pycache__/' \
        --exclude='**/*.pyc' \
        --exclude='tests/e2e/test_fantasy_cards_module.py' \
        "$PUBLIC_TARGET"/ "$staging"/
}

scan_package_tree() {
    local tree="$1"
    local allow_vendor="$2"
    local failed=0

    if [[ "$allow_vendor" != "1" ]] && [[ -d "$tree/vendor" ]]; then
        printf 'vendor/ ist im Source-Staging nicht erlaubt.\n' >&2
        failed=1
    fi

    local suspicious_files
    if [[ "$allow_vendor" == "1" ]]; then
        suspicious_files="$(find "$tree" -path "$tree/vendor" -prune -o -type f \( \
            -name '.env' -o -name '.user.ini' -o -path '*/.local/*' -o -path '*/var/cache/*' -o -path '*/var/log/*' -o \
            -iname '*.log' -o -iname '*.bak' -o -iname '*.backup' -o -iname '*.dump' -o -iname '*.sql.gz' -o \
            -iname '*.tar' -o -iname '*.tar.gz' -o -iname '*.zip' -o -iname '*backup*' -o -iname '*dump*' \
        \) -print)"
    else
        suspicious_files="$(find "$tree" -type f \( \
            -name '.env' -o -name '.user.ini' -o -path '*/.local/*' -o -path '*/var/cache/*' -o -path '*/var/log/*' -o \
            -iname '*.log' -o -iname '*.bak' -o -iname '*.backup' -o -iname '*.dump' -o -iname '*.sql.gz' -o \
            -iname '*.tar' -o -iname '*.tar.gz' -o -iname '*.zip' -o -iname '*backup*' -o -iname '*dump*' \
        \) -print)"
    fi
    if [[ -n "$suspicious_files" ]]; then
        printf 'Verdächtige Datei im Paket-Staging:\n' >&2
        printf '%s\n' "$suspicious_files" >&2
        failed=1
    fi

    if [[ -d "$tree/storage" ]] && find "$tree/storage" -type f ! -name '.gitkeep' | grep -q .; then
        printf 'storage enthält Paketdaten außer .gitkeep:\n' >&2
        find "$tree/storage" -type f ! -name '.gitkeep' >&2
        failed=1
    fi

    if [[ -d "$tree/public/assets/favicons" ]] && find "$tree/public/assets/favicons" -type f ! -name '.gitkeep' | grep -q .; then
        printf 'Runtime-Favicons im Paket-Staging gefunden.\n' >&2
        failed=1
    fi

    if find "$tree" -type d -name '__pycache__' | grep -q .; then
        printf '__pycache__ im Paket-Staging gefunden.\n' >&2
        find "$tree" -type d -name '__pycache__' >&2
        failed=1
    fi

    if find "$tree" -type f -name '*.pyc' | grep -q .; then
        printf '*.pyc im Paket-Staging gefunden.\n' >&2
        find "$tree" -type f -name '*.pyc' >&2
        failed=1
    fi

    if [[ -f "$tree/tests/e2e/test_fantasy_cards_module.py" ]]; then
        printf 'FantasyCards-E2E-Test im Paket-Staging gefunden, obwohl FantasyCards nicht im Public-Release ist.\n' >&2
        failed=1
    fi

    if grep -RInE \
        '(Jennifer@|Grassl|dvU4MiSvAxq6PpW|BEGIN (RSA|OPENSSH|PRIVATE) KEY|DB_PASS[[:space:]]*=[[:space:]]*[^[:space:]#]+|MAIL_CREDENTIAL_KEY[[:space:]]*=[[:space:]]*[^[:space:]#]+|api[_-]?key[[:space:]]*=>[[:space:]]*'\''[A-Za-z0-9_-]{20,}'\'')' \
        "$tree" --exclude-dir='.git' --exclude-dir='vendor' --exclude='.env.example' --exclude='install.php' --exclude='export-modulnest.sh' --exclude='build-packages.sh' --exclude='composer.lock' --exclude='*.md' >/tmp/modulnest-package-secret-scan.$$ 2>/dev/null; then
        cat /tmp/modulnest-package-secret-scan.$$ >&2
        failed=1
    fi
    rm -f /tmp/modulnest-package-secret-scan.$$

    return "$failed"
}

install_vendor_for_bundled() {
    local staging="$1"
    command -v composer >/dev/null 2>&1 || fail "Composer fehlt. Für modulnest-bundled muss lokal composer install --no-dev --optimize-autoloader ausgeführt werden."
    (cd "$staging" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist)
    [[ -d "$staging/vendor" ]] || fail "composer install hat kein vendor/ erzeugt."
}

zip_staging() {
    local kind="$1"
    local staging="$2"
    local zip_path="$OUTPUT_DIR/modulnest-$kind-$VERSION.zip"
    local sha_path="$zip_path.sha256"

    rm -f "$zip_path" "$sha_path"
    if command -v zip >/dev/null 2>&1; then
        (cd "$staging" && zip -qr "$zip_path" .)
    else
        python3 - "$staging" "$zip_path" <<'PY'
import os
import sys
import zipfile

source, target = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED) as archive:
    for root, dirs, files in os.walk(source):
        dirs.sort()
        for name in sorted(files):
            path = os.path.join(root, name)
            arcname = os.path.relpath(path, source)
            archive.write(path, arcname)
PY
    fi
    (cd "$(dirname "$zip_path")" && sha256sum "$(basename "$zip_path")") > "$sha_path"
    printf '%s\n' "$zip_path"
}

write_metadata() {
    local source_zip="$1"
    local bundled_zip="$2"
    local source_sha bundled_sha source_url bundled_url changelog_url php_requirement modules_json

    source_sha="$(sha256sum "$source_zip" | awk '{print $1}')"
    bundled_sha="$(sha256sum "$bundled_zip" | awk '{print $1}')"
    source_url="$BASE_URL/v$VERSION/$(basename "$source_zip")"
    bundled_url="$BASE_URL/v$VERSION/$(basename "$bundled_zip")"
    changelog_url="https://github.com/ChobitsChii/ModulNest/releases/tag/v$VERSION"
    php_requirement="^8.3"
    modules_json="$(php -r '$j=json_decode((string) file_get_contents($argv[1]), true); echo json_encode($j["modules"] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);' "$PUBLIC_TARGET/modulnest-package.json")"

    mkdir -p "$(dirname "$METADATA_FILE")"
    php -r '
        $modules = json_decode($argv[9], true);
        if (!is_array($modules)) {
            $modules = [];
        }
        $metadata = [
            "latest" => $argv[1],
            "channel" => $argv[2],
            "php_requirement" => $argv[3],
            "packages" => [
                "source" => [
                    "url" => $argv[4],
                    "sha256" => $argv[5],
                    "needs_composer" => true,
                ],
                "bundled" => [
                    "url" => $argv[6],
                    "sha256" => $argv[7],
                    "needs_composer" => false,
                ],
            ],
            "changelog_url" => $argv[8],
            "requires_migrations" => true,
            "package_metadata" => "modulnest-package.json",
            "modules" => $modules,
            "generated_at" => gmdate("c"),
        ];
        echo json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    ' "$VERSION" "$CHANNEL" "$php_requirement" "$source_url" "$source_sha" "$bundled_url" "$bundled_sha" "$changelog_url" "$modules_json" > "$METADATA_FILE"
}

main() {
    parse_args "$@"
    require_tools
    require_public_export
    detect_version
    normalize_paths
    confirm_build

    mkdir -p "$OUTPUT_DIR"
    rm -f "$OUTPUT_DIR"/modulnest-core-*.zip "$OUTPUT_DIR"/modulnest-core-*.zip.sha256 \
        "$OUTPUT_DIR"/modulnest-full-*.zip "$OUTPUT_DIR"/modulnest-full-*.zip.sha256
    local workdir source_stage bundled_stage source_zip bundled_zip
    workdir="$(mktemp -d /tmp/modulnest-release.XXXXXX)"
    source_stage="$workdir/source"
    bundled_stage="$workdir/bundled"

    copy_public_export_to_staging "$source_stage"
    scan_package_tree "$source_stage" "0" || fail "Sicherheitsprüfung für Source-Staging fehlgeschlagen."

    rsync -a "$source_stage"/ "$bundled_stage"/
    install_vendor_for_bundled "$bundled_stage"
    scan_package_tree "$bundled_stage" "1" || fail "Sicherheitsprüfung für Bundled-Staging fehlgeschlagen."

    source_zip="$(zip_staging "source" "$source_stage")"
    bundled_zip="$(zip_staging "bundled" "$bundled_stage")"
    write_metadata "$source_zip" "$bundled_zip"

    if [[ "$KEEP_WORK" -eq 0 ]]; then
        rm -rf "$workdir"
    else
        printf 'Temporäres Staging behalten: %s\n' "$workdir"
    fi

    printf '\nPakete erstellt:\n'
    printf '  - %s\n' "$source_zip"
    printf '  - %s\n' "$bundled_zip"
    printf 'SHA256-Dateien:\n'
    printf '  - %s.sha256\n' "$source_zip"
    printf '  - %s.sha256\n' "$bundled_zip"
    printf 'Update-Metadaten: %s\n' "$METADATA_FILE"
}

main "$@"
