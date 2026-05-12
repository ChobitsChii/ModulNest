#!/usr/bin/env php
<?php

declare(strict_types=1);

use Modulon\Core\Database;
use Modulon\Core\Env;
use Modulon\Modules\SneakPreview\SneakPreviewRepository;

$basePath = dirname(__DIR__);
$autoload = $basePath . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer-Autoload fehlt.\n");
    exit(1);
}
require $autoload;

Env::load($basePath . '/.env');

$options = getopt('', ['dry-run', 'apply']);
$apply = array_key_exists('apply', $options);
$dryRun = !$apply;

$legacyConfigPath = $basePath . '/app/Legacy/sneak-preview-app/config.php';
if (!is_file($legacyConfigPath)) {
    fwrite(STDERR, "Legacy-Konfiguration fehlt: {$legacyConfigPath}\n");
    exit(1);
}

$CONFIG = [];
require $legacyConfigPath;
if (!isset($CONFIG) || !is_array($CONFIG)) {
    fwrite(STDERR, "Legacy-Konfiguration konnte nicht gelesen werden.\n");
    exit(1);
}

$legacyDsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    (string) ($CONFIG['db_host'] ?? ''),
    (string) ($CONFIG['db_name'] ?? '')
);

try {
    $legacy = new PDO($legacyDsn, (string) ($CONFIG['db_user'] ?? ''), (string) ($CONFIG['db_pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $modulon = Database::connect(require $basePath . '/app/Config/database.php');
} catch (Throwable $exception) {
    fwrite(STDERR, "DB-Verbindung fehlgeschlagen: {$exception->getMessage()}\n");
    exit(1);
}

$count = (int) $legacy->query('SELECT COUNT(*) FROM movies')->fetchColumn();
echo "Sneak Preview Migration\n";
echo "Modus: " . ($dryRun ? "dry-run" : "apply") . "\n";
echo "Legacy movies: {$count}\n";

$repository = new SneakPreviewRepository($modulon);
if ($dryRun) {
    $existing = count($repository->allMovies());
    echo "Native Einträge aktuell: {$existing}\n";
    echo "Dry-Run schreibt nichts. Mit --apply importieren.\n";
    exit(0);
}

$rows = $legacy->query('SELECT * FROM movies ORDER BY id ASC')->fetchAll();
$inserted = 0;
$updated = 0;
$copiedPosters = 0;

$modulon->beginTransaction();
try {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $posterPath = copyLegacyPoster($basePath, (string) ($row['poster_path'] ?? ''));
        if ($posterPath !== null) {
            $row['poster_path'] = $posterPath;
            $copiedPosters++;
        } else {
            $row['poster_path'] = null;
        }

        $existing = findByLegacyId($modulon, (int) ($row['id'] ?? 0));
        upsertLegacyMovie($modulon, $row);
        $existing === null ? $inserted++ : $updated++;
    }

    $settings = $legacy->query("SELECT `key`, `value` FROM settings")->fetchAll();
    foreach ($settings as $setting) {
        if (!is_array($setting) || (string) ($setting['key'] ?? '') !== 'display_fields') {
            continue;
        }
        $repository->setSetting('display_fields', (string) ($setting['value'] ?? ''));
    }

    if (!empty($CONFIG['save_posters_locally'])) {
        $repository->setSetting('save_posters_locally', '1');
    }
    // Der TMDB-Key wird bewusst nicht automatisch aus der Legacy-Datei in versionierten Zustand geschrieben,
    // aber lokal in die native DB übernommen, damit die vorhandene Installation weiterarbeiten kann.
    if (is_string($CONFIG['tmdb_api_key'] ?? null) && trim((string) $CONFIG['tmdb_api_key']) !== '') {
        $repository->setSetting('tmdb_api_key', trim((string) $CONFIG['tmdb_api_key']));
    }

    $modulon->commit();
} catch (Throwable $exception) {
    $modulon->rollBack();
    fwrite(STDERR, "Import fehlgeschlagen, Rollback ausgeführt: {$exception->getMessage()}\n");
    exit(1);
}

echo "Import abgeschlossen\n";
echo "Neu: {$inserted}\n";
echo "Aktualisiert: {$updated}\n";
echo "Poster kopiert: {$copiedPosters}\n";

function findByLegacyId(PDO $pdo, int $legacyId): ?int
{
    $statement = $pdo->prepare('SELECT id FROM sneak_preview_entries WHERE legacy_id = :legacy_id LIMIT 1');
    $statement->execute(['legacy_id' => $legacyId]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (int) $value;
}

/**
 * @param array<string, mixed> $row
 */
function upsertLegacyMovie(PDO $pdo, array $row): void
{
    $statement = $pdo->prepare(
        'INSERT INTO sneak_preview_entries
            (legacy_id, sneak_date, title, location, release_date_de, poster_path, poster_tmdb_path, tmdb_id, overview, genres, runtime, certification, original_language, production_countries, vote_average, trailer_key)
         VALUES
            (:legacy_id, :sneak_date, :title, :location, :release_date_de, :poster_path, :poster_tmdb_path, :tmdb_id, :overview, :genres, :runtime, :certification, :original_language, :production_countries, :vote_average, :trailer_key)
         ON DUPLICATE KEY UPDATE
            sneak_date = VALUES(sneak_date),
            title = VALUES(title),
            location = VALUES(location),
            release_date_de = VALUES(release_date_de),
            poster_path = VALUES(poster_path),
            poster_tmdb_path = VALUES(poster_tmdb_path),
            tmdb_id = VALUES(tmdb_id),
            overview = VALUES(overview),
            genres = VALUES(genres),
            runtime = VALUES(runtime),
            certification = VALUES(certification),
            original_language = VALUES(original_language),
            production_countries = VALUES(production_countries),
            vote_average = VALUES(vote_average),
            trailer_key = VALUES(trailer_key)'
    );
    $statement->execute([
        'legacy_id' => (int) ($row['id'] ?? 0),
        'sneak_date' => (string) ($row['sneak_date'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'location' => (string) ($row['location'] ?? ''),
        'release_date_de' => normalizeNullable($row['release_date_de'] ?? null),
        'poster_path' => normalizeNullable($row['poster_path'] ?? null),
        'poster_tmdb_path' => normalizeNullable($row['poster_tmdb_path'] ?? null),
        'tmdb_id' => normalizeNullable($row['tmdb_id'] ?? null),
        'overview' => normalizeNullable($row['overview'] ?? null),
        'genres' => normalizeNullable($row['genres'] ?? null),
        'runtime' => normalizeNullable($row['runtime'] ?? null),
        'certification' => normalizeNullable($row['certification'] ?? null),
        'original_language' => normalizeNullable($row['original_language'] ?? null),
        'production_countries' => normalizeNullable($row['production_countries'] ?? null),
        'vote_average' => normalizeNullable($row['vote_average'] ?? null),
        'trailer_key' => normalizeNullable($row['trailer_key'] ?? null),
    ]);
}

function normalizeNullable(mixed $value): mixed
{
    $string = trim((string) ($value ?? ''));
    return $string === '' ? null : $string;
}

function copyLegacyPoster(string $basePath, string $legacyPosterPath): ?string
{
    $legacyPosterPath = trim($legacyPosterPath);
    if ($legacyPosterPath === '') {
        return null;
    }

    $source = $basePath . '/app/Legacy/sneak-preview-app/' . ltrim($legacyPosterPath, '/');
    if (!is_file($source)) {
        return null;
    }

    $fileName = basename($source);
    $targetDir = $basePath . '/public/assets/sneak-preview/posters';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return null;
    }
    copy($source, $targetDir . '/' . $fileName);

    return '/assets/sneak-preview/posters/' . $fileName;
}
