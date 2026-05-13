#!/usr/bin/env bash
set -Eeuo pipefail

readonly SCRIPT_NAME="$(basename "$0")"
readonly DEFAULT_TARGET="/srv/http/modulnest"
readonly CORE_MODULES=("Admin" "Auth" "Modules" "User")
readonly DEFAULT_OPTIONAL_MODULES=("Banking" "Dashboard" "DataPortability" "Logs" "News" "SneakPreview" "Systeminfo" "Tools" "Updates")

TARGET="$DEFAULT_TARGET"
ASSUME_YES=0
ALL_MODULES=0
MODULES_CSV=""
NO_UI=0

COPIED_ITEMS=()
WARNINGS=()
SELECTED_MODULES=()
OPTIONAL_MODULES=()

usage() {
    cat <<EOF
Usage: $SCRIPT_NAME [--target /pfad] [--modules A,B,C] [--all-modules] [--no-ui] [--yes]

Erzeugt einen sicheren öffentlichen ModulNest-Export aus dem Modulon-Projektroot.

Optionen:
  --target PATH       Zielordner. Default: $DEFAULT_TARGET
  --modules A,B,C    Optionale Module explizit auswählen.
  --all-modules      Alle gefundenen optionalen Module exportieren.
  --no-ui            Keine dialog/whiptail-Auswahl verwenden.
  --yes              Nicht interaktiv bestätigen.
  -h, --help         Hilfe anzeigen.
EOF
}

fail() {
    printf 'FEHLER: %s\n' "$*" >&2
    exit 1
}

warn() {
    WARNINGS+=("$*")
    printf 'WARNUNG: %s\n' "$*" >&2
}

is_in_array() {
    local needle="$1"
    shift
    local item
    for item in "$@"; do
        [[ "$item" == "$needle" ]] && return 0
    done
    return 1
}

parse_args() {
    while (($# > 0)); do
        case "$1" in
            --target)
                [[ $# -ge 2 ]] || fail "--target benötigt einen Pfad."
                TARGET="$2"
                shift 2
                ;;
            --modules)
                [[ $# -ge 2 ]] || fail "--modules benötigt eine kommaseparierte Modulliste."
                MODULES_CSV="$2"
                shift 2
                ;;
            --all-modules)
                ALL_MODULES=1
                shift
                ;;
            --no-ui)
                NO_UI=1
                shift
                ;;
            --yes|-y)
                ASSUME_YES=1
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

require_project_root() {
    [[ -f "app/bootstrap.php" ]] || fail "Bitte aus dem Modulon-Projektroot ausführen."
    [[ -d "app/Modules" ]] || fail "app/Modules nicht gefunden."
    [[ -f "composer.json" ]] || fail "composer.json nicht gefunden."
}

discover_modules() {
    local module_dir module
    OPTIONAL_MODULES=()
    while IFS= read -r module_dir; do
        module="$(basename "$module_dir")"
        is_in_array "$module" "${CORE_MODULES[@]}" && continue
        OPTIONAL_MODULES+=("$module")
    done < <(find app/Modules -mindepth 1 -maxdepth 1 -type d | sort)
}

select_modules_from_csv() {
    local raw module
    IFS=',' read -r -a raw <<< "$MODULES_CSV"
    SELECTED_MODULES=()
    for module in "${raw[@]}"; do
        module="$(printf '%s' "$module" | xargs)"
        [[ -z "$module" ]] && continue
        [[ -d "app/Modules/$module" ]] || fail "Modul '$module' existiert nicht unter app/Modules."
        is_in_array "$module" "${CORE_MODULES[@]}" && continue
        SELECTED_MODULES+=("$module")
    done
}

select_modules_with_dialog() {
    local cmd choices=() module state selected
    if command -v dialog >/dev/null 2>&1; then
        cmd="dialog"
    elif command -v whiptail >/dev/null 2>&1; then
        cmd="whiptail"
    else
        return 1
    fi

    for module in "${OPTIONAL_MODULES[@]}"; do
        state="off"
        is_in_array "$module" "${DEFAULT_OPTIONAL_MODULES[@]}" && state="on"
        choices+=("$module" "Optionales Modul $module" "$state")
    done

    if [[ "$cmd" == "dialog" ]]; then
        selected="$("$cmd" --stdout --checklist "Optionale Module für ModulNest auswählen" 24 92 16 "${choices[@]}")" || return 1
    else
        selected="$("$cmd" --title "ModulNest Export" --checklist "Optionale Module auswählen" 24 92 16 "${choices[@]}" 3>&1 1>&2 2>&3)" || return 1
    fi

    SELECTED_MODULES=()
    for module in $selected; do
        module="${module%\"}"
        module="${module#\"}"
        [[ -n "$module" ]] && SELECTED_MODULES+=("$module")
    done
    return 0
}

select_modules_cli() {
    local module index default_answer answer selected=()
    if [[ ! -t 0 || "$ASSUME_YES" -eq 1 ]]; then
        SELECTED_MODULES=("${DEFAULT_OPTIONAL_MODULES[@]}")
        return
    fi

    printf 'Core-Module werden immer exportiert: %s\n' "${CORE_MODULES[*]}"
    printf 'Optionale Module auswählen. Enter übernimmt den Default.\n\n'
    index=1
    for module in "${OPTIONAL_MODULES[@]}"; do
        default_answer="n"
        is_in_array "$module" "${DEFAULT_OPTIONAL_MODULES[@]}" && default_answer="y"
        read -r -p "[$index/${#OPTIONAL_MODULES[@]}] $module exportieren? [$default_answer] " answer
        answer="${answer:-$default_answer}"
        if [[ "$answer" =~ ^[JjYy]$ ]]; then
            selected+=("$module")
        fi
        index=$((index + 1))
    done
    SELECTED_MODULES=("${selected[@]}")
}

select_modules() {
    if [[ "$ALL_MODULES" -eq 1 ]]; then
        SELECTED_MODULES=("${OPTIONAL_MODULES[@]}")
    elif [[ -n "$MODULES_CSV" ]]; then
        select_modules_from_csv
    elif [[ "$NO_UI" -eq 0 ]] && [[ -t 1 ]] && select_modules_with_dialog; then
        :
    else
        select_modules_cli
    fi
}

safe_target_check() {
    [[ "$TARGET" = /* ]] || fail "Zielpfad muss absolut sein: $TARGET"
    [[ "$TARGET" != "/" ]] || fail "Zielpfad darf nicht / sein."
    [[ "$TARGET" != "$(pwd)" ]] || fail "Zielpfad darf nicht der Modulon-Projektroot sein."
    [[ "$TARGET" != "$(pwd)/"* ]] || fail "Zielpfad darf nicht innerhalb des privaten Modulon-Projekts liegen."
}

confirm_export() {
    printf 'ModulNest Export\n'
    printf '  Quelle: %s\n' "$(pwd)"
    printf '  Ziel:   %s\n' "$TARGET"
    printf '  Core:   %s\n' "${CORE_MODULES[*]}"
    printf '  Optional enthalten: %s\n' "${SELECTED_MODULES[*]:-(keine)}"
    printf '\n'

    if [[ "$ASSUME_YES" -eq 1 ]]; then
        return
    fi
    read -r -p "Zielordner wird neu aufgebaut. Fortfahren? [y/N] " answer
    [[ "$answer" =~ ^[JjYy]$ ]] || fail "Abgebrochen."
}

reset_target() {
    local preserve_dir=""
    mkdir -p "$TARGET"
    if [[ -f "$TARGET/build/update/stable.json" ]]; then
        preserve_dir="$(mktemp -d)"
        mkdir -p "$preserve_dir/build/update"
        cp -p "$TARGET/build/update/stable.json" "$preserve_dir/build/update/stable.json"
    fi
    find "$TARGET" -mindepth 1 -maxdepth 1 ! -name '.git' -exec rm -rf {} +
    if [[ -n "$preserve_dir" ]]; then
        mkdir -p "$TARGET/build/update"
        cp -p "$preserve_dir/build/update/stable.json" "$TARGET/build/update/stable.json"
        rm -rf "$preserve_dir"
    fi
}

copy_file_if_exists() {
    local source="$1"
    local destination="$2"
    if [[ -f "$source" ]]; then
        mkdir -p "$(dirname "$destination")"
        cp -p "$source" "$destination"
        COPIED_ITEMS+=("$source")
    fi
}

copy_dir() {
    local source="$1"
    local destination="$2"
    shift 2
    [[ -d "$source" ]] || return 0
    mkdir -p "$destination"
    rsync -a "$@" "$source"/ "$destination"/
    COPIED_ITEMS+=("$source")
}

append_sql_if_exists() {
    local source="$1"
    local destination="$2"
    [[ -f "$source" ]] || return 0
    {
        printf '\n-- Source: %s\n' "$source"
        cat "$source"
        printf '\n'
    } >> "$destination"
}

copy_database_files() {
    local destination="$TARGET/app/Database/schema.sql"
    local module

    copy_dir "app/Database/schema" "$TARGET/app/Database/schema"
    copy_dir "app/Database/seeds" "$TARGET/app/Database/seeds"
    copy_dir "app/Database/migrations" "$TARGET/app/Database/migrations"

    mkdir -p "$(dirname "$destination")"
    {
        printf -- '-- ModulNest compatibility schema generated by %s\n' "$SCRIPT_NAME"
        printf -- '-- Clean installs use app/Database/schema/* and selected module Database files.\n'
    } > "$destination"

    append_sql_if_exists "app/Database/schema/core.sql" "$destination"
    for module in "${CORE_MODULES[@]}" "${SELECTED_MODULES[@]}"; do
        append_sql_if_exists "app/Modules/$module/Database/schema.sql" "$destination"
    done
    append_sql_if_exists "app/Database/seeds/core.sql" "$destination"
    for module in "${CORE_MODULES[@]}" "${SELECTED_MODULES[@]}"; do
        append_sql_if_exists "app/Modules/$module/Database/seeds.sql" "$destination"
    done

    COPIED_ITEMS+=("app/Database/schema.sql (compat aggregate)")
}

write_env_example() {
    cat > "$TARGET/.env.example" <<'EOF'
APP_ENV=production
APP_DEBUG=false
APP_PRODUCT_NAME=ModulNest
APP_CORE_NAME=Modulon
APP_CORE_LABEL="Modulon Core"
PUBLIC_REGISTRATION_ENABLED=false

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=modulnest
DB_CHARSET=utf8mb4
DB_USER=modulnest
DB_PASS=change-me

SESSION_IDLE_TIMEOUT=1800
SESSION_ABSOLUTE_TIMEOUT=28800

REMEMBER_COOKIE_NAME=modulnest_remember
REMEMBER_TOKEN_LIFETIME=1209600
REMEMBER_COOKIE_SECURE=true
REMEMBER_COOKIE_SAMESITE=Lax

TOTP_ISSUER=ModulNest
WEBAUTHN_RP_NAME=ModulNest
WEBAUTHN_RP_ID=

# Mail-Modul: 32+ zufällige Bytes/Base64 für serverseitige Verschlüsselung.
MAIL_CREDENTIAL_KEY=
EOF
    COPIED_ITEMS+=(".env.example (public template)")
}

module_view_dir() {
    case "$1" in
        Banking) printf 'banking' ;;
        DataPortability) printf 'data-portability' ;;
        Dashboard) printf 'dashboard' ;;
        FantasyCards) printf 'fantasy-cards' ;;
        Logs) printf 'logs' ;;
        Mail) printf 'mail' ;;
        News) printf 'news' ;;
        SneakPreview) printf 'sneak-preview' ;;
        Systeminfo) printf 'systeminfo' ;;
        Tools) printf 'tools' ;;
        Updates) printf 'updates' ;;
        User) printf 'user' ;;
        Admin) printf 'admin' ;;
        Auth) printf 'auth' ;;
        Modules) printf 'modules' ;;
        *) printf '' ;;
    esac
}

copy_module_and_views() {
    local module="$1"
    local view_dir
    copy_dir "app/Modules/$module" "$TARGET/app/Modules/$module"
    view_dir="$(module_view_dir "$module")"
    if [[ -n "$view_dir" ]]; then
        if [[ "$module" == "User" ]] && ! is_in_array "FantasyCards" "${SELECTED_MODULES[@]}"; then
            copy_dir "app/Views/$view_dir" "$TARGET/app/Views/$view_dir" \
                --exclude='partials/fantasy-cards.php'
        else
            copy_dir "app/Views/$view_dir" "$TARGET/app/Views/$view_dir"
        fi
    fi
}

copy_public_assets() {
    copy_dir "public/assets/css" "$TARGET/public/assets/css"
    copy_dir "public/assets/js" "$TARGET/public/assets/js" \
        --exclude='fantasycards-*.js' \
        --exclude='mail-*.js' \
        --exclude='modulon-overlay.js'
    copy_dir "public/assets/img" "$TARGET/public/assets/img"
    copy_dir "public/assets/vendor" "$TARGET/public/assets/vendor"

    mkdir -p "$TARGET/public/assets/favicons"
    touch "$TARGET/public/assets/favicons/.gitkeep"

    if is_in_array "SneakPreview" "${SELECTED_MODULES[@]}"; then
        mkdir -p "$TARGET/public/assets/sneak-preview/posters"
        touch "$TARGET/public/assets/sneak-preview/posters/.gitkeep"
    fi
    if is_in_array "FantasyCards" "${SELECTED_MODULES[@]}"; then
        mkdir -p "$TARGET/public/assets/fantasy-cards/cards"
        touch "$TARGET/public/assets/fantasy-cards/.gitkeep" \
            "$TARGET/public/assets/fantasy-cards/cards/.gitkeep"
    fi
}

copy_storage_placeholders() {
    mkdir -p "$TARGET/storage/logs" \
        "$TARGET/storage/favicons"
    if is_in_array "Tools" "${SELECTED_MODULES[@]}"; then
        mkdir -p "$TARGET/storage/tools/speech/uploads" \
            "$TARGET/storage/tools/speech/wav" \
            "$TARGET/storage/tools/speech/results" \
            "$TARGET/storage/tools/speech/jobs" \
            "$TARGET/storage/tools/speech/logs" \
            "$TARGET/storage/tools/speech/models"
    fi
    if is_in_array "FantasyCards" "${SELECTED_MODULES[@]}"; then
        mkdir -p "$TARGET/storage/fantasy-cards"
    fi
    find "$TARGET/storage" -type d -exec sh -c 'touch "$1/.gitkeep"' sh {} \;
}

write_package_metadata() {
    local selected_csv core_csv
    selected_csv="$(IFS=,; printf '%s' "${SELECTED_MODULES[*]}")"
    core_csv="$(IFS=,; printf '%s' "${CORE_MODULES[*]}")"

    php -r '
        $target = $argv[1];
        $core = array_values(array_filter(explode(",", $argv[2])));
        $selected = array_values(array_filter(explode(",", $argv[3])));
        $version = "0.7.5";
        if (is_file("app/Core/Env.php") && is_file("app/Config/version.php")) {
            require_once "app/Core/Env.php";
            $config = require "app/Config/version.php";
            $version = (string) ($config["version"] ?? $version);
        }
        spl_autoload_register(static function (string $class): void {
            $prefix = "Modulon\\";
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $path = "app/" . str_replace("\\", "/", substr($class, strlen($prefix))) . ".php";
            if (is_file($path)) {
                require_once $path;
            }
        });
        $viewMap = [
            "Banking" => "banking",
            "DataPortability" => "data-portability",
            "Dashboard" => "dashboard",
            "FantasyCards" => "fantasy-cards",
            "Logs" => "logs",
            "Mail" => "mail",
            "News" => "news",
            "SneakPreview" => "sneak-preview",
            "Systeminfo" => "systeminfo",
            "Tools" => "tools",
            "User" => "user",
        ];
        $modules = [];
        foreach ($core as $name) {
            $class = "Modulon\\Modules\\" . $name . "\\" . $name . "Module";
            $metadata = [];
            $native = false;
            if (class_exists($class) && is_subclass_of($class, "Modulon\\Core\\NativeModuleInterface")) {
                $metadata = $class::metadata();
                $native = true;
            }
            $modules[] = [
                "directory" => $name,
                "key" => (string) ($metadata["key"] ?? strtolower($name)),
                "name" => (string) ($metadata["name"] ?? $name),
                "route_prefix" => trim((string) ($metadata["route_prefix"] ?? strtolower($name)), "/"),
                "access_level" => (string) ($metadata["access_level"] ?? ($name === "Admin" || $name === "Modules" ? "admin" : "public")),
                "description" => (string) ($metadata["description"] ?? "Core-Modul"),
                "required" => true,
                "default_enabled" => true,
                "native" => $native,
                "show_in_header" => !empty($metadata["show_in_header"]),
                "show_on_home" => !empty($metadata["show_on_home"]),
                "view_dir" => $viewMap[$name] ?? null,
            ];
        }
        foreach ($selected as $name) {
            $class = "Modulon\\Modules\\" . $name . "\\" . $name . "Module";
            $metadata = [];
            if (class_exists($class) && is_subclass_of($class, "Modulon\\Core\\NativeModuleInterface")) {
                $metadata = $class::metadata();
            }
            $modules[] = [
                "directory" => $name,
                "key" => (string) ($metadata["key"] ?? strtolower($name)),
                "name" => (string) ($metadata["name"] ?? $name),
                "route_prefix" => trim((string) ($metadata["route_prefix"] ?? strtolower($name)), "/"),
                "access_level" => (string) ($metadata["access_level"] ?? "user"),
                "description" => (string) ($metadata["description"] ?? ""),
                "required" => false,
                "default_enabled" => true,
                "native" => true,
                "show_in_header" => !empty($metadata["show_in_header"]),
                "show_on_home" => !empty($metadata["show_on_home"]),
                "view_dir" => $viewMap[$name] ?? null,
            ];
        }
        $payload = [
            "schema" => 1,
            "product" => "ModulNest",
            "core" => "Modulon",
            "version" => $version,
            "channel" => "alpha",
            "generated_at" => gmdate("c"),
            "required_modules" => $core,
            "optional_modules" => $selected,
            "modules" => $modules,
        ];
        file_put_contents($target . "/modulnest-package.json", json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    ' "$TARGET" "$core_csv" "$selected_csv"

    COPIED_ITEMS+=("modulnest-package.json")
}

write_public_gitignore() {
    cat > "$TARGET/.gitignore" <<'EOF'
.env
.local/
vendor/
node_modules/
.idea/
.vscode/
*.code-workspace

var/cache/
var/log/
storage/**/*
!storage/**/
!storage/**/.gitkeep

public/assets/favicons/*
!public/assets/favicons/.gitkeep
public/assets/sneak-preview/posters/*
!public/assets/sneak-preview/posters/.gitkeep
public/assets/fantasy-cards/cards/*
!public/assets/fantasy-cards/.gitkeep
!public/assets/fantasy-cards/cards/.gitkeep
public/.user.ini
!public/.user.ini.example

*.log
*.bak
*.backup
*.dump
*.sql.gz
*.tar
*.tar.gz
*.zip
build/releases/*.zip
build/releases/*.sha256
__pycache__/
*.pyc
.pytest_cache/
playwright-report/
test-results/
EOF
}

copy_project() {
    copy_file_if_exists "README.md" "$TARGET/README.md"
    copy_file_if_exists "LICENSE" "$TARGET/LICENSE"
    copy_file_if_exists "composer.json" "$TARGET/composer.json"
    copy_file_if_exists "composer.lock" "$TARGET/composer.lock"
    copy_file_if_exists "pytest.ini" "$TARGET/pytest.ini"
    copy_file_if_exists "install.php" "$TARGET/install.php"

    copy_file_if_exists "app/bootstrap.php" "$TARGET/app/bootstrap.php"
    copy_dir "app/Core" "$TARGET/app/Core"
    copy_dir "app/Config" "$TARGET/app/Config" \
        --exclude='*.local.php' \
        --exclude='*.private.php'
    copy_dir "app/Views/layouts" "$TARGET/app/Views/layouts"
    copy_dir "app/Views/partials" "$TARGET/app/Views/partials"
    copy_dir "app/Views/errors" "$TARGET/app/Views/errors"
    copy_file_if_exists "app/Views/home.php" "$TARGET/app/Views/home.php"
    copy_file_if_exists "app/Views/partials/module-nav.php" "$TARGET/app/Views/partials/module-nav.php"

    mkdir -p "$TARGET/app/Legacy"
    touch "$TARGET/app/Legacy/.gitkeep"

    copy_database_files
    write_env_example

    local module
    for module in "${CORE_MODULES[@]}"; do
        copy_module_and_views "$module"
    done
    for module in "${SELECTED_MODULES[@]}"; do
        copy_module_and_views "$module"
    done

    if is_in_array "FantasyCards" "${SELECTED_MODULES[@]}"; then
        mkdir -p "$TARGET/app/Views/user/partials"
        copy_file_if_exists "app/Views/user/partials/fantasy-cards.php" "$TARGET/app/Views/user/partials/fantasy-cards.php"
    fi

    copy_dir "public" "$TARGET/public" \
        --exclude='assets' \
        --exclude='.htaccess.local' \
        --exclude='.user.ini'
    copy_public_assets

    copy_dir "docs" "$TARGET/docs"
    copy_dir "scripts" "$TARGET/scripts"
    copy_dir "tools/release" "$TARGET/tools/release"
    copy_dir "tests" "$TARGET/tests" \
        --exclude='**/local.env' \
        --exclude='**/__pycache__/' \
        --exclude='**/*.pyc' \
        --exclude='e2e/test_fantasy_cards_module.py'

    mkdir -p "$TARGET/bin"
    copy_file_if_exists "bin/tools-speech-worker.php" "$TARGET/bin/tools-speech-worker.php"
    if is_in_array "Banking" "${SELECTED_MODULES[@]}"; then
        copy_file_if_exists "bin/migrate-banking.php" "$TARGET/bin/migrate-banking.php"
    fi
    if is_in_array "SneakPreview" "${SELECTED_MODULES[@]}"; then
        copy_file_if_exists "bin/migrate-sneak-preview.php" "$TARGET/bin/migrate-sneak-preview.php"
    fi

    copy_storage_placeholders
    write_package_metadata
    write_public_gitignore
}

scan_forbidden_paths() {
    local found=0
    local pattern
    local forbidden_names=(
        '.env'
        '.local'
        'vendor'
        'node_modules'
        '.idea'
        '.vscode'
        'var/cache'
        'var/log'
    )

    for pattern in "${forbidden_names[@]}"; do
        if find "$TARGET" -path "$TARGET/$pattern" -o -path "$TARGET/$pattern/*" | grep -q .; then
            printf 'Verdächtiger Pfad im Export: %s\n' "$pattern" >&2
            found=1
        fi
    done

    if find "$TARGET" -type f \( \
        -iname '*.bak' -o -iname '*.backup' -o -iname '*.dump' -o -iname '*.sql.gz' -o -iname '*.log' -o \
        -iname '*.tar' -o -iname '*.tar.gz' -o -iname '*.zip' -o -iname '*.pyc' -o -iname '*backup*' -o -iname '*dump*' \
    \) | grep -q .; then
        find "$TARGET" -type f \( \
            -iname '*.bak' -o -iname '*.backup' -o -iname '*.dump' -o -iname '*.sql.gz' -o -iname '*.log' -o \
            -iname '*.tar' -o -iname '*.tar.gz' -o -iname '*.zip' -o -iname '*.pyc' -o -iname '*backup*' -o -iname '*dump*' \
        \) >&2
        found=1
    fi

    if find "$TARGET" -type d -name '__pycache__' | grep -q .; then
        find "$TARGET" -type d -name '__pycache__' >&2
        found=1
    fi

    if [[ -f "$TARGET/tests/e2e/test_fantasy_cards_module.py" ]]; then
        printf 'FantasyCards-E2E-Test im Public-Export gefunden, obwohl FantasyCards nicht enthalten ist.\n' >&2
        found=1
    fi

    if find "$TARGET/storage" -type f ! -name '.gitkeep' | grep -q .; then
        printf 'storage enthält Dateien außer .gitkeep.\n' >&2
        find "$TARGET/storage" -type f ! -name '.gitkeep' >&2
        found=1
    fi

    local runtime_asset_dirs=()
    [[ -d "$TARGET/public/assets/favicons" ]] && runtime_asset_dirs+=("$TARGET/public/assets/favicons")
    [[ -d "$TARGET/public/assets/sneak-preview/posters" ]] && runtime_asset_dirs+=("$TARGET/public/assets/sneak-preview/posters")
    [[ -d "$TARGET/public/assets/fantasy-cards/cards" ]] && runtime_asset_dirs+=("$TARGET/public/assets/fantasy-cards/cards")
    if ((${#runtime_asset_dirs[@]} > 0)) && find "${runtime_asset_dirs[@]}" \
        -type f ! -name '.gitkeep' | grep -q .; then
        printf 'Runtime-Assets enthalten Dateien außer .gitkeep.\n' >&2
        find "${runtime_asset_dirs[@]}" \
            -type f ! -name '.gitkeep' >&2
        found=1
    fi

    return "$found"
}

scan_secrets() {
    local findings
    findings="$(
        grep -RInE \
            '((DB_PASS|MAIL_CREDENTIAL_KEY|TMDB_API_KEY|TOOLS_SPEECH_MODEL_PATH)[[:space:]]*=[[:space:]]*[^[:space:]#]+|[a-zA-Z0-9_]*api[_-]?key[[:space:]]*=>[[:space:]]*'\''[A-Za-z0-9_-]{20,}'\''|BEGIN (RSA|OPENSSH|PRIVATE) KEY|Jennifer@|Grassl|dvU4MiSvAxq6PpW|password[[:space:]]*=[[:space:]]*'\''[^'\'']{8,}'\''|token[[:space:]]*=[[:space:]]*'\''[A-Za-z0-9._-]{20,}'\'')' \
            "$TARGET" \
            --exclude-dir='.git' \
            --exclude='.env.example' \
            --exclude='install.php' \
            --exclude='export-modulnest.sh' \
            --exclude='build-packages.sh' \
            --exclude='composer.lock' \
            --exclude='schema.sql' \
            --exclude='*.md' \
            || true
    )"

    if [[ -n "$findings" ]]; then
        printf '%s\n' "$findings" >&2
        return 1
    fi
    return 0
}

scan_schema_safety() {
    local schema="$TARGET/app/Database/schema.sql"
    [[ -f "$schema" ]] || return 0
    if grep -nE 'INSERT[[:space:]]+INTO[[:space:]]+(card_sets|cards|users|mail_accounts|banking_transactions|sneak_preview_entries)' "$schema" >&2; then
        return 1
    fi
    return 0
}

run_security_scan() {
    local failed=0
    scan_forbidden_paths || failed=1
    scan_secrets || failed=1
    scan_schema_safety || failed=1
    [[ "$failed" -eq 0 ]] || fail "Sicherheitsprüfung fehlgeschlagen. Export wurde im Ziel belassen für Review, aber sollte nicht gepusht werden."
}

write_summary() {
    local summary="$TARGET/docs/export-summary.md"
    mkdir -p "$(dirname "$summary")"
    {
        printf '# ModulNest Export Summary\n\n'
        printf -- '- Zielpfad: `%s`\n' "$TARGET"
        printf -- '- Erstellt am: `%s`\n' "$(date -Is)"
        printf -- '- Core-Module: `%s`\n' "${CORE_MODULES[*]}"
        printf -- '- Optionale Module: `%s`\n\n' "${SELECTED_MODULES[*]:-(keine)}"
        printf '## Ausgeschlossen\n\n'
        printf -- '- `.env`, `.local`, `vendor`, `storage`-Nutzdaten, Runtime-Assets, Logs, Backups, IDE-Dateien, private Testdaten.\n'
        printf -- '- `app/Legacy` enthält nur `.gitkeep`.\n'
        printf -- '- `app/Database/schema.sql` wurde als Kompatibilitäts-Aggregat aus Core-Schema, Core-Seeds und den ausgewählten Modul-Schemas/-Seeds erzeugt.\n'
        printf -- '- Nicht ausgewählte Module bringen keine Modul-Schema-Dateien in den Export.\n\n'
        printf -- '- `install.php` ist als einzelner Bootstrap-Installer enthalten.\n\n'
        printf -- '- `modulnest-package.json` beschreibt die im Export enthaltenen Pflicht- und optionalen Module.\n\n'
        printf '## Nächste Schritte\n\n'
        printf '1. Zielordner manuell reviewen.\n'
        printf '2. Im Zielrepo `composer install` ausführen.\n'
        printf '3. `.env.example` nach `.env` kopieren und echte lokale Werte setzen.\n'
        printf '4. Erst nach Review im Zielrepo committen und nach `ChobitsChii/ModulNest` pushen.\n'
    } > "$summary"
}

print_final_summary() {
    printf '\nExport abgeschlossen.\n'
    printf 'Zielpfad: %s\n' "$TARGET"
    printf 'Kopierte Hauptbestandteile:\n'
    printf '  - app/Core, app/Config, app/Database/schema/*, app/Database/seeds/*, app/Database/migrations/* und app/Database/schema.sql (Kompatibilitäts-Aggregat)\n'
    printf '  - app/Modules: %s %s\n' "${CORE_MODULES[*]}" "${SELECTED_MODULES[*]:-}"
    printf '  - app/Views passend zu enthaltenen Modulen\n'
    printf '  - public ohne Runtime-Assets\n'
    printf '  - docs, scripts, tests, composer.json, composer.lock, README\n'
    printf '  - install.php Bootstrap-Installer\n'
    printf '  - modulnest-package.json Release-/Modulmetadaten\n'
    printf 'Ausgeschlossen: .env, .local, vendor, storage-Nutzdaten, Logs, Backups, Legacy-App-Inhalte, Runtime-Bilder/Favicons.\n'
    if ((${#WARNINGS[@]} > 0)); then
        printf 'Warnungen:\n'
        printf '  - %s\n' "${WARNINGS[@]}"
    else
        printf 'Warnungen: keine\n'
    fi
    printf 'Nächster Schritt: manueller Review im Zielordner, danach Git commit/push im ModulNest-Zielrepo.\n'
}

main() {
    parse_args "$@"
    require_project_root
    discover_modules
    select_modules
    safe_target_check
    confirm_export
    reset_target
    copy_project
    run_security_scan
    write_summary
    print_final_summary
}

main "$@"
