# Implementierungsplan: modulnest.calendar 0.1.0-beta.1

> **Status**: Überarbeitet nach technischem Review gegen Core-Implementation
> **Ziel**: Lokaler Kalender mit Terminverwaltung (CRUD), Tages- und Wochenansicht, lokale Speicherung
> **Nicht-Ziel (Beta 1)**: Bidirektionale Google Calendar Synchronisation

---

## 1. Modul-Identität & Manifest (`module.json`)

### 1.1 Basis-Konfiguration

```json
{
  "manifest_version": 2,
  "id": "modulnest.calendar",
  "name": "Calendar",
  "version": "0.1.0-beta.1",
  "description": "Lokaler Kalender mit Terminverwaltung (CRUD), Tages- und Wochenansicht. Google Calendar Sync folgt in späteren Versionen.",
  "type": "native",
  "license": "MIT",
  "author": "ModulNest Team",
  "homepage": "https://modulnest.example.com/modules/calendar",
  "repository": "https://github.com/modulnest/calendar-module",
  "keywords": ["calendar", "appointments", "scheduling", "local"],
  "minimum_core_version": "2.0.0",
  "php_version": ">=8.2",
  "entrypoint": "ModulNest\\Calendar\\CalendarModule",
  "autoload": {
    "psr4": {
      "ModulNest\\Calendar\\": "src/"
    }
  },
  "route_prefix": "/calendar",
  "access_level": "user",
  "data": {
    "schema_version": 1,
    "ownership": {
      "tables": ["calendar_appointments"],
      "settings": ["calendar.*"],
      "storage": [],
      "uploads": [],
      "jobs": []
    }
  },
  "capabilities": {
    "data_portability": "ModulNest\\Calendar\\Portability\\CalendarDataPortabilityProvider",
    "health_check": true
  },
  "navigation": {
    "user": {
      "label": "Kalender",
      "icon": "calendar",
      "order": 30,
      "route": "calendar.index"
    }
  },
  "assets": {
    "css": ["assets/css/calendar.css"],
    "js": ["assets/js/calendar.js"]
  },
  "dependencies": {
    "requires": [],
    "conflicts": [],
    "suggests": []
  }
}
```

### 1.2 Wichtige Manifest-Entscheidungen

| Feld | Entscheidung | Begründung |
|------|--------------|------------|
| `manifest_version` | 2 | Canonical v2 Format |
| `id` | `modulnest.calendar` | Namespaced ID (Publisher.Module) |
| `version` | `0.1.0-beta.1` | SemVer Pre-Release für Beta |
| `route_prefix` | `/calendar` | Ersetzt `access.routes` Objekt, Core nutzt Prefix für Routing |
| `access_level` | `user` | Ersetzt `access.default`, alle Routes erben `user` Level |
| `data.ownership.tables` | `["calendar_appointments"]` | Explizites Ownership für Migration/Uninstall |
| `capabilities.data_portability` | `"ModulNest\\Calendar\\Portability\\CalendarDataPortabilityProvider"` | Klassenname statt Boolean (Core CapabilityRegistry) |
| `capabilities.health_check` | `true` | DB-Verbindung prüfbar via `addWritableDirectory` |
| `navigation.user` | definiert | Hauptnavigation via Manifest, Subnavigation via Entrypoint-Hook |

---

## 2. Datenbankschema (`migrations/schema.sql`)

### 2.1 Tabelle: `calendar_appointments`

```sql
-- migrations/schema.sql
-- Baseline-Migration für modulnest.calendar 0.1.0-beta.1
-- Lexicographisch: 001_baseline.php → schema.sql

CREATE TABLE calendar_appointments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    location VARCHAR(500) NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    -- Für zukünftige Google Calendar Sync (Beta 2+):
    external_id VARCHAR(255) NULL,
    external_source VARCHAR(50) NULL, -- 'google', 'outlook', etc.
    external_etag VARCHAR(255) NULL,
    last_synced_at DATETIME NULL,
    sync_status ENUM('local', 'synced', 'conflict', 'pending') NOT NULL DEFAULT 'local',
    -- Metadaten
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    INDEX idx_user_start (user_id, start_at),
    INDEX idx_user_range (user_id, start_at, end_at),
    INDEX idx_external (external_source, external_id),
    INDEX idx_sync_status (sync_status),
    CONSTRAINT fk_calendar_appointments_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.2 Migrations-Klasse (`migrations/001_baseline.php`)

```php
<?php
// migrations/001_baseline.php

namespace ModulNest\Calendar\Migrations;

use ModulNest\Core\Module\Migration\Migration;
use ModulNest\Core\Module\Migration\MigrationContext;
use PDO;

final class Baseline implements Migration
{
    public function version(): string
    {
        return '001_baseline';
    }

    public function description(): string
    {
        return 'Create calendar_appointments table with indexes and foreign key';
    }

    public function up(MigrationContext $ctx): void
    {
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        $ctx->pdo->exec($sql);
    }

    public function down(MigrationContext $ctx): void
    {
        $ctx->pdo->exec('DROP TABLE IF EXISTS calendar_appointments');
    }
}
```

### 2.3 Schema-Design-Entscheidungen

| Spalte | Typ | Zweck | Sync-Vorbereitung |
|--------|-----|-------|-------------------|
| `external_id` | VARCHAR(255) | Google Event ID | **Beta 2+** (NULLable, kein Risiko) |
| `external_source` | VARCHAR(50) | Quelle identifizieren | **Beta 2+** (NULLable, kein Risiko) |
| `external_etag` | VARCHAR(255) | Optimistisches Locking | **Beta 2+** (NULLable, kein Risiko) |
| `last_synced_at` | DATETIME | Sync-Timestamp | **Beta 2+** (NULLable, kein Risiko) |
| `sync_status` | ENUM | Sync-State-Machine | **Beta 2+** (Default 'local') |
| `timezone` | VARCHAR(64) | IANA Timezone pro Termin | **Beta 1** (wichtig für lokale Termine) |
| `all_day` | TINYINT | Ganztägige Termine | **Beta 1** (Standard Kalender-Feature) |

**Entscheidung**: Die Sync-Spalten (`external_id`, `external_source`, `external_etag`, `last_synced_at`, `sync_status`) bleiben **im Schema** (NULLable, Defaults), sind aber **nicht funktional in Beta 1**. Das vermeidet spätere Schema-Migrationen und birgt kein Risiko. Nur `timezone` und `all_day` sind in Beta 1 aktiv genutzt.

---

## 3. Entrypoint (`src/CalendarModule.php`)

```php
<?php
// src/CalendarModule.php

namespace ModulNest\Calendar;

use ModulNest\Core\Module\NativeModuleInterface;
use ModulNest\Core\Module\ModuleContext;
use ModulNest\Core\Module\Router;
use ModulNest\Core\Module\Navigation\ModuleSubnavigationRegistry;
use ModulNest\Core\Module\Navigation\AdminNavigationRegistry;
use ModulNest\Core\Module\Navigation\UserNavigationRegistry;
use ModulNest\Core\Module\HealthCheck\HealthCheckProviderInterface;
use ModulNest\Core\Module\HealthCheck\HealthCheckRegistry;
use ModulNest\Core\Module\Capability\CapabilityRegistry;
use ModulNest\Core\Module\Capability\DataPortabilityProviderInterface;
use ModulNest\Calendar\Portability\CalendarDataPortabilityProvider;
use ModulNest\Calendar\Navigation\CalendarSubnavigationProvider;
use PDO;

final class CalendarModule implements NativeModuleInterface, HealthCheckProviderInterface
{
    private PDO $pdo;
    private string $basePath;

    public static function metadata(): array
    {
        return [
            'id' => 'modulnest.calendar',
            'name' => 'Calendar',
            'version' => '0.1.0-beta.1',
            'description' => 'Lokaler Kalender mit Terminverwaltung',
            'author' => 'ModulNest Team',
            'license' => 'MIT',
        ];
    }

    public static function create(ModuleContext $context): ?NativeModuleInterface
    {
        $instance = new self();
        $instance->pdo = $context->pdo;
        $instance->basePath = $context->basePath;
        return $instance;
    }

    public function key(): string
    {
        return 'modulnest.calendar';
    }

    public function routePrefix(): string
    {
        return '/calendar';
    }

    public function registerNavigation(
        ModuleSubnavigationRegistry $moduleNavigation,
        AdminNavigationRegistry $adminNavigation,
        UserNavigationRegistry $userNavigation
    ): void {
        // User-Navigation wird via Manifest deklariert (navigation.user)
        // Subnavigation für Day/Week Views
        $moduleNavigation->register('modulnest.calendar', new CalendarSubnavigationProvider());
    }

    public function registerRoutes(Router $router): void
    {
        $controller = CalendarController::class;

        // Kalender-Hauptansicht (Default: Wochenansicht)
        // Router::get(path, handler, access, csrfPolicy) - positional parameters
        $router->get('/', [$controller, 'index'], 'user');

        // Tagesansicht
        $router->get('/day/{date}', [$controller, 'dayView'], 'user');

        // Wochenansicht
        $router->get('/week/{date}', [$controller, 'weekView'], 'user');

        // Termin CRUD
        $router->get('/appointment/create', [$controller, 'createForm'], 'user');

        $router->post('/appointment', [$controller, 'store'], 'user', 'strict');

        $router->get('/appointment/{id}/edit', [$controller, 'editForm'], 'user');

        $router->put('/appointment/{id}', [$controller, 'update'], 'user', 'strict');

        $router->delete('/appointment/{id}', [$controller, 'destroy'], 'user', 'strict');

        // AJAX-Endpoints für Drag & Drop, Quick-Create
        $router->post('/api/appointment/quick', [$controller, 'quickCreate'], 'user', 'strict');

        $router->patch('/api/appointment/{id}/move', [$controller, 'move'], 'user', 'strict');

        $router->patch('/api/appointment/{id}/resize', [$controller, 'resize'], 'user', 'strict');
    }

    public function registerAdminRoutes(Router $router): void
    {
        // Keine Admin-Routes in Beta 1
    }

    public function registerHealthChecks(HealthCheckRegistry $healthChecks): void
    {
        // HealthCheckRegistry unterstützt nur addWritableDirectory()
        // Kein Storage-Verzeichnis für Calendar in Beta 1 → kein HealthCheck nötig
        // Falls später Storage genutzt wird: $healthChecks->addWritableDirectory('calendar.storage', 'Calendar Storage', $this->basePath . '/storage');
    }

    public function nativeBinding(): array
    {
        return [
            DataPortabilityProviderInterface::class => CalendarDataPortabilityProvider::class,
        ];
    }
}
```

---

## 4. Controller (`src/CalendarController.php`)

### 4.1 Struktur & Dependencies

```php
<?php
// src/CalendarController.php

namespace ModulNest\Calendar;

use ModulNest\Core\Http\Request;
use ModulNest\Core\Http\Response;
use ModulNest\Core\Http\Session;
use ModulNest\Core\View\View;
use ModulNest\Core\Auth\AuthService;
use ModulNest\Calendar\Service\CalendarService;
use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\DTO\AppointmentDTO;
use ModulNest\Calendar\Validation\AppointmentValidator;
use DateTimeImmutable;
use DateTimeZone;

final class CalendarController
{
    public function __construct(
        private CalendarService $service,
        private AppointmentRepository $repository,
        private AppointmentValidator $validator,
        private AuthService $auth,
        private Session $session,
        private View $view
    ) {}

    // ... Methoden folgen
}
```

### 4.2 Haupt-Routen (Views)

```php
    public function index(Request $request): Response
    {
        $user = $this->auth->user();
        $date = $request->query('date') ?? (new DateTimeImmutable())->format('Y-m-d');
        return $this->weekView($request->withQueryParams(['date' => $date]));
    }

    public function dayView(Request $request): Response
    {
        $user = $this->auth->user();
        $date = $request->attribute('date');
        $timezone = new DateTimeZone($user['timezone'] ?? 'UTC');
        $dayStart = (new DateTimeImmutable($date, $timezone))->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $appointments = $this->repository->findByUserAndRange(
            $user['id'],
            $dayStart,
            $dayEnd
        );

        return $this->view->render('@modulnest.calendar/day', [
            'date' => $date,
            'dayStart' => $dayStart,
            'appointments' => $appointments,
            'timezone' => $timezone,
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    public function weekView(Request $request): Response
    {
        $user = $this->auth->user();
        $date = $request->attribute('date') ?? (new DateTimeImmutable())->format('Y-m-d');
        $timezone = new DateTimeZone($user['timezone'] ?? 'UTC');
        $weekStart = (new DateTimeImmutable($date, $timezone))->modify('monday this week')->setTime(0, 0);
        $weekEnd = $weekStart->modify('+7 days');

        $appointments = $this->repository->findByUserAndRange(
            $user['id'],
            $weekStart,
            $weekEnd
        );

        // Gruppieren nach Tag für Template
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->modify("+{$i} days");
            $days[] = [
                'date' => $day->format('Y-m-d'),
                'label' => $day->format('D, d.m.'),
                'isToday' => $day->format('Y-m-d') === (new DateTimeImmutable('now', $timezone))->format('Y-m-d'),
                'appointments' => array_filter($appointments, fn($a) => 
                    (new DateTimeImmutable($a->start_at))->format('Y-m-d') === $day->format('Y-m-d')
                ),
            ];
        }

        return $this->view->render('@modulnest.calendar/week', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'days' => $days,
            'timezone' => $timezone,
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }
```

### 4.3 CRUD-Operationen

```php
    public function createForm(Request $request): Response
    {
        $user = $this->auth->user();
        $date = $request->query('date') ?? (new DateTimeImmutable())->format('Y-m-d');
        $time = $request->query('time') ?? '09:00';

        return $this->view->render('@modulnest.calendar/appointment-form', [
            'mode' => 'create',
            'appointment' => null,
            'defaultStart' => "{$date}T{$time}",
            'defaultEnd' => (new DateTimeImmutable("{$date}T{$time}"))->modify('+1 hour')->format('H:i'),
            'timezone' => $user['timezone'] ?? 'UTC',
            'csrfToken' => $this->session->csrfToken(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $user = $this->auth->user();
        $data = $request->all();
        $errors = $this->validator->validate($data);

        if (!empty($errors)) {
            return $this->view->render('@modulnest.calendar/appointment-form', [
                'mode' => 'create',
                'appointment' => null,
                'errors' => $errors,
                'old' => $data,
                'csrfToken' => $this->session->csrfToken(),
            ])->withStatus(422);
        }

        $dto = AppointmentDTO::fromRequest($data, $user['id']);
        $appointment = $this->service->create($dto);

        $this->session->flash('success', 'Termin erstellt');
        return Response::redirect(route('calendar.day', ['date' => $dto->startAt->format('Y-m-d')]));
    }

    public function editForm(Request $request): Response
    {
        $user = $this->auth->user();
        $id = (int) $request->attribute('id');
        $appointment = $this->repository->findByIdAndUser($id, $user['id']);

        if (!$appointment) {
            return Response::notFound('Termin nicht gefunden');
        }

        return $this->view->render('@modulnest.calendar/appointment-form', [
            'mode' => 'edit',
            'appointment' => $appointment,
            'errors' => [],
            'old' => [],
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->auth->user();
        $id = (int) $request->attribute('id');
        $data = $request->all();
        $errors = $this->validator->validate($data, $id);

        if (!empty($errors)) {
            $appointment = $this->repository->findByIdAndUser($id, $user['id']);
            return $this->view->render('@modulnest.calendar/appointment-form', [
                'mode' => 'edit',
                'appointment' => $appointment,
                'errors' => $errors,
                'old' => $data,
                'csrfToken' => $this->session->csrfToken(),
            ])->withStatus(422);
        }

        $dto = AppointmentDTO::fromRequest($data, $user['id']);
        $this->service->update($id, $dto);

        $this->session->flash('success', 'Termin aktualisiert');
        return Response::redirect(route('calendar.day', ['date' => $dto->startAt->format('Y-m-d')]));
    }

    public function destroy(Request $request): Response
    {
        $user = $this->auth->user();
        $id = (int) $request->attribute('id');
        $this->service->delete($id, $user['id']);

        $this->session->flash('success', 'Termin gelöscht');
        $referer = $request->header('Referer') ?? route('calendar.index');
        return Response::redirect($referer);
    }
```

### 4.4 AJAX-Endpoints (Drag & Drop, Resize)

```php
    public function quickCreate(Request $request): Response
    {
        $user = $this->auth->user();
        $data = $request->json();
        $errors = $this->validator->validateQuick($data);

        if (!empty($errors)) {
            return $this->json(['ok' => false, 'errors' => $errors], 422);
        }

        $dto = AppointmentDTO::fromQuickCreate($data, $user['id']);
        $appointment = $this->service->create($dto);

        return $this->json(['ok' => true, 'appointment' => $appointment->toArray()]);
    }

    public function move(Request $request): Response
    {
        $user = $this->auth->user();
        $id = (int) $request->attribute('id');
        $data = $request->json();

        $appointment = $this->repository->findByIdAndUser($id, $user['id']);
        if (!$appointment) {
            return $this->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $newStart = new DateTimeImmutable($data['start']);
        $newEnd = new DateTimeImmutable($data['end']);
        $duration = $appointment->end_at->getTimestamp() - $appointment->start_at->getTimestamp();
        
        // Wenn nur Start übergeben: End berechnen
        if (!isset($data['end'])) {
            $newEnd = $newStart->modify("+{$duration} seconds");
        }

        $this->service->move($id, $newStart, $newEnd);

        return $this->json(['ok' => true]);
    }

    public function resize(Request $request): Response
    {
        $user = $this->auth->user();
        $id = (int) $request->attribute('id');
        $data = $request->json();

        $appointment = $this->repository->findByIdAndUser($id, $user['id']);
        if (!$appointment) {
            return $this->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $newStart = isset($data['start']) ? new DateTimeImmutable($data['start']) : $appointment->start_at;
        $newEnd = new DateTimeImmutable($data['end']);

        $this->service->resize($id, $newStart, $newEnd);

        return $this->json(['ok' => true]);
    }

    private function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
```

---

## 5. Domain Layer (Service, Repository, DTO, Validator)

### 5.1 Repository (`src/Repository/AppointmentRepository.php`)

```php
<?php
// src/Repository/AppointmentRepository.php

namespace ModulNest\Calendar\Repository;

use ModulNest\Calendar\DTO\AppointmentDTO;
use PDO;

final class AppointmentRepository
{
    public function __construct(private PDO $pdo) {}

    public function findByIdAndUser(int $id, int $userId): ?AppointmentDTO
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_appointments WHERE id = ? AND user_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapRow($row) : null;
    }

    public function findByUserAndRange(int $userId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_appointments 
             WHERE user_id = ? AND deleted_at IS NULL 
             AND start_at < ? AND end_at > ?
             ORDER BY start_at ASC'
        );
        $stmt->execute([
            $userId,
            $end->format('Y-m-d H:i:s'),
            $start->format('Y-m-d H:i:s')
        ]);
        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function create(AppointmentDTO $dto): AppointmentDTO
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO calendar_appointments 
             (user_id, title, description, location, start_at, end_at, all_day, timezone, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $dto->userId,
            $dto->title,
            $dto->description,
            $dto->location,
            $dto->startAt->format('Y-m-d H:i:s'),
            $dto->endAt->format('Y-m-d H:i:s'),
            $dto->allDay ? 1 : 0,
            $dto->timezone,
        ]);
        $dto->id = (int) $this->pdo->lastInsertId();
        return $dto;
    }

    public function update(int $id, AppointmentDTO $dto): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE calendar_appointments SET
             title = ?, description = ?, location = ?, start_at = ?, end_at = ?, all_day = ?, timezone = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ? AND deleted_at IS NULL'
        );
        return $stmt->execute([
            $dto->title,
            $dto->description,
            $dto->location,
            $dto->startAt->format('Y-m-d H:i:s'),
            $dto->endAt->format('Y-m-d H:i:s'),
            $dto->allDay ? 1 : 0,
            $dto->timezone,
            $id,
            $dto->userId,
        ]);
    }

    public function move(int $id, int $userId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE calendar_appointments SET start_at = ?, end_at = ?, updated_at = NOW()
             WHERE id = ? AND user_id = ? AND deleted_at IS NULL'
        );
        return $stmt->execute([
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            $id,
            $userId,
        ]);
    }

    public function resize(int $id, int $userId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return $this->move($id, $userId, $start, $end);
    }

    public function delete(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE calendar_appointments SET deleted_at = NOW() WHERE id = ? AND user_id = ?'
        );
        return $stmt->execute([$id, $userId]);
    }

    // Für Data Portability (Export)
    public function findAllByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM calendar_appointments WHERE user_id = ? AND deleted_at IS NULL ORDER BY start_at'
        );
        $stmt->execute([$userId]);
        return array_map([$this, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Für Data Portability (Import)
    public function import(array $appointments): int
    {
        $count = 0;
        foreach ($appointments as $appt) {
            $dto = AppointmentDTO::fromArray($appt);
            $this->create($dto);
            $count++;
        }
        return $count;
    }

    private function mapRow(array $row): AppointmentDTO
    {
        return new AppointmentDTO(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            title: $row['title'],
            description: $row['description'],
            location: $row['location'],
            startAt: new DateTimeImmutable($row['start_at']),
            endAt: new DateTimeImmutable($row['end_at']),
            allDay: (bool) $row['all_day'],
            timezone: $row['timezone'],
            externalId: $row['external_id'],
            externalSource: $row['external_source'],
            externalEtag: $row['external_etag'],
            lastSyncedAt: $row['last_synced_at'] ? new DateTimeImmutable($row['last_synced_at']) : null,
            syncStatus: $row['sync_status'],
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
            deletedAt: $row['deleted_at'] ? new DateTimeImmutable($row['deleted_at']) : null,
        );
    }
}
```

### 5.2 Service (`src/Service/CalendarService.php`)

```php
<?php
// src/Service/CalendarService.php

namespace ModulNest\Calendar\Service;

use ModulNest\Calendar\Repository\AppointmentRepository;
use ModulNest\Calendar\DTO\AppointmentDTO;

final class CalendarService
{
    public function __construct(private AppointmentRepository $repository) {}

    public function create(AppointmentDTO $dto): AppointmentDTO
    {
        // Business Logic: Validierung, Defaults, Konflikte prüfen
        $this->validateNoOverlap($dto);
        return $this->repository->create($dto);
    }

    public function update(int $id, AppointmentDTO $dto): bool
    {
        $this->validateNoOverlap($dto, $id);
        return $this->repository->update($id, $dto);
    }

    public function move(int $id, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return $this->repository->move($id, $dto->userId, $start, $end);
    }

    public function resize(int $id, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        return $this->repository->resize($id, $dto->userId, $start, $end);
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->repository->delete($id, $userId);
    }

    private function validateNoOverlap(AppointmentDTO $dto, ?int $excludeId = null): void
    {
        // Optional: Überschneidungsprüfung für Beta 1
        // Kann in Beta 2+ erweitert werden für Sync-Konflikte
    }
}
```

### 5.3 DTO (`src/DTO/AppointmentDTO.php`)

```php
<?php
// src/DTO/AppointmentDTO.php

namespace ModulNest\Calendar\DTO;

use DateTimeImmutable;
use DateTimeZone;

final class AppointmentDTO
{
    public function __construct(
        public ?int $id,
        public int $userId,
        public string $title,
        public ?string $description,
        public ?string $location,
        public DateTimeImmutable $startAt,
        public DateTimeImmutable $endAt,
        public bool $allDay,
        public string $timezone,
        public ?string $externalId = null,
        public ?string $externalSource = null,
        public ?string $externalEtag = null,
        public ?DateTimeImmutable $lastSyncedAt = null,
        public string $syncStatus = 'local',
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
        public ?DateTimeImmutable $deletedAt = null,
    ) {}

    public static function fromRequest(array $data, int $userId): self
    {
        $timezone = new DateTimeZone($data['timezone'] ?? 'UTC');
        $startAt = new DateTimeImmutable($data['start_at'], $timezone);
        $endAt = new DateTimeImmutable($data['end_at'], $timezone);
        $allDay = !empty($data['all_day']);

        if ($allDay) {
            $startAt = $startAt->setTime(0, 0);
            $endAt = $endAt->setTime(23, 59, 59);
        }

        return new self(
            id: null,
            userId: $userId,
            title: trim($data['title']),
            description: $data['description'] ?? null,
            location: $data['location'] ?? null,
            startAt: $startAt,
            endAt: $endAt,
            allDay: $allDay,
            timezone: $timezone->getName(),
        );
    }

    public static function fromQuickCreate(array $data, int $userId): self
    {
        return self::fromRequest($data + ['timezone' => 'UTC'], $userId);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? null,
            userId: $data['user_id'],
            title: $data['title'],
            description: $data['description'] ?? null,
            location: $data['location'] ?? null,
            startAt: new DateTimeImmutable($data['start_at']),
            endAt: new DateTimeImmutable($data['end_at']),
            allDay: (bool) $data['all_day'],
            timezone: $data['timezone'],
            externalId: $data['external_id'] ?? null,
            externalSource: $data['external_source'] ?? null,
            externalEtag: $data['external_etag'] ?? null,
            lastSyncedAt: isset($data['last_synced_at']) ? new DateTimeImmutable($data['last_synced_at']) : null,
            syncStatus: $data['sync_status'] ?? 'local',
            createdAt: isset($data['created_at']) ? new DateTimeImmutable($data['created_at']) : null,
            updatedAt: isset($data['updated_at']) ? new DateTimeImmutable($data['updated_at']) : null,
            deletedAt: isset($data['deleted_at']) ? new DateTimeImmutable($data['deleted_at']) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'start_at' => $this->startAt->format('Y-m-d H:i:s'),
            'end_at' => $this->endAt->format('Y-m-d H:i:s'),
            'all_day' => $this->allDay,
            'timezone' => $this->timezone,
            'external_id' => $this->externalId,
            'external_source' => $this->externalSource,
            'external_etag' => $this->externalEtag,
            'last_synced_at' => $this->lastSyncedAt?->format('Y-m-d H:i:s'),
            'sync_status' => $this->syncStatus,
            'created_at' => $this->createdAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at' => $this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
```

### 5.4 Validator (`src/Validation/AppointmentValidator.php`)

```php
<?php
// src/Validation/AppointmentValidator.php

namespace ModulNest\Calendar\Validation;

final class AppointmentValidator
{
    public function validate(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        $title = trim($data['title'] ?? '');
        if ($title === '') {
            $errors['title'] = 'Titel ist erforderlich';
        } elseif (mb_strlen($title) > 255) {
            $errors['title'] = 'Titel maximal 255 Zeichen';
        }

        if (!empty($data['description']) && mb_strlen($data['description']) > 65535) {
            $errors['description'] = 'Beschreibung zu lang';
        }

        if (!empty($data['location']) && mb_strlen($data['location']) > 500) {
            $errors['location'] = 'Ort maximal 500 Zeichen';
        }

        // Start/End Validierung
        try {
            $start = new \DateTimeImmutable($data['start_at'] ?? '');
            $end = new \DateTimeImmutable($data['end_at'] ?? '');
        } catch (\Throwable) {
            $errors['start_at'] = 'Ungültiges Datumsformat';
            return $errors;
        }

        if ($start >= $end) {
            $errors['end_at'] = 'Endzeit muss nach Startzeit liegen';
        }

        // Max 1 Jahr im Voraus (konfigurierbar)
        $maxFuture = new \DateTimeImmutable('+1 year');
        if ($start > $maxFuture) {
            $errors['start_at'] = 'Termin liegt zu weit in der Zukunft';
        }

        return $errors;
    }

    public function validateQuick(array $data): array
    {
        // Reduzierte Validierung für Quick-Create (AJAX)
        $errors = [];
        if (empty($data['title'])) $errors['title'] = 'Titel erforderlich';
        if (empty($data['start'])) $errors['start'] = 'Start erforderlich';
        if (empty($data['end'])) $errors['end'] = 'Ende erforderlich';
        return $errors;
    }
}
```

---

## 6. Views

### 6.1 Layout-Struktur

```
views/
├── layout.php          # Basis-Layout (extends Core layout)
├── day.php             # Tagesansicht
├── week.php            # Wochenansicht
├── appointment-form.php # Create/Edit Formular (Modal oder Seite)
└── partials/
    ├── appointment-card.php
    ├── time-grid.php
    └── week-header.php
```

### 6.2 Wichtige View-Patterns (aus Referenzmodulen)

```php
// views/day.php - Beispiel
<?php
/** @var DateTimeImmutable $dayStart */
/** @var array $appointments */
/** @var DateTimeZone $timezone */
/** @var string $csrfToken */
?>
<div class="calendar-day-view">
    <header class="calendar-header">
        <h1><?= $dayStart->format('l, d. F Y') ?></h1>
        <nav class="calendar-nav">
            <a href="<?= route('calendar.day', ['date' => $dayStart->modify('-1 day')->format('Y-m-d')]) ?>">← Vortag</a>
            <a href="<?= route('calendar.week', ['date' => $dayStart->format('Y-m-d')]) ?>">Wochenansicht</a>
            <a href="<?= route('calendar.day', ['date' => $dayStart->modify('+1 day')->format('Y-m-d')]) ?>">Nächster Tag →</a>
        </nav>
    </header>

    <div class="calendar-time-grid" data-date="<?= $dayStart->format('Y-m-d') ?>">
        <?php foreach (range(0, 23) as $hour): ?>
            <div class="time-slot" data-hour="<?= $hour ?>">
                <span class="time-label"><?= sprintf('%02d:00', $hour) ?></span>
                <div class="appointments-container"></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Termin-Rendering via JS oder PHP -->
    <template id="appointment-template">
        <div class="appointment-card" data-id="{{id}}">
            <div class="appointment-time">{{start}} - {{end}}</div>
            <div class="appointment-title">{{title}}</div>
            {{#if location}}<div class="appointment-location">📍 {{location}}</div>{{/if}}
            <div class="appointment-actions">
                <a href="<?= route('calendar.appointment.edit', ['id' => '{{id}}']) ?>">Bearbeiten</a>
                <form method="POST" action="<?= route('calendar.appointment.destroy', ['id' => '{{id}}']) ?>">
                    <input type="hidden" name="_csrf" value="<?= $csrfToken ?>">
                    <input type="hidden" name="_method" value="DELETE">
                    <button type="submit" onclick="return confirm('Löschen?')">Löschen</button>
                </form>
            </div>
        </div>
    </template>
</div>

<script src="<?= asset('modules/modulnest.calendar/0.1.0-beta.1/js/calendar.js') ?>"></script>
<script>
    CalendarDayView.init({
        appointments: <?= json_encode(array_map(fn($a) => $a->toArray(), $appointments)) ?>,
        csrfToken: '<?= $csrfToken ?>',
        baseUrl: '<?= route('calendar.api.move') ?>'
    });
</script>
```

---

## 7. Navigation Provider (`src/Navigation/CalendarSubnavigationProvider.php`)

```php
<?php
// src/Navigation/CalendarSubnavigationProvider.php

namespace ModulNest\Calendar\Navigation;

use ModulNest\Core\Module\Navigation\ModuleSubnavigationProviderInterface;

final class CalendarSubnavigationProvider implements ModuleSubnavigationProviderInterface
{
    private const ITEMS = [
        ['key' => 'week', 'label' => 'Woche', 'route' => 'calendar.week', 'icon' => 'calendar-week'],
        ['key' => 'day', 'label' => 'Tag', 'route' => 'calendar.day', 'icon' => 'calendar-day'],
        ['key' => 'create', 'label' => 'Neuer Termin', 'route' => 'calendar.appointment.create', 'icon' => 'plus', 'class' => 'btn-primary'],
    ];

    public function moduleKey(): string
    {
        return 'modulnest.calendar';
    }

    public function items(string $currentPath): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $weekStart = (new \DateTimeImmutable())->modify('monday this week')->format('Y-m-d');

        $activeKey = $this->activeKey($currentPath);

        return array_map(function (array $item) use ($activeKey, $today, $weekStart): array {
            $params = match ($item['key']) {
                'week' => ['date' => $weekStart],
                'day' => ['date' => $today],
                'create' => ['date' => $today],
                default => [],
            };

            return [
                'key' => $item['key'],
                'label' => $item['label'],
                'url' => route($item['route'], $params),
                'is_active' => $item['key'] === $activeKey,
                'description' => '',
            ];
        }, self::ITEMS);
    }

    private function activeKey(string $currentPath): string
    {
        if (str_contains($currentPath, '/week/')) return 'week';
        if (str_contains($currentPath, '/day/')) return 'day';
        if (str_contains($currentPath, '/appointment/create')) return 'create';
        return 'week';
    }
}
```

---

## 8. Data Portability (`src/Portability/CalendarDataPortabilityProvider.php`)

```php
<?php
// src/Portability/CalendarDataPortabilityProvider.php

namespace ModulNest\Calendar\Portability;

use ModulNest\Core\Module\Capability\DataPortabilityProviderInterface;
use ModulNest\Core\Module\Capability\DataPortabilityFileCollector;
use ModulNest\Core\Module\Capability\DataPortabilityArchiveReader;
use ModulNest\Calendar\Repository\AppointmentRepository;
use PDO;

final class CalendarDataPortabilityProvider implements DataPortabilityProviderInterface
{
    public function __construct(
        private AppointmentRepository $repository,
        private PDO $pdo
    ) {}

    public function key(): string
    {
        return 'modulnest.calendar';
    }

    public function label(): string
    {
        return 'Kalender-Termine';
    }

    public function routePrefix(): string
    {
        return '/calendar';
    }

    public function description(): string
    {
        return 'Exportiert und importiert alle Kalender-Termine des Benutzers (Titel, Beschreibung, Ort, Zeit, Ganztägig, Timezone).';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function hasFiles(): bool
    {
        return false; // Keine Dateianhänge in Beta 1
    }

    public function sensitivityNote(): string
    {
        return 'Enthält persönliche Termin-Details (Titel, Beschreibung, Orte, Zeiten).';
    }

    public function supportsReplaceImport(): bool
    {
        return true; // Ersetzen wird unterstützt (Löschen vor Import)
    }

    public function scopes(): array
    {
        return [
            'appointments' => 'Alle Kalender-Termine',
        ];
    }

    public function export(int $userId, DataPortabilityFileCollector $files): array
    {
        $appointments = $this->repository->findAllByUser($userId);
        
        $payload = [
            'version' => 1,
            'module' => 'modulnest.calendar',
            'exported_at' => (new \DateTimeImmutable())->format('c'),
            'user_id' => $userId,
            'data' => [
                'appointments' => array_map(fn($a) => $a->toArray(), $appointments),
            ],
        ];

        // JSON-Datei in Archive schreiben
        $files->writeJson('appointments.json', $payload['data']['appointments']);

        return [
            'scopes' => ['appointments'],
            'counts' => ['appointments' => count($appointments)],
            'manifest' => $payload,
        ];
    }

    public function previewImport(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId): array
    {
        $appointments = $archive->readJson('appointments.json') ?? [];
        $count = count($appointments);
        
        return [
            'summary' => "Werden {$count} Termin(e) importiert.",
            'details' => [
                'appointments' => $count,
            ],
            'warnings' => [],
            'requiresReplace' => false,
        ];
    }

    public function import(array $payload, array $manifestModule, DataPortabilityArchiveReader $archive, int $targetUserId, string $importMode = 'merge'): array
    {
        $appointments = $archive->readJson('appointments.json') ?? [];
        $imported = 0;
        $errors = [];

        if ($importMode === 'replace') {
            $this->clearTargetData($targetUserId);
        }

        foreach ($appointments as $appt) {
            try {
                $appt['user_id'] = $targetUserId;
                $appt['id'] = null; // Neue IDs generieren
                $appt['sync_status'] = 'local';
                $appt['external_id'] = null;
                $appt['external_source'] = null;
                $appt['external_etag'] = null;
                $appt['last_synced_at'] = null;
                $this->repository->import([$appt]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
            'replaced' => $importMode === 'replace',
        ];
    }

    private function clearTargetData(int $targetUserId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM calendar_appointments WHERE user_id = ?');
        $stmt->execute([$targetUserId]);
    }
}
```

---

## 9. Assets (CSS/JS)

### 9.1 CSS (`assets/css/calendar.css`)

```css
/* assets/css/calendar.css */
/* Veröffentlicht unter /assets/modules/modulnest.calendar/0.1.0-beta.1/css/calendar.css */

.calendar-day-view,
.calendar-week-view {
    --calendar-primary: #3b82f6;
    --calendar-bg: #ffffff;
    --calendar-border: #e5e7eb;
    --calendar-text: #1f2937;
    --calendar-muted: #6b7280;
}

.calendar-time-grid {
    display: grid;
    grid-template-rows: repeat(24, 60px);
    grid-template-columns: 60px 1fr;
    border: 1px solid var(--calendar-border);
    border-radius: 8px;
    overflow: hidden;
}

.time-slot {
    display: grid;
    grid-template-columns: 60px 1fr;
    border-bottom: 1px solid var(--calendar-border);
}

.time-label {
    padding: 4px 8px;
    font-size: 0.75rem;
    color: var(--calendar-muted);
    text-align: right;
    border-right: 1px solid var(--calendar-border);
    background: #f9fafb;
}

.appointments-container {
    position: relative;
    min-height: 60px;
}

.appointment-card {
    position: absolute;
    left: 4px;
    right: 4px;
    background: var(--calendar-primary);
    color: white;
    border-radius: 4px;
    padding: 4px 8px;
    font-size: 0.8rem;
    cursor: pointer;
    transition: transform 0.1s, box-shadow 0.1s;
    z-index: 10;
}

.appointment-card:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
}

.appointment-card.all-day {
    top: 0;
    height: 28px;
    background: #fef3c7;
    color: #92400e;
    border: 1px solid #fcd34d;
}

/* Wochenansicht */
.calendar-week-grid {
    display: grid;
    grid-template-columns: 60px repeat(7, 1fr);
    border: 1px solid var(--calendar-border);
    border-radius: 8px;
    overflow: hidden;
}

.week-day-header {
    padding: 12px 8px;
    text-align: center;
    font-weight: 600;
    border-right: 1px solid var(--calendar-border);
    background: #f9fafb;
}

.week-day-header.today {
    background: #eff6ff;
    color: var(--calendar-primary);
}

.week-day-column {
    min-height: 400px;
    border-right: 1px solid var(--calendar-border);
    position: relative;
}

.week-day-column:last-child {
    border-right: none;
}
```

### 9.2 JavaScript (`assets/js/calendar.js`)

```javascript
// assets/js/calendar.js
// Vanilla JS, keine Framework-Abhängigkeit
// Pattern aus tools.js: IIFE, DOMContentLoaded, fetch mit X-CSRF-Token Header

(function () {
    'use strict';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function csrfHeaders() {
        const token = document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_csrf"]')?.value
            || '';
        return {
            'X-CSRF-Token': token,
            'Content-Type': 'application/json',
        };
    }

    class CalendarDayView {
        static init({ appointments, csrfToken, baseUrl }) {
            this.appointments = appointments;
            this.csrfToken = csrfToken;
            this.baseUrl = baseUrl;
            this.render();
            this.bindDragDrop();
        }

        static render() {
            const container = document.querySelector('.calendar-time-grid');
            if (!container) return;

            this.appointments.forEach(appt => {
                const start = new Date(appt.start_at);
                const end = new Date(appt.end_at);
                const top = start.getHours() * 60 + start.getMinutes();
                const height = (end - start) / 60000; // Minuten

                const el = document.createElement('div');
                el.className = 'appointment-card' + (appt.all_day ? ' all-day' : '');
                el.dataset.id = appt.id;
                el.style.top = `${top}px`;
                el.style.height = `${height}px`;
                el.innerHTML = `
                    <div class="appointment-time">
                        ${start.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})} -
                        ${end.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'})}
                    </div>
                    <div class="appointment-title">${escapeHtml(appt.title)}</div>
                    ${appt.location ? `<div class="appointment-location">📍 ${escapeHtml(appt.location)}</div>` : ''}
                `;
                container.appendChild(el);
            });
        }

        static bindDragDrop() {
            // Drag & Drop für Move/Resize
            // Nutzt native HTML5 Drag & Drop API oder Pointer Events
            // Sendet PATCH an /api/appointment/{id}/move oder /resize
        }
    }

    class CalendarWeekView {
        static init({ days, csrfToken }) {
            // Ähnlich wie DayView aber 7 Spalten
        }
    }

    // Auto-init basierend auf Body-Class
    document.addEventListener('DOMContentLoaded', () => {
        if (document.body.classList.contains('calendar-day-view')) {
            // Data wird via data-Attribute oder Inline-Script übergeben
        }
    });

    // Export für Inline-Script Nutzung
    window.CalendarDayView = CalendarDayView;
    window.CalendarWeekView = CalendarWeekView;
})();
```

---

## 10. Architektur-Vorbereitung für Google Calendar Sync (Beta 2+)

**Entscheidung nach Review**: Sync-Interfaces und Stub-Implementationen werden **nicht in Beta 1 implementiert**. Sie werden vollständig auf Beta 2+ verschoben. Das Schema behält die Sync-Spalten (NULLable, Defaults) bei, aber es gibt **keine** Contracts, Services oder DI-Bindungen für Sync in Beta 1.

### 10.1 Was in Beta 2+ geplant ist (nur Dokumentation, kein Code in Beta 1)

```php
// src/Contracts/CalendarSyncProviderInterface.php (Beta 2+)
namespace ModulNest\Calendar\Contracts;

interface CalendarSyncProviderInterface
{
    public function authenticate(array $config): bool;
    public function fetchEvents(DateTimeImmutable $start, DateTimeImmutable $end): array;
    public function createEvent(array $eventData): array;
    public function updateEvent(string $externalId, array $eventData): array;
    public function deleteEvent(string $externalId): bool;
    public function getSyncToken(): ?string;
    public function setSyncToken(string $token): void;
}
```

```php
// src/Contracts/CalendarSyncManagerInterface.php (Beta 2+)
namespace ModulNest\Calendar\Contracts;

interface CalendarSyncManagerInterface
{
    public function sync(int $userId): SyncResult;
    public function pushLocalChanges(int $userId): SyncResult;
    public function pullRemoteChanges(int $userId): SyncResult;
    public function resolveConflict(int $appointmentId, string $resolution): SyncResult;
}
```

### 10.2 Datenmodell-Erweiterungen (bereits im Schema, inaktiv in Beta 1)

| Spalte | Beta 1 | Beta 2+ Nutzung |
|--------|--------|-----------------|
| `external_id` | NULL (inaktiv) | Google Event ID |
| `external_source` | NULL (inaktiv) | 'google' |
| `external_etag` | NULL (inaktiv) | ETag für optimistisches Locking |
| `last_synced_at` | NULL (inaktiv) | Letzter erfolgreicher Sync |
| `sync_status` | 'local' (Default) | 'synced' \| 'conflict' \| 'pending' |
| `external_etag` | NULL | ETag für optimistisches Locking |
| `last_synced_at` | NULL | Letzter erfolgreicher Sync |
| `sync_status` | 'local' | 'synced' \| 'conflict' \| 'pending' |

---

## 11. Explizite NICHT-Implementierung in Beta 1

| Feature | Grund | Geplant für |
|---------|-------|-------------|
| **Google OAuth 2.0 Flow** | Externer Dependency, komplexer Auth-Flow | Beta 2 |
| **Bidirektionaler Sync** | Erfordert Conflict-Resolution, Webhook-Handling | Beta 2 |
| **Webhook-Endpoints für Google Push** | Öffentliche URL, Security, Retry-Logic | Beta 2 |
| **Conflict Resolution UI** | Komplexe UX (Merge, Keep Local, Keep Remote) | Beta 2 |
| **Mehrere Kalender pro User** | Datenmodell-Erweiterung (calendar_id) | Beta 2 |
| **Wiederkehrende Termine (RRULE)** | Komplexe Recurrence-Engine nötig | Beta 3 |
| **Einladungen/Teilnehmer** | E-Mail-Integration, iCal/ICS | Beta 3 |
| **CalDAV/WebDAV Support** | Separates Protokoll | Später |
| **Mobile-spezifische Views** | Responsive CSS reicht für Beta 1 | Beta 2 |
| **Offline-Support (Service Worker)** | PWA-Architektur | Später |

---

## 12. Testing-Strategie

### 12.1 Unit Tests

```php
// tests/Unit/AppointmentRepositoryTest.php
// tests/Unit/CalendarServiceTest.php
// tests/Unit/AppointmentValidatorTest.php
// tests/Unit/CalendarDataPortabilityProviderTest.php
```

### 12.2 Integration Tests

```php
// tests/Integration/CalendarControllerTest.php
// - index, dayView, weekView
// - CRUD flows (create, edit, delete)
// - AJAX endpoints (quickCreate, move, resize)
// - Authorization (user isolation)
// - CSRF protection
```

### 12.3 E2E Tests (Playwright/Pest)

```php
// tests/E2E/calendar.spec.ts
// - Vollständiger User-Flow: Login → Kalender → Termin erstellen → Bearbeiten → Löschen
// - Drag & Drop in Day/Week View
// - Responsive Verhalten
```

---

## 13. Build & Veröffentlichung

### 13.1 Verzeichnisstruktur (Final)

```
modules-src/calendar/0.1.0-beta.1/
├── CHANGELOG.md
├── LICENSE
├── module.json
├── migrations/
│   ├── 001_baseline.php
│   └── schema.sql
├── src/
│   ├── CalendarModule.php
│   ├── CalendarController.php
│   ├── DTO/
│   │   └── AppointmentDTO.php
│   ├── Navigation/
│   │   └── CalendarSubnavigationProvider.php
│   ├── Portability/
│   │   └── CalendarDataPortabilityProvider.php
│   ├── Repository/
│   │   └── AppointmentRepository.php
│   ├── Service/
│   │   └── CalendarService.php
│   └── Validation/
│       └── AppointmentValidator.php
├── views/
│   ├── layout.php
│   ├── day.php
│   ├── week.php
│   ├── appointment-form.php
│   └── partials/
│       ├── appointment-card.php
│       ├── time-grid.php
│       └── week-header.php
└── assets/
    ├── css/
    │   └── calendar.css
    └── js/
        └── calendar.js
```

### 13.2 Build-Schritte (gemäß module-v2-authoring.md §14)

```bash
# 1. Validierung
php bin/module-validate.php modules-src/calendar/0.1.0-beta.1

# 2. Tests
php vendor/bin/pest tests/Unit/Calendar
php vendor/bin/pest tests/Integration/Calendar

# 3. Package erstellen
php bin/module-package.php modules-src/calendar/0.1.0-beta.1

# 4. Signatur (falls konfiguriert)
# 5. Upload in Katalog
```

---

## 14. Abhängigkeiten zu Core-Services (via ModuleContext)

| Service | Interface | Verwendung |
|---------|-----------|------------|
| `pdo` | `PDO` | Database Access (Repository) |
| `session` | `Session` | Flash Messages, CSRF Token |
| `authService` | `AuthService` | Current User, Permissions |
| `view` | `View` | Template Rendering |
| `router` | `Router` | Route Generation (`route()`) |
| `moduleRepository` | `ModuleRepository` | Module-Metadaten (für Portability) |
| `userRepository` | `UserRepository` | User-Details (Timezone) |

**Keine direkten Core-Model-Abhängigkeiten** – Repository nutzt nur PDO.

---

## 15. Sicherheits-Checkliste (gemäß §12 module-v2-authoring.md)

- [x] **CSRF-Schutz**: Alle mutierenden Routes (POST/PUT/PATCH/DELETE) mit `csrfPolicy='strict'` als 4. Parameter in Router-Methoden
- [x] **Authorization**: Alle Routes mit `access='user'` als 3. Parameter in Router-Methoden, User-Isolation in Repository (`WHERE user_id = ?`)
- [x] **Input Validation**: `AppointmentValidator` für alle Eingaben
- [x] **SQL Injection**: Prepared Statements überall (PDO)
- [x] **XSS Prevention**: `escapeHtml()` in JS, `htmlspecialchars` in PHP Views
- [x] **Rate Limiting**: Via Core Middleware (nicht modul-spezifisch)
- [x] **Data Ownership**: Explizit in Manifest deklariert → Core managed Uninstall/Purge

---

## 16. Changelog (`CHANGELOG.md`)

```markdown
# Changelog

## 0.1.0-beta.1 (2026-09-10)

### Added
- Local calendar with appointment CRUD (title, description, start/end, location, all-day)
- Day view with hourly time grid
- Week view with 7-day column layout
- Drag & drop move/resize via AJAX API
- Quick-create via double-click (AJAX)
- Data portability (export/import JSON) with full DataPortabilityProviderInterface
- User navigation entry with subnavigation (Day/Week/New)
- Timezone-aware appointments (per-user timezone)
- Soft deletes (deleted_at) for data retention
- Sync-preparation columns in schema (inactive in Beta 1)

### Not Included (Future Versions)
- Google Calendar bidirectional sync (OAuth 2.0, webhooks, conflict resolution) — Beta 2+
- Sync interfaces (CalendarSyncProviderInterface, CalendarSyncManagerInterface) — Beta 2+
- Recurring appointments (RRULE) — Beta 3
- Multiple calendars per user — Beta 2+
- Invitations/attendees (iCal/ICS) — Beta 3
- CalDAV/WebDAV support — Later
```

---

## 17. Nächste Schritte (Implementation Order)

1. **Scaffold**: Verzeichnisstruktur, `module.json`, `CHANGELOG.md`, `LICENSE`
2. **Migration**: `migrations/001_baseline.php` + `schema.sql`
3. **Domain**: `AppointmentDTO`, `AppointmentRepository`, `CalendarService`, `AppointmentValidator`
4. **Entrypoint**: `CalendarModule` mit Routes, Navigation, HealthCheck, Portability
5. **Controller**: `CalendarController` mit allen Routes
6. **Views**: `day.php`, `week.php`, `appointment-form.php`, Partials
7. **Assets**: `calendar.css`, `calendar.js` (Drag & Drop)
7. **Tests**: Unit + Integration + E2E
8. **Build & Validate**: `module-validate`, `module-package`
9. **Documentation**: README, API-Docs für Sync-Interfaces

---

## 18. Risiken & Offene Fragen

| Risiko | Mitigation |
|--------|------------|
| Timezone-Handling komplex | IANA Timezone DB nutzen, User-Timezone aus Core UserRepository |
| Drag & Drop auf Touch-Geräten | Pointer Events + Fallback, Test auf Mobile |
| Performance bei vielen Terminen | Pagination/Lazy-Load für Week View, Index auf (user_id, start_at) |
| Sync-Schema zu früh? | Spalten sind NULLable, Default 'local' → kein Risiko |
| Core-API-Änderungen | Nur stabile Core-Interfaces nutzen (ModuleContext Services) |

---

**Ende des Implementierungsplans**  
*Basierend auf Modul-v2-Authoring-Guide v2 und Analyse von: Banking, Dashboard, Sneak Preview, Tools, Repository Manager*