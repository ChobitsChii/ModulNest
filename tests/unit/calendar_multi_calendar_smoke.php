<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'ModulNest\\Calendar\\';
    $baseDir = __DIR__ . '/../../modules/modulnest.calendar/releases/0.1.0-beta.1-3e971e2d820a/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\DTO\CalendarDTO;
use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\Repository\CalendarRepository;
use ModulNest\Calendar\Service\CalendarService;
use ModulNest\Calendar\Validation\AppointmentValidator;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

$calRepo = new CalendarRepository($pdo);
$apptRepo = new AppointmentRepository($pdo);
$service = new CalendarService($apptRepo, $calRepo, new AppointmentValidator());

$userId = 42;

// 1. Initial calendars: default calendar created automatically
$calendars = $service->getCalendars($userId);
assert(count($calendars) === 1, 'Default calendar must exist');
assert($calendars[0]->name === 'Mein Kalender', 'Default calendar is named Mein Kalender');
$defaultCalId = $calendars[0]->id;

// 2. Create custom calendar
$res = $service->createCalendar(['name' => 'Arbeit', 'color' => '#10B981'], $userId);
assert($res['success'] === true, 'Create calendar must succeed');
$workCalId = $res['calendar']->id;

$calendars = $service->getCalendars($userId);
assert(count($calendars) === 2, 'Must have 2 calendars now');

// 3. Create appointment in work calendar
$resAppt = $service->createAppointment([
    'title' => 'Projektmeeting',
    'calendar_id' => $workCalId,
    'start_at' => '2026-09-15 10:00:00',
    'end_at' => '2026-09-15 11:00:00',
], $userId);
assert($resAppt['success'] === true, 'Appointment creation must succeed');
assert($resAppt['appointment']->calendarId === $workCalId, 'Appointment calendar_id must match');
assert($resAppt['appointment']->color === '#10B981', 'Appointment must inherit calendar color');

// 4. Query day: appointment is visible
$appts = $service->getAppointmentsForDay($userId, new DateTime('2026-09-15'));
assert(count($appts) === 1, 'Appointment must be visible in day view');

// 5. Toggle visibility of work calendar off
$service->toggleCalendarVisibility($workCalId, $userId);
$apptsHidden = $service->getAppointmentsForDay($userId, new DateTime('2026-09-15'));
assert(count($apptsHidden) === 0, 'Appointment must be hidden when calendar visibility is toggled off');

// 6. Toggle visibility back on
$service->toggleCalendarVisibility($workCalId, $userId);
$apptsRestored = $service->getAppointmentsForDay($userId, new DateTime('2026-09-15'));
assert(count($apptsRestored) === 1, 'Appointment must be visible again');

// 7. Update calendar name and color
$service->updateCalendar($workCalId, ['name' => 'Job', 'color' => '#8B5CF6'], $userId);
$updatedCal = $service->getCalendar($workCalId, $userId);
assert($updatedCal->name === 'Job', 'Calendar name updated');
assert($updatedCal->color === '#8B5CF6', 'Calendar color updated');

echo "PASS: calendar_multi_calendar_smoke\n";
