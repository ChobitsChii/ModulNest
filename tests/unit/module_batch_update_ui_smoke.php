<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function batch_ui_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$modules = [[
    'id' => 'modulnest.incompatible', 'name' => 'Nicht kompatibel', 'description' => 'Fixture',
    'classification' => 'v2', 'classification_label' => 'Modul v2', 'origin_label' => 'Katalog',
    'installed_version' => '1.0.0', 'available_version' => '1.0.0', 'latest_version' => '2.0.0',
    'latest_update_compatible' => false, 'update_incompatibility_reason' => 'Benötigt ModulNest >=3.0.0.',
    'installed' => true, 'v2_installed' => true, 'retained' => false, 'active' => true,
    'adoption_candidate' => false, 'adoptable' => false, 'compatible' => true, 'update_available' => false,
]];
$tab = 'updates';
$counts = ['entdecken' => 0, 'installiert' => 1, 'updates' => 1];
$batch_update_plan = [[
    'module_id' => 'modulnest.wiki', 'name' => 'Wiki', 'from' => '1.2.0', 'to' => '1.3.0',
    'migration_count' => 1, 'dependencies' => ['modulnest.logs' => '>=1.0.0'], 'backup' => true,
]];
$batch_update_operation = [
    'operation_id' => 'fixture', 'status' => 'running', 'current' => 2, 'total' => 3,
    'modules' => [
        ['module_id' => 'modulnest.wiki', 'name' => 'Wiki', 'status' => 'succeeded'],
        ['module_id' => 'modulnest.dashboard', 'name' => 'Dashboard', 'status' => 'running'],
        ['module_id' => 'modulnest.banking', 'name' => 'Banking', 'status' => 'waiting'],
    ],
];
$csrf_token = 'fixture-token';
$clean_install = $test_key_active = false;
$message = $error = $catalog_warning = '';
ob_start();
require dirname(__DIR__, 2) . '/app/Views/admin/module-catalog/index.php';
$html = (string) ob_get_clean();

batch_ui_assert(str_contains($html, 'Alle aktualisieren') && str_contains($html, '1.2.0 → 1.3.0'), 'Gesamtplan oder Batchaktion fehlt.');
batch_ui_assert(str_contains($html, 'Migrationen') && str_contains($html, 'Dependencies') && str_contains($html, 'Backup'), 'Planrisiken werden nicht vollständig angezeigt.');
batch_ui_assert(str_contains($html, '2 / 3') && str_contains($html, '✓ Wiki') && str_contains($html, '→ Dashboard') && str_contains($html, 'Banking – wartet'), 'Sequenzieller Fortschritt ist nicht verständlich sichtbar.');
batch_ui_assert(str_contains($html, 'Update 2.0.0 nicht kompatibel') && str_contains($html, 'Benötigt ModulNest'), 'Inkompatibles Update wird nicht mit Grund ausgeschlossen.');

fwrite(STDOUT, "Module batch update plan/progress UI smoke passed.\n");
