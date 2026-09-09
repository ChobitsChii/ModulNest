<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function changelog_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$history = [
    ['version' => '1.3.0', 'date' => '2026-09-08', 'changes' => ['Aktuelle Änderung']],
    ['version' => '1.2.0', 'date' => '2026-09-04', 'changes' => ['Ältere Änderung']],
];
$module = [
    'id' => 'modulnest.wiki', 'name' => 'Wiki', 'description' => 'Fixture',
    'classification' => 'v2', 'classification_label' => 'Modul v2', 'origin_label' => 'Katalog',
    'installed' => true, 'v2_installed' => true, 'retained' => false, 'has_owned_data' => true,
    'active' => true, 'installed_version' => '1.2.0', 'available_version' => '1.3.0',
    'update_available' => true, 'compatible' => true, 'incompatibility_reason' => null,
    'data_schema_version' => 1, 'adoption_candidate' => false, 'adoptable' => false,
    'catalog' => ['license' => 'MIT', 'authors' => ['ModulNest'], 'homepage' => 'https://example.test', 'repository' => 'https://example.test/repository', 'history' => $history],
    'release' => ['core' => '>=2.0.0-alpha.1 <3.0.0', 'php' => '>=8.3.0', 'dependencies' => [], 'migration_count' => 1, 'release_notes' => ['summary' => 'Aktuell', 'changes' => ['Aktuelle Änderung']]],
    'resources' => [], 'data_portability' => ['supported' => false], 'last_operation' => null,
];
$csrf_token = 'fixture-token';
$message = $error = $catalog_warning = '';
ob_start();
require dirname(__DIR__, 2) . '/app/Views/admin/module-catalog/detail.php';
$html = (string) ob_get_clean();

changelog_ui_assert(str_contains($html, 'Neu in Version 1.3.0') && str_contains($html, 'Aktuelle Änderung'), 'Prominente Update-Release-Notes fehlen.');
changelog_ui_assert(str_contains($html, 'Version 1.3.0 verfügbar') && substr_count($html, 'value="update"') === 1, 'Updateaktion ist nicht prominent im Modulkopf oder wird doppelt angezeigt.');
changelog_ui_assert(str_contains($html, 'Versionsverlauf') && str_contains($html, 'Version 1.3.0'), 'Versionsverlauf fehlt auf der Detailseite.');
changelog_ui_assert(str_contains($html, '<details') && str_contains($html, 'Version 1.2.0'), 'Ältere Versionen werden nicht einklappbar dargestellt.');

fwrite(STDOUT, "Module catalog changelog UI smoke passed.\n");
