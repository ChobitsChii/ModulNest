<?php

declare(strict_types=1);

use Modulon\Core\Modules\CapabilityRegistry;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function capability_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$registry = new CapabilityRegistry();
$registry->register('modulnest.news', 'data_portability', 'ModulNest\\News\\NewsDataPortabilityProvider');
$registry->register('modulnest.wiki', 'data_portability', 'ModulNest\\Wiki\\WikiDataPortabilityProvider');
capability_assert(
    array_keys($registry->providers('data_portability')) === ['modulnest.news', 'modulnest.wiki'],
    'Provider müssen deterministisch nach unveränderlicher Modul-ID registriert werden.'
);
capability_assert($registry->providers('unknown') === [], 'Unbekannte Capabilities müssen leer bleiben.');
$pageProvider = new stdClass();
$registry->registerInstance('modulnest.pages', 'page_links', $pageProvider);
capability_assert(
    $registry->instances('page_links') === ['modulnest.pages' => $pageProvider],
    'Laufzeit-Providerinstanzen müssen neutral und deterministisch abrufbar sein.'
);
$registry->registerInstance('modulnest.pages', 'page_links', new stdClass());
capability_assert(
    $registry->instances('page_links')['modulnest.pages'] === $pageProvider,
    'Erneute Bootstrap-Erzeugung derselben Providerklasse muss idempotent bleiben.'
);
try {
    $registry->registerInstance('modulnest.pages', 'page_links', new class {
    });
    capability_assert(false, 'Doppelte Capability-Instanz wurde akzeptiert.');
} catch (InvalidArgumentException) {
}
try {
    $registry->register('modulnest.news', 'data_portability', 'ModulNest\\News\\OtherProvider');
    capability_assert(false, 'Doppelte Modul-Capability wurde akzeptiert.');
} catch (InvalidArgumentException) {
}

fwrite(STDOUT, "Capability registry v2 smoke passed.\n");
