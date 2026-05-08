<?php

declare(strict_types=1);

namespace Modulon\Modules\Admin;

use Modulon\Core\AdminNavigationRegistry;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Auth\UserRepository;
use Modulon\Modules\Modules\ModuleRepository;
use RuntimeException;

final class AdminController
{
    /**
     * @var array<int, string>
     */
    private array $reservedPrefixes = ['admin', 'login', 'logout', 'internal'];

    public function __construct(
        private readonly ?ModuleRepository $modules,
        private readonly ?UserRepository $users,
        private readonly ?AppSettingRepository $settings,
        private readonly Session $session,
        private readonly ?AuthService $auth = null,
        private readonly ?string $basePath = null,
        private readonly ?AdminNavigationRegistry $adminNavigation = null,
        private readonly array $nativeModuleBindings = [],
    ) {
    }

    public function dashboard(Request $request): Response
    {
        return Response::redirect('/admin/modules');
    }

    public function modules(Request $request): Response
    {
        if ($this->modules === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $discoveredCount = 0;
        if (is_string($this->basePath) && $this->basePath !== '') {
            try {
                $discoveredCount = $this->modules->discoverNativeModules($this->basePath);
            } catch (\Throwable) {
                $this->session->flash('admin_error', 'Automatische Modulerkennung konnte nicht abgeschlossen werden.');
            }
        }
        if ($discoveredCount > 0) {
            $this->session->flash('admin_info', $discoveredCount . ' neues Modul automatisch erkannt und deaktiviert angelegt.');
        }

        return new Response(View::render('admin/modules', $this->viewData($request, [
            'title' => 'Admin',
            'admin_section' => 'modules',
            'message' => $this->session->pullFlash('admin_info'),
            'error' => $this->session->pullFlash('admin_error'),
            'modules' => $this->withModuleLinks($this->modules->listAll()),
            'legacy_entries' => $this->discoverLegacyEntries(),
        ])));
    }

    public function moduleSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if (preg_match('/^admin\/modules\/([0-9]+)\/edit$/', $path, $matches) === 1) {
            return $this->editModule($request, (int) $matches[1]);
        }

        return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
    }

    public function users(Request $request): Response
    {
        if ($this->users === null || $this->settings === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        return new Response(View::render('admin/users', $this->viewData($request, [
            'title' => 'Benutzerverwaltung',
            'admin_section' => 'users',
            'message' => $this->session->pullFlash('admin_info'),
            'error' => $this->session->pullFlash('admin_error'),
            'users' => $this->users->listForAdmin(),
            'current_user_id' => (int) (($this->auth?->currentUser()['id'] ?? 0)),
            'public_registration_enabled' => $this->settings->getBool('public_registration_enabled', true),
        ])));
    }

    public function userSubRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if (preg_match('/^admin\/users\/([0-9]+)\/edit$/', $path, $matches) === 1) {
            return $this->editUser($request, (int) $matches[1]);
        }

        return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
    }

    public function createUser(Request $request): Response
    {
        if ($this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $name = trim((string) $request->input('name', ''));
        $username = trim((string) $request->input('username', ''));
        $email = trim(strtolower((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $role = strtolower((string) $request->input('role', 'user'));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('admin_error', 'Name und gültige E-Mail sind erforderlich.');
            return Response::redirect('/admin/users');
        }

        if (mb_strlen($password) < 8) {
            $this->session->flash('admin_error', 'Passwort muss mindestens 8 Zeichen haben.');
            return Response::redirect('/admin/users');
        }

        if (!in_array($role, ['user', 'admin'], true)) {
            $role = 'user';
        }

        if ($username !== '') {
            if (preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username) !== 1) {
                $this->session->flash('admin_error', 'Benutzername muss 3-40 Zeichen lang sein (a-z, 0-9, . _ -).');
                return Response::redirect('/admin/users');
            }
            if ($this->users->usernameExists($username, 0)) {
                $this->session->flash('admin_error', 'Benutzername ist bereits vergeben.');
                return Response::redirect('/admin/users');
            }
        }

        if ($this->users->findByEmail($email) !== null) {
            $this->session->flash('admin_error', 'E-Mail ist bereits vergeben.');
            return Response::redirect('/admin/users');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            $this->session->flash('admin_error', 'Passwort konnte nicht gehasht werden.');
            return Response::redirect('/admin/users');
        }

        $userId = $this->users->createUser($name, $email, $passwordHash, $username === '' ? null : $username);
        $this->users->setPrimaryRole($userId, $role);

        $this->session->flash('admin_info', 'Benutzer angelegt.');
        return Response::redirect('/admin/users');
    }

    public function toggleUserBlocked(Request $request): Response
    {
        if ($this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $userId = (int) $request->input('user_id', '0');
        if ($userId <= 0) {
            $this->session->flash('admin_error', 'Ungültige Benutzer-ID.');
            return Response::redirect('/admin/users');
        }

        $target = $this->users->findById($userId);
        if ($target === null) {
            $this->session->flash('admin_error', 'Benutzer nicht gefunden.');
            return Response::redirect('/admin/users');
        }

        $currentUser = $this->auth?->currentUser();
        $currentUserId = (int) ($currentUser['id'] ?? 0);
        if ($currentUserId === $userId) {
            $this->session->flash('admin_error', 'Eigener Benutzer kann nicht gesperrt werden.');
            return Response::redirect('/admin/users');
        }

        $blocked = (int) ($target['is_blocked'] ?? 0) === 1;
        $this->users->setBlocked($userId, !$blocked);
        $this->session->flash('admin_info', $blocked ? 'Benutzer entsperrt.' : 'Benutzer gesperrt.');

        return Response::redirect('/admin/users');
    }

    public function deleteUser(Request $request): Response
    {
        if ($this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $userId = (int) $request->input('user_id', '0');
        if ($userId <= 0) {
            $this->session->flash('admin_error', 'Ungültige Benutzer-ID.');
            return Response::redirect('/admin/users');
        }

        $target = $this->users->findById($userId);
        if ($target === null) {
            $this->session->flash('admin_error', 'Benutzer nicht gefunden.');
            return Response::redirect('/admin/users');
        }

        $currentUser = $this->auth?->currentUser();
        $currentUserId = (int) ($currentUser['id'] ?? 0);
        if ($currentUserId === $userId) {
            $this->session->flash('admin_error', 'Eigener Benutzer kann nicht gelöscht werden.');
            return Response::redirect('/admin/users');
        }

        if ($this->users->hasRole($userId, 'admin') && $this->users->countAdmins() <= 1) {
            $this->session->flash('admin_error', 'Der letzte Admin kann nicht gelöscht werden.');
            return Response::redirect('/admin/users');
        }

        $this->users->deleteUser($userId);
        $this->session->flash('admin_info', 'Benutzer gelöscht.');
        return Response::redirect('/admin/users');
    }

    public function updateUser(Request $request): Response
    {
        if ($this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $userId = (int) $request->input('user_id', '0');
        $name = trim((string) $request->input('name', ''));
        $username = trim((string) $request->input('username', ''));
        $email = trim(strtolower((string) $request->input('email', '')));
        $role = strtolower((string) $request->input('role', 'user'));
        $isBlocked = $request->input('is_blocked') === '1';
        $newPassword = (string) $request->input('new_password', '');
        $passwordConfirmation = (string) $request->input('new_password_confirmation', '');

        if ($userId <= 0) {
            $this->session->flash('admin_error', 'Ungültige Benutzer-ID.');
            return Response::redirect('/admin/users');
        }

        $target = $this->users->findById($userId);
        if ($target === null) {
            $this->session->flash('admin_error', 'Benutzer nicht gefunden.');
            return Response::redirect('/admin/users');
        }

        if ($name === '') {
            $this->session->flash('admin_error', 'Name ist erforderlich.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('admin_error', 'Ungültige E-Mail-Adresse.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        }

        if ($role !== 'admin') {
            $role = 'user';
        }

        if ($username !== '') {
            if (preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username) !== 1) {
                $this->session->flash('admin_error', 'Benutzername muss 3-40 Zeichen lang sein (a-z, 0-9, . _ -).');
                return Response::redirect('/admin/users/' . $userId . '/edit');
            }
            if ($this->users->usernameExists($username, $userId)) {
                $this->session->flash('admin_error', 'Benutzername ist bereits vergeben.');
                return Response::redirect('/admin/users/' . $userId . '/edit');
            }
        }

        if ($this->users->emailExists($email, $userId)) {
            $this->session->flash('admin_error', 'E-Mail ist bereits vergeben.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        }

        $currentUserId = (int) (($this->auth?->currentUser()['id'] ?? 0));
        if ($currentUserId === $userId && $isBlocked) {
            $this->session->flash('admin_error', 'Eigener Benutzer kann nicht gesperrt werden.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        }

        if ($currentUserId === $userId && $role !== 'admin') {
            $this->session->flash('admin_error', 'Eigene Admin-Rolle kann nicht entfernt werden.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        }

        if ($this->users->hasRole($userId, 'admin') && $role !== 'admin' && $this->users->countAdmins() <= 1) {
            $this->session->flash('admin_error', 'Der letzte Admin kann nicht auf user gesetzt werden.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        }

        $passwordHash = null;
        if ($newPassword !== '' || $passwordConfirmation !== '') {
            if (mb_strlen($newPassword) < 8) {
                $this->session->flash('admin_error', 'Neues Passwort muss mindestens 8 Zeichen haben.');
                return Response::redirect('/admin/users/' . $userId . '/edit');
            }
            if (!hash_equals($newPassword, $passwordConfirmation)) {
                $this->session->flash('admin_error', 'Neue Passwörter stimmen nicht überein.');
                return Response::redirect('/admin/users/' . $userId . '/edit');
            }

            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                $this->session->flash('admin_error', 'Passwort konnte nicht gehasht werden.');
                return Response::redirect('/admin/users/' . $userId . '/edit');
            }
        }

        $this->users->updateUserByAdmin(
            $userId,
            $name,
            $username === '' ? null : $username,
            $email,
            $role,
            $isBlocked,
            $passwordHash,
        );

        $this->session->flash('admin_info', 'Benutzer gespeichert.');
        return Response::redirect('/admin/users/' . $userId . '/edit');
    }

    public function updateRegistrationSetting(Request $request): Response
    {
        if ($this->settings === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $enabled = $request->input('public_registration_enabled') === '1';
        $this->settings->setBool('public_registration_enabled', $enabled);
        $this->session->flash('admin_info', $enabled ? 'Öffentliche Registrierung aktiviert.' : 'Öffentliche Registrierung deaktiviert.');
        return Response::redirect('/admin/users');
    }

    public function toggleRegistrationSetting(Request $request): Response
    {
        if ($this->settings === null) {
            return $this->json(['ok' => false, 'message' => 'Service Unavailable'], 503);
        }

        $enabledRaw = $request->inputRaw('enabled', $request->input('enabled', '0'));
        $enabled = match (true) {
            is_bool($enabledRaw) => $enabledRaw,
            is_int($enabledRaw) => $enabledRaw === 1,
            is_string($enabledRaw) => in_array(strtolower(trim($enabledRaw)), ['1', 'true', 'yes', 'on'], true),
            default => false,
        };

        $this->settings->setBool('public_registration_enabled', $enabled);

        return $this->json([
            'ok' => true,
            'public_registration_enabled' => $enabled,
            'message' => $enabled ? 'Öffentliche Registrierung aktiviert.' : 'Öffentliche Registrierung deaktiviert.',
        ]);
    }

    public function createModule(Request $request): Response
    {
        if ($this->modules === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $name = trim((string) $request->input('name', ''));
        $description = $this->normalizeDescription((string) $request->input('description', ''));
        $routePrefix = $this->normalizePrefix((string) $request->input('route_prefix', ''));
        $access = strtolower((string) $request->input('access_level', 'public'));
        $handler = strtolower((string) $request->input('handler', 'native'));
        $legacyEntry = $this->normalizeLegacyEntry((string) $request->input('legacy_entry', ''));
        $adminEntry = $this->normalizeLegacyEntry((string) $request->input('admin_entry', ''));
        $enableOverlay = $request->input('enable_overlay') === '1';
        $isActive = $request->input('is_active') === '1';
        $showInHeader = $request->input('show_in_header') === '1';
        $showOnHome = $request->input('show_on_home') === '1';

        if ($name === '') {
            $this->session->flash('admin_error', 'Modulname ist erforderlich.');
            return Response::redirect('/admin/modules');
        }

        if (!$this->isValidPrefix($routePrefix)) {
            $this->session->flash('admin_error', 'Ungültiger Route Prefix.');
            return Response::redirect('/admin/modules');
        }

        if (in_array($routePrefix, $this->reservedPrefixes, true)) {
            $this->session->flash('admin_error', 'Route Prefix ist reserviert.');
            return Response::redirect('/admin/modules');
        }

        if (!in_array($access, ['public', 'user', 'admin'], true)) {
            $this->session->flash('admin_error', 'Ungültiges Zugriffslevel.');
            return Response::redirect('/admin/modules');
        }

        if (!in_array($handler, ['native', 'placeholder', 'legacy'], true)) {
            $this->session->flash('admin_error', 'Ungültiger Handler.');
            return Response::redirect('/admin/modules');
        }

        if ($this->modules->routePrefixExists($routePrefix, 0)) {
            $this->session->flash('admin_error', 'Route Prefix ist bereits vergeben.');
            return Response::redirect('/admin/modules');
        }

        if ($handler === 'legacy') {
            if ($legacyEntry === null || !$this->legacyEntryExists($legacyEntry)) {
                $this->session->flash('admin_error', 'Legacy Entry nicht gefunden.');
                return Response::redirect('/admin/modules');
            }

            if ($adminEntry !== null && !$this->moduleRelativeEntryExists($legacyEntry, $adminEntry)) {
                $this->session->flash('admin_error', 'Admin Entry nicht gefunden.');
                return Response::redirect('/admin/modules');
            }
        } else {
            $legacyEntry = null;
            $adminEntry = null;
            $enableOverlay = false;
        }

        try {
            $this->modules->createModule(
                $name,
                $description,
                $routePrefix,
                $access,
                $handler,
                $legacyEntry,
                $adminEntry,
                $enableOverlay,
                $isActive,
                $showInHeader,
                $showOnHome,
            );
        } catch (RuntimeException|\PDOException $exception) {
            $this->session->flash('admin_error', 'Modul konnte nicht erstellt werden.');
            return Response::redirect('/admin/modules');
        }

        $this->session->flash('admin_info', 'Modul erstellt.');
        return Response::redirect('/admin/modules');
    }

    public function updateModule(Request $request): Response
    {
        if ($this->modules === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $moduleId = (int) $request->input('module_id', '0');
        $name = trim((string) $request->input('name', ''));
        $description = $this->normalizeDescription((string) $request->input('description', ''));
        $routePrefix = $this->normalizePrefix((string) $request->input('route_prefix', ''));
        $access = strtolower((string) $request->input('access_level', 'public'));
        $handler = strtolower((string) $request->input('handler', 'native'));
        $legacyEntry = $this->normalizeLegacyEntry((string) $request->input('legacy_entry', ''));
        $adminEntry = $this->normalizeLegacyEntry((string) $request->input('admin_entry', ''));
        $enableOverlay = $request->input('enable_overlay') === '1';
        $isActive = $request->input('is_active') === '1';
        $showInHeader = $request->input('show_in_header') === '1';
        $showOnHome = $request->input('show_on_home') === '1';

        if ($moduleId <= 0) {
            $this->session->flash('admin_error', 'Ungültige Modul-ID.');
            return Response::redirect('/admin/modules');
        }

        if ($name === '') {
            $this->session->flash('admin_error', 'Modulname ist erforderlich.');
            return Response::redirect('/admin/modules/' . $moduleId . '/edit');
        }

        if (!$this->isValidPrefix($routePrefix)) {
            $this->session->flash('admin_error', 'Ungültiger Route Prefix.');
            return Response::redirect('/admin/modules/' . $moduleId . '/edit');
        }

        if (in_array($routePrefix, $this->reservedPrefixes, true)) {
            $this->session->flash('admin_error', 'Route Prefix ist reserviert.');
            return Response::redirect('/admin/modules/' . $moduleId . '/edit');
        }

        if (!in_array($access, ['public', 'user', 'admin'], true)) {
            $this->session->flash('admin_error', 'Ungültiges Zugriffslevel.');
            return Response::redirect('/admin/modules/' . $moduleId . '/edit');
        }

        if (!in_array($handler, ['native', 'placeholder', 'legacy'], true)) {
            $this->session->flash('admin_error', 'Ungültiger Handler.');
            return Response::redirect('/admin/modules/' . $moduleId . '/edit');
        }

        if ($handler === 'legacy') {
            if ($legacyEntry === null || !$this->legacyEntryExists($legacyEntry)) {
                $this->session->flash('admin_error', 'Legacy Entry nicht gefunden.');
                return Response::redirect('/admin/modules/' . $moduleId . '/edit');
            }

            if ($adminEntry !== null && !$this->moduleRelativeEntryExists($legacyEntry, $adminEntry)) {
                $this->session->flash('admin_error', 'Admin Entry nicht gefunden.');
                return Response::redirect('/admin/modules/' . $moduleId . '/edit');
            }
        } else {
            $legacyEntry = null;
            $adminEntry = null;
            $enableOverlay = false;
        }

        if ($this->modules->routePrefixExists($routePrefix, $moduleId)) {
            $this->session->flash('admin_error', 'Route Prefix ist bereits vergeben.');
            return Response::redirect('/admin/modules/' . $moduleId . '/edit');
        }

        if ($this->modules->nameExists($name, $moduleId)) {
            $this->session->flash('admin_error', 'Modulname ist bereits vergeben.');
            return Response::redirect('/admin/modules/' . $moduleId . '/edit');
        }

        $this->modules->updateModuleAdvanced(
            $moduleId,
            $name,
            $description,
            $routePrefix,
            $access,
            $handler,
            $legacyEntry,
            $adminEntry,
            $enableOverlay,
            $isActive,
            $showInHeader,
            $showOnHome,
        );
        $this->session->flash('admin_info', 'Modul gespeichert.');

        return Response::redirect('/admin/modules/' . $moduleId . '/edit');
    }

    public function toggleModuleFlags(Request $request): Response
    {
        if ($this->modules === null) {
            return $this->json(['ok' => false, 'message' => 'Service Unavailable'], 503);
        }

        $moduleIdRaw = $request->inputRaw('module_id', $request->input('module_id', '0'));
        $fieldRaw = $request->inputRaw('field', $request->input('field', ''));
        $enabledRaw = $request->inputRaw('enabled', $request->input('enabled', '0'));

        $moduleId = is_int($moduleIdRaw)
            ? $moduleIdRaw
            : (is_string($moduleIdRaw) ? (int) $moduleIdRaw : 0);
        $field = is_string($fieldRaw) ? strtolower(trim($fieldRaw)) : '';
        $enabled = match (true) {
            is_bool($enabledRaw) => $enabledRaw,
            is_int($enabledRaw) => $enabledRaw === 1,
            is_string($enabledRaw) => in_array(strtolower(trim($enabledRaw)), ['1', 'true', 'yes', 'on'], true),
            default => false,
        };

        if ($moduleId <= 0 || !in_array($field, ['enable_overlay', 'is_active'], true)) {
            return $this->json(['ok' => false, 'message' => 'Ungültige Eingabe.'], 422);
        }

        $module = $this->modules->findById($moduleId);
        if ($module === null) {
            return $this->json(['ok' => false, 'message' => 'Modul nicht gefunden.'], 404);
        }

        $handler = strtolower((string) ($module['handler'] ?? 'placeholder'));
        if ($field === 'enable_overlay' && $handler !== 'legacy') {
            return $this->json(['ok' => false, 'message' => 'Overlay ist nur für Legacy-Module verfügbar.'], 422);
        }

        $newOverlay = $field === 'enable_overlay' ? $enabled : ((int) ($module['enable_overlay'] ?? 0) === 1);
        $newActive = $field === 'is_active' ? $enabled : ((int) ($module['is_active'] ?? 0) === 1);
        $this->modules->updateFlags($moduleId, $newOverlay, $newActive);

        return $this->json([
            'ok' => true,
            'module_id' => $moduleId,
            'enable_overlay' => $newOverlay,
            'is_active' => $newActive,
        ]);
    }

    public function reorderModules(Request $request): Response
    {
        if ($this->modules === null) {
            return $this->json(['ok' => false, 'message' => 'Service Unavailable'], 503);
        }

        $rawIds = $request->inputRaw('module_ids', []);
        if (!is_array($rawIds)) {
            return $this->json(['ok' => false, 'message' => 'Ungültige Eingabe.'], 422);
        }

        $orderedIds = [];
        foreach ($rawIds as $rawId) {
            $id = is_int($rawId) ? $rawId : (is_string($rawId) ? (int) $rawId : 0);
            if ($id > 0 && !in_array($id, $orderedIds, true)) {
                $orderedIds[] = $id;
            }
        }

        $allModules = $this->modules->listAll();
        $allIds = array_values(array_map(
            static fn (array $module): int => (int) ($module['id'] ?? 0),
            $allModules
        ));
        sort($allIds);
        $incomingSorted = $orderedIds;
        sort($incomingSorted);

        if ($orderedIds === [] || $incomingSorted !== $allIds) {
            return $this->json(['ok' => false, 'message' => 'Ungültige Reihenfolge.'], 422);
        }

        $this->modules->reorderModules($orderedIds);

        return $this->json(['ok' => true, 'message' => 'Reihenfolge gespeichert.']);
    }

    public function deleteModule(Request $request): Response
    {
        if ($this->modules === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $moduleId = (int) $request->input('module_id', '0');
        if ($moduleId <= 0) {
            $this->session->flash('admin_error', 'Ungültige Modul-ID.');
            return Response::redirect('/admin/modules');
        }

        $this->modules->deleteModule($moduleId);
        $this->session->flash('admin_info', 'Modul gelöscht.');
        return Response::redirect('/admin/modules');
    }

    private function editModule(Request $request, int $moduleId): Response
    {
        if ($this->modules === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $module = $this->modules->findById($moduleId);
        if ($module === null) {
            return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
        }

        return new Response(View::render('admin/module-edit', $this->viewData($request, [
            'title' => 'Modul bearbeiten',
            'admin_section' => 'modules',
            'message' => $this->session->pullFlash('admin_info'),
            'error' => $this->session->pullFlash('admin_error'),
            'module' => $module,
            'native_binding' => $this->resolveNativeBinding($module),
            'legacy_entries' => $this->discoverLegacyEntries(),
        ])));
    }

    /**
     * @param array<string, mixed> $module
     * @return array<string, string>|null
     */
    private function resolveNativeBinding(array $module): ?array
    {
        $handler = strtolower((string) ($module['handler'] ?? ''));
        if ($handler !== 'native') {
            return null;
        }

        $prefix = trim(strtolower((string) ($module['route_prefix'] ?? '')), '/');
        if (isset($this->nativeModuleBindings[$prefix]) && is_array($this->nativeModuleBindings[$prefix])) {
            return $this->nativeModuleBindings[$prefix];
        }

        return [
            'module_key' => $prefix !== '' ? $prefix : '(unbekannt)',
            'internal_name' => 'Nicht registriertes natives Modul',
            'controller' => 'Kein nativer Controller-Mapping-Eintrag gefunden',
            'implementation_path' => '-',
            'route_binding' => '-',
        ];
    }

    private function editUser(Request $request, int $userId): Response
    {
        if ($this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])), 503);
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return new Response(View::render('errors/404', $this->viewData($request, ['title' => '404 Not Found'])), 404);
        }

        $user['role_name'] = $this->users->hasRole($userId, 'admin') ? 'admin' : 'user';

        return new Response(View::render('admin/user-edit', $this->viewData($request, [
            'title' => 'Benutzer bearbeiten',
            'admin_section' => 'users',
            'message' => $this->session->pullFlash('admin_info'),
            'error' => $this->session->pullFlash('admin_error'),
            'edit_user' => $user,
            'current_user_id' => (int) (($this->auth?->currentUser()['id'] ?? 0)),
        ])));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function viewData(Request $request, array $extra = []): array
    {
        $user = $this->auth?->currentUser();

        return array_merge([
            'current_path' => $request->path(),
            'auth' => [
                'is_authenticated' => $user !== null,
                'is_admin' => $this->auth?->isAdmin() ?? false,
                'user_name' => (string) ($user['name'] ?? ''),
            ],
        ], $extra);
    }

    private function normalizePrefix(string $prefix): string
    {
        return trim(strtolower($prefix), '/');
    }

    private function isValidPrefix(string $prefix): bool
    {
        return $prefix !== '' && preg_match('/^[a-z0-9][a-z0-9\\-\\/]*$/', $prefix) === 1;
    }

    private function normalizeLegacyEntry(string $entry): ?string
    {
        $entry = trim(str_replace('\\', '/', $entry), '/');
        if ($entry === '') {
            return null;
        }

        return $entry;
    }

    private function normalizeDescription(string $description): ?string
    {
        $description = trim($description);
        if ($description === '') {
            return null;
        }

        return mb_substr($description, 0, 255);
    }

    /**
     * @return array<int, string>
     */
    private function discoverLegacyEntries(): array
    {
        if (!is_string($this->basePath) || $this->basePath === '') {
            return [];
        }

        $legacyBase = $this->basePath . '/app/Legacy';
        if (!is_dir($legacyBase)) {
            return [];
        }

        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($legacyBase, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = str_replace('\\', '/', $fileInfo->getPathname());
            if (!str_ends_with($path, '.php')) {
                continue;
            }

            $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $legacyBase))), '/');
            $entries[] = $relative;
        }

        sort($entries);
        return $entries;
    }

    private function legacyEntryExists(string $relativeEntry): bool
    {
        if (!is_string($this->basePath) || $this->basePath === '') {
            return false;
        }

        $legacyBase = realpath($this->basePath . '/app/Legacy');
        if (!is_string($legacyBase)) {
            return false;
        }

        $candidate = realpath($legacyBase . '/' . $relativeEntry);
        if (!is_string($candidate) || !is_file($candidate)) {
            return false;
        }

        $legacyBase = str_replace('\\', '/', $legacyBase);
        $candidate = str_replace('\\', '/', $candidate);

        return str_starts_with($candidate, $legacyBase . '/');
    }

    private function moduleRelativeEntryExists(string $legacyEntry, string $moduleRelativeEntry): bool
    {
        if (!is_string($this->basePath) || $this->basePath === '') {
            return false;
        }

        $legacyBase = realpath($this->basePath . '/app/Legacy');
        if (!is_string($legacyBase)) {
            return false;
        }

        $entryFile = realpath($legacyBase . '/' . $legacyEntry);
        if (!is_string($entryFile) || !is_file($entryFile)) {
            return false;
        }

        $moduleRoot = str_replace('\\', '/', dirname($entryFile));
        $candidate = realpath($moduleRoot . '/' . ltrim($moduleRelativeEntry, '/'));
        if (!is_string($candidate) || !is_file($candidate)) {
            return false;
        }

        $candidate = str_replace('\\', '/', $candidate);
        return str_starts_with($candidate, $moduleRoot . '/');
    }

    /**
     * @param array<int, array<string, mixed>> $modules
     * @return array<int, array<string, mixed>>
     */
    private function withModuleLinks(array $modules): array
    {
        foreach ($modules as &$module) {
            $prefix = trim((string) ($module['route_prefix'] ?? ''), '/');
            $module['module_url'] = $prefix !== '' ? '/' . $prefix . '/' : null;
            $module['module_admin_url'] = $this->resolveModuleAdminUrl($module, $prefix);
        }

        return $modules;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    /**
     * @param array<string, mixed> $module
     */
    private function resolveModuleAdminUrl(array $module, string $prefix): ?string
    {
        if ($prefix === '') {
            return null;
        }

        if (strtolower((string) ($module['handler'] ?? '')) === 'native') {
            return $this->adminNavigation?->adminUrlForModule($prefix);
        }

        if (str_ends_with($prefix, '/admin')) {
            return '/' . $prefix . '/';
        }

        $handler = strtolower((string) ($module['handler'] ?? 'placeholder'));
        if ($handler !== 'legacy') {
            return null;
        }

        $legacyEntry = $this->normalizeLegacyEntry((string) ($module['legacy_entry'] ?? ''));
        $adminEntry = $this->normalizeLegacyEntry((string) ($module['admin_entry'] ?? ''));
        if ($legacyEntry === null || !is_string($this->basePath) || $this->basePath === '') {
            return null;
        }

        $legacyBase = realpath($this->basePath . '/app/Legacy');
        if (!is_string($legacyBase)) {
            return null;
        }

        $entryFile = realpath($legacyBase . '/' . $legacyEntry);
        if (!is_string($entryFile) || !is_file($entryFile)) {
            return null;
        }

        if ($adminEntry !== null && $this->moduleRelativeEntryExists($legacyEntry, $adminEntry)) {
            return '/' . $prefix . '/' . ltrim($adminEntry, '/');
        }

        $moduleRoot = str_replace('\\', '/', dirname($entryFile));
        $adminIndex = realpath($moduleRoot . '/admin/index.php');
        if (!is_string($adminIndex) || !is_file($adminIndex)) {
            return null;
        }

        $legacyBase = str_replace('\\', '/', $legacyBase);
        $adminIndex = str_replace('\\', '/', $adminIndex);
        if (!str_starts_with($adminIndex, $legacyBase . '/')) {
            return null;
        }

        return '/' . $prefix . '/admin/';
    }
}
