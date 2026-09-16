<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Module classes autoloader
spl_autoload_register(function ($class) {
    $prefix = 'ModulNest\\Calendar\\';
    if (str_starts_with($class, $prefix)) {
        $rel = substr($class, strlen($prefix));
        $file = __DIR__ . '/../../modules-src/calendar/1.1.0/src/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use Modulon\Core\Database\SchemaHelper;
use Modulon\Modules\Admin\AppSettingRepository;
use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\DTO\CalendarDTO;
use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\Repository\CalendarRepository;
use ModulNest\Calendar\Repository\GoogleAccountRepository;
use ModulNest\Calendar\Service\CalendarService;
use ModulNest\Calendar\Service\GoogleCalendarService;
use ModulNest\Calendar\Service\RecurrenceService;
use ModulNest\Calendar\Validation\AppointmentValidator;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. Baseline tables (SQLite schema) + Run migration 002
$pdo->exec('
CREATE TABLE `calendars` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `name` TEXT NOT NULL,
    `color` TEXT NOT NULL DEFAULT "#3B82F6",
    `is_visible` INTEGER NOT NULL DEFAULT 1,
    `is_default` INTEGER NOT NULL DEFAULT 0,
    `source` TEXT NOT NULL DEFAULT "local",
    `external_id` TEXT NULL,
    `sync_token` TEXT NULL,
    `last_synced_at` TEXT NULL,
    `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE `calendar_appointments` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `user_id` INTEGER NOT NULL,
    `calendar_id` INTEGER NULL,
    `title` TEXT NOT NULL,
    `description` TEXT NULL,
    `location` TEXT NULL,
    `start_at` TEXT NOT NULL,
    `end_at` TEXT NOT NULL,
    `all_day` INTEGER NOT NULL DEFAULT 0,
    `color` TEXT NULL DEFAULT "#3B82F6",
    `created_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
');

$schemaHelper = new SchemaHelper($pdo);
$mig2 = require __DIR__ . '/../../modules-src/calendar/1.1.0/migrations/002_google_and_recurring.php';
$mig2->up($pdo, $schemaHelper);

// Verify tables and columns
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
assert(in_array('calendars', $tables), 'calendars table missing');
assert(in_array('calendar_appointments', $tables), 'calendar_appointments table missing');
assert(in_array('calendar_google_accounts', $tables), 'calendar_google_accounts table missing');

$cols = $pdo->query("PRAGMA table_info(calendar_appointments)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'name');
assert(in_array('recurrence_rule', $colNames), 'recurrence_rule column missing');
assert(in_array('google_event_id', $colNames), 'google_event_id column missing');

echo "[PASS] Database migration 001 and 002 executed successfully.\n";

// 2. Test RecurrenceService
$recurrence = new RecurrenceService();
$builtRule = RecurrenceService::buildRule('WEEKLY', 2, '2026-12-31');
assert($builtRule === 'FREQ=WEEKLY;INTERVAL=2;UNTIL=20261231', "Unexpected built rule: {$builtRule}");

$parsed = RecurrenceService::parseRule($builtRule);
assert(($parsed['FREQ'] ?? '') === 'WEEKLY', 'Failed to parse FREQ');
assert(($parsed['INTERVAL'] ?? '') === '2', 'Failed to parse INTERVAL');

// Expand a daily recurring appointment
$baseStart = new DateTime('2026-03-01 10:00:00');
$baseEnd = new DateTime('2026-03-01 11:00:00');
$recurringApt = new AppointmentDTO(
    id: 42,
    userId: 1,
    calendarId: 1,
    title: 'Tägliches Standup',
    description: 'Sprint Daily',
    location: 'Meet',
    startAt: $baseStart,
    endAt: $baseEnd,
    allDay: false,
    color: '#10B981',
    createdAt: new DateTime(),
    updatedAt: new DateTime(),
    recurrenceRule: 'FREQ=DAILY;INTERVAL=1',
);

$windowStart = new DateTime('2026-03-01 00:00:00');
$windowEnd = new DateTime('2026-03-05 23:59:59');
$occurrences = $recurrence->expandOccurrences([$recurringApt], $windowStart, $windowEnd);

assert(count($occurrences) === 5, 'Expected 5 daily occurrences, got ' . count($occurrences));
assert($occurrences[0]->startAt->format('Y-m-d') === '2026-03-01', 'First occurrence mismatch');
assert($occurrences[4]->startAt->format('Y-m-d') === '2026-03-05', 'Last occurrence mismatch');
echo "[PASS] RecurrenceService correctly builds, parses, and expands recurrences.\n";

// 3. Test GoogleAccountRepository
$accountRepo = new GoogleAccountRepository($pdo);
$accountRepo->save(
    userId: 10,
    email: 'testuser@gmail.com',
    accessToken: 'mock_access_token_123',
    refreshToken: 'mock_refresh_token_456',
    expiresAt: new DateTime('+1 hour'),
    scopes: 'https://www.googleapis.com/auth/calendar'
);

$savedAccount = $accountRepo->findByUserId(10);
assert($savedAccount !== null, 'Account not saved');
assert($savedAccount['google_email'] === 'testuser@gmail.com', 'Email mismatch');
assert($savedAccount['access_token'] === 'mock_access_token_123', 'Access token mismatch');
assert($savedAccount['refresh_token'] === 'mock_refresh_token_456', 'Refresh token mismatch');

$accountRepo->touchLastSynced(10);
$syncedAccount = $accountRepo->findByUserId(10);
assert(!empty($syncedAccount['last_synced_at']), 'Last synced at was not updated');

echo "[PASS] GoogleAccountRepository CRUD & touchLastSynced works.\n";

// 4. Test CalendarRepository with Google Calendar
$calendarRepo = new CalendarRepository($pdo);
$googleCal = new CalendarDTO(
    id: null,
    userId: 10,
    name: 'Mein Google Kalender',
    color: '#4285F4',
    isVisible: true,
    isDefault: false,
    source: 'google',
    externalId: 'testuser@gmail.com',
);
$createdCal = $calendarRepo->create($googleCal);
assert($createdCal->id !== null, 'Failed to create Google calendar');

$foundByExt = $calendarRepo->findByExternalId(10, 'google', 'testuser@gmail.com');
assert($foundByExt !== null, 'Failed to find Google calendar by external ID');
assert($foundByExt->name === 'Mein Google Kalender', 'Name mismatch');

echo "[PASS] CalendarRepository supports Google source calendars.\n";

// 5. Test AppointmentRepository with Google Event ID & Recurrence
$appointmentRepo = new AppointmentRepository($pdo, $recurrence);
$aptDto = new AppointmentDTO(
    id: null,
    userId: 10,
    calendarId: $createdCal->id,
    title: 'Google Termin Test',
    description: 'Synchronisierter Termin',
    location: 'Berlin',
    startAt: new DateTime('2026-04-10 14:00:00'),
    endAt: new DateTime('2026-04-10 15:00:00'),
    allDay: false,
    color: '#4285F4',
    createdAt: new DateTime(),
    updatedAt: new DateTime(),
    recurrenceRule: null,
    recurrenceParentId: null,
    googleEventId: 'google_evt_998877',
);
$createdApt = $appointmentRepo->create($aptDto);
assert($createdApt->id !== null, 'Failed to insert appointment');

$foundApt = $appointmentRepo->findByGoogleEventId(10, 'google_evt_998877');
assert($foundApt !== null, 'Failed to find appointment by googleEventId');
assert($foundApt->title === 'Google Termin Test', 'Title mismatch');

echo "[PASS] AppointmentRepository supports googleEventId and queries.\n";

// 6. Test GoogleCalendarService rate limiting & configuration
// Mock settings using PDO settings table if needed, or in-memory settings repo
$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_settings (
        `key` VARCHAR(191) PRIMARY KEY,
        `value` TEXT NOT NULL,
        `updated_at` DATETIME NOT NULL
    );
");
$settingsRepo = new AppSettingRepository($pdo);
$settingsRepo->setBool('calendar.google_enabled', true);
$settingsRepo->set('calendar.google_client_id', 'mock_client_id.apps.googleusercontent.com');
$settingsRepo->set('calendar.google_client_secret', 'mock_secret_abc');

$googleService = new GoogleCalendarService($settingsRepo, $accountRepo, $calendarRepo, $appointmentRepo);
assert($googleService->isConfigured() === true, 'Google service should report configured');

$authUrl = $googleService->getAuthUrl('https://modulnest.de/calendar/google/callback', 'state123');
assert(str_contains($authUrl, 'client_id=mock_client_id'), 'Auth URL missing client_id');
assert(str_contains($authUrl, 'state=state123'), 'Auth URL missing state');

// Rate limiting: since we touched last_synced_at just now (<60s), sync should rate-limit
$rateLimitCheck = $googleService->syncEvents(10, force: false);
assert($rateLimitCheck['success'] === false, 'Expected sync to be rate-limited');
assert(!empty($rateLimitCheck['rate_limited']), 'Expected rate_limited flag');
echo "[PASS] GoogleCalendarService OAuth URL generation & rate limiting verified.\n";

echo "ALL CALENDAR 1.1.0 TESTS PASSED!\n";
