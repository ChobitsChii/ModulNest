<?php

declare(strict_types=1);

namespace Modulon\Modules\User;

use DateTimeImmutable;
use DateTimeZone;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use Modulon\Modules\Auth\AuthService;
use Modulon\Modules\Auth\UserRepository;
use Modulon\Modules\FantasyCards\FantasyCardsProfileService;

final class UserController
{
    private const DASHBOARD_AUTO_REFRESH_DEFAULT_MINUTES = 30;
    private const DASHBOARD_AUTO_REFRESH_MIN_MINUTES = 5;
    private const DASHBOARD_AUTO_REFRESH_MAX_MINUTES = 240;

    public function __construct(
        private readonly ?AuthService $auth,
        private readonly ?UserRepository $users,
        private readonly Session $session,
        private readonly ?FantasyCardsProfileService $fantasyCardsProfile = null,
        private readonly bool $dataPortabilityAvailable = false,
    ) {
    }

    public function profile(Request $request): Response
    {
        return $this->renderUserArea($request, 'profile');
    }

    public function subRoute(Request $request): Response
    {
        $path = trim($request->path(), '/');
        if ($path === 'profil' || $path === 'profil/') {
            return $this->renderUserArea($request, 'profile');
        }
        if ($path === 'profil/security') {
            return $this->renderUserArea($request, 'security');
        }
        if ($path === 'profil/settings') {
            return $this->renderUserArea($request, 'settings');
        }
        if ($path === 'profil/fantasy-cards' && $this->fantasyCardsProfile !== null) {
            return $this->renderUserArea($request, 'fantasy-cards');
        }
        if ($path === 'profil/data-portability' && $this->dataPortabilityAvailable) {
            return Response::redirect('/profil/data-portability');
        }

        return new Response(View::render('errors/404', $this->viewData($request, [
            'title' => '404 Not Found',
        ])), 404);
    }

    public function updateFantasyCardsProfile(Request $request): Response
    {
        if ($this->auth === null || $this->fantasyCardsProfile === null) {
            return Response::redirect('/profil');
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $cardIds = $request->inputRaw('showcase_card_ids', []);
        if (!is_array($cardIds)) {
            $cardIds = [];
        }

        $this->fantasyCardsProfile->saveProfile((int) ($user['id'] ?? 0), [
            'favorite_card_id' => (int) $request->input('favorite_card_id', '0'),
            'showcase_mode' => (string) $request->input('showcase_mode', 'manual'),
            'showcase_card_ids' => $cardIds,
            'is_collection_public' => $this->toBool($request->inputRaw('is_collection_public', 0)),
            'is_progress_public' => $this->toBool($request->inputRaw('is_progress_public', 0)),
            'is_favorites_public' => $this->toBool($request->inputRaw('is_favorites_public', 0)),
        ]);
        $this->session->flash('profile_cards_info', 'Sammelkarten-Profil gespeichert.');

        return Response::redirect('/profil/fantasy-cards');
    }

    public function updateProfile(Request $request): Response
    {
        if ($this->auth === null || $this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, [
                'title' => 'Service Unavailable',
            ])), 503);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $name = trim((string) $request->input('name', ''));
        $username = trim((string) $request->input('username', ''));
        $email = trim(strtolower((string) $request->input('email', '')));

        if ($name === '') {
            $this->session->flash('profile_error', 'Name ist erforderlich.');
            return Response::redirect('/profil');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('profile_error', 'Ungültige E-Mail-Adresse.');
            return Response::redirect('/profil');
        }

        if ($username !== '') {
            if (preg_match('/^[a-zA-Z0-9_.-]{3,40}$/', $username) !== 1) {
                $this->session->flash('profile_error', 'Benutzername muss 3-40 Zeichen lang sein (a-z, 0-9, . _ -).');
                return Response::redirect('/profil');
            }
            if ($this->users->usernameExists($username, $userId)) {
                $this->session->flash('profile_error', 'Benutzername ist bereits vergeben.');
                return Response::redirect('/profil');
            }
        }

        if ($this->users->emailExists($email, $userId)) {
            $this->session->flash('profile_error', 'E-Mail ist bereits vergeben.');
            return Response::redirect('/profil');
        }

        $this->users->updateProfile($userId, $name, $username === '' ? null : $username, $email);
        $this->session->flash('profile_info', 'Profil gespeichert.');

        return Response::redirect('/profil');
    }

    public function updatePassword(Request $request): Response
    {
        if ($this->auth === null || $this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, [
                'title' => 'Service Unavailable',
            ])), 503);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }
        $userId = (int) ($user['id'] ?? 0);
        $currentPassword = (string) $request->input('current_password', '');
        $newPassword = (string) $request->input('new_password', '');
        $newPasswordConfirmation = (string) $request->input('new_password_confirmation', '');

        $existingHash = $this->users->findPasswordHashById($userId);
        if (!is_string($existingHash) || !password_verify($currentPassword, $existingHash)) {
            $this->session->flash('security_error', 'Aktuelles Passwort ist ungültig.');
            return Response::redirect('/profil/security');
        }

        if (mb_strlen($newPassword) < 8) {
            $this->session->flash('security_error', 'Neues Passwort muss mindestens 8 Zeichen haben.');
            return Response::redirect('/profil/security');
        }

        if (!hash_equals($newPassword, $newPasswordConfirmation)) {
            $this->session->flash('security_error', 'Neue Passwörter stimmen nicht überein.');
            return Response::redirect('/profil/security');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            $this->session->flash('security_error', 'Passwort konnte nicht gehasht werden.');
            return Response::redirect('/profil/security');
        }

        $this->users->updatePasswordHash($userId, $passwordHash);
        $this->session->flash('security_info', 'Passwort geändert.');

        return Response::redirect('/profil/security');
    }

    public function updateSettings(Request $request): Response
    {
        if ($this->auth === null || $this->users === null) {
            return new Response(View::render('errors/500', $this->viewData($request, [
                'title' => 'Service Unavailable',
            ])), 503);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $timezone = trim((string) $request->input('timezone', 'UTC'));
        $validZones = DateTimeZone::listIdentifiers();
        if (!in_array($timezone, $validZones, true)) {
            $this->session->flash('settings_error', 'Ungültige Zeitzone.');
            return Response::redirect('/profil/settings');
        }

        $autoRefreshEnabled = $this->toBool($request->inputRaw('dashboard_auto_refresh_enabled', 0));
        $intervalRaw = $request->inputRaw('dashboard_auto_refresh_interval_minutes', self::DASHBOARD_AUTO_REFRESH_DEFAULT_MINUTES);
        $interval = $this->normalizeDashboardAutoRefreshInterval($intervalRaw);
        if ($interval === null) {
            $this->session->flash(
                'settings_error',
                sprintf(
                    'Aktualisierungsintervall muss zwischen %d und %d Minuten liegen.',
                    self::DASHBOARD_AUTO_REFRESH_MIN_MINUTES,
                    self::DASHBOARD_AUTO_REFRESH_MAX_MINUTES
                )
            );
            return Response::redirect('/profil/settings');
        }

        $this->users->updateSettings($userId, $timezone, $autoRefreshEnabled, $interval);
        $this->session->flash('settings_info', 'Einstellungen gespeichert.');
        return Response::redirect('/profil/settings');
    }

    private function renderUserArea(Request $request, string $tab): Response
    {
        if ($this->auth === null) {
            return new Response(View::render('errors/500', $this->viewData($request, [
                'title' => 'Service Unavailable',
            ])), 503);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) ($user['id'] ?? 0);
        $allowedTabs = ['profile', 'security', 'settings'];
        if ($this->fantasyCardsProfile !== null) {
            $allowedTabs[] = 'fantasy-cards';
        }
        if ($this->dataPortabilityAvailable) {
            $allowedTabs[] = 'data-portability';
        }
        $activeTab = in_array($tab, $allowedTabs, true) ? $tab : 'profile';
        $timezoneValue = trim((string) ($user['timezone'] ?? ''));
        if ($timezoneValue === '' || !in_array($timezoneValue, DateTimeZone::listIdentifiers(), true)) {
            $timezoneValue = 'UTC';
        }
        $dashboardAutoRefreshEnabled = (int) ($user['dashboard_auto_refresh_enabled'] ?? 1) === 1;
        $dashboardAutoRefreshIntervalMinutes = $this->normalizeDashboardAutoRefreshInterval($user['dashboard_auto_refresh_interval_minutes'] ?? null)
            ?? self::DASHBOARD_AUTO_REFRESH_DEFAULT_MINUTES;

        return new Response(View::render('user/area', $this->viewData($request, [
            'title' => match ($activeTab) {
                'security' => 'Profil / Sicherheit',
                'settings' => 'Profil / Einstellungen',
                'data-portability' => 'Profil / Meine Daten',
                default => 'Profil',
            },
            'user_tab' => $activeTab,
            'profile_user' => $user,
            'timezone_options' => $this->buildTimezoneOptions(),
            'settings_timezone' => $timezoneValue,
            'settings_dashboard_auto_refresh_enabled' => $dashboardAutoRefreshEnabled,
            'settings_dashboard_auto_refresh_interval_minutes' => $dashboardAutoRefreshIntervalMinutes,
            'profile_message' => $this->session->pullFlash('profile_info'),
            'profile_error' => $this->session->pullFlash('profile_error'),
            'security_message' => $this->session->pullFlash('security_info'),
            'security_error' => $this->session->pullFlash('security_error'),
            'settings_message' => $this->session->pullFlash('settings_info'),
            'settings_error' => $this->session->pullFlash('settings_error'),
            'profile_cards_message' => $this->session->pullFlash('profile_cards_info'),
            'profile_cards_error' => $this->session->pullFlash('profile_cards_error'),
            'fantasy_cards_profile_available' => $this->fantasyCardsProfile !== null,
            'data_portability_available' => $this->dataPortabilityAvailable,
            'fantasy_cards_profile' => $this->fantasyCardsProfile !== null ? $this->fantasyCardsProfile->profileData($userId) : null,
            'totp_enabled' => (int) ($user['totp_enabled'] ?? 0) === 1,
            'webauthn_enabled' => (int) ($user['webauthn_enabled'] ?? 0) === 1,
            'pending_totp' => $this->auth->pendingTotpSetup($userId),
            'recovery_codes' => $this->auth->pullGeneratedRecoveryCodes($userId),
            'recovery_count' => $this->auth->recoveryCodeCount($userId),
            'credentials' => $this->auth->listWebAuthnCredentials($userId),
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

    /**
     * @return array<int, array{value:string,label:string}>
     */
    private function buildTimezoneOptions(): array
    {
        $zones = DateTimeZone::listIdentifiers();
        $now = new DateTimeImmutable('now');
        $options = [];
        foreach ($zones as $zone) {
            try {
                $tz = new DateTimeZone($zone);
                $offset = $tz->getOffset($now);
            } catch (\Throwable) {
                continue;
            }
            $sign = $offset >= 0 ? '+' : '-';
            $abs = abs($offset);
            $hours = str_pad((string) intdiv($abs, 3600), 2, '0', STR_PAD_LEFT);
            $minutes = str_pad((string) intdiv($abs % 3600, 60), 2, '0', STR_PAD_LEFT);
            $options[] = [
                'value' => $zone,
                'label' => $zone . ' (UTC' . $sign . $hours . ':' . $minutes . ')',
            ];
        }

        usort($options, static fn (array $a, array $b): int => strcmp($a['value'], $b['value']));
        return $options;
    }

    private function normalizeDashboardAutoRefreshInterval(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !preg_match('/^\d+$/', $value)) {
                return null;
            }
            $value = (int) $value;
        }

        if (!is_int($value)) {
            return null;
        }

        if ($value < self::DASHBOARD_AUTO_REFRESH_MIN_MINUTES || $value > self::DASHBOARD_AUTO_REFRESH_MAX_MINUTES) {
            return null;
        }

        return $value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
        }

        return false;
    }
}
