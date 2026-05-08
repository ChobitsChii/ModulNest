<?php

declare(strict_types=1);

namespace Modulon\Modules\Auth;

use InvalidArgumentException;
use Modulon\Core\Request;
use Modulon\Core\Response;
use Modulon\Core\Session;
use Modulon\Core\View;
use RuntimeException;
use Throwable;

final class AuthController
{
    public function __construct(
        private readonly ?AuthService $auth,
        private readonly Session $session,
        private readonly bool $publicRegistrationEnabled = true,
    ) {
    }

    public function showLoginForm(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        if ($this->auth->isAuthenticated()) {
            return Response::redirect('/');
        }

        return new Response(View::render('auth/login', $this->viewData($request, [
            'title' => 'Login',
            'error' => $this->session->pullFlash('login_error'),
            'info' => $this->session->pullFlash('auth_info'),
        ])));
    }

    public function login(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $identifier = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        $rememberMe = $request->input('remember_me') === '1';
        $result = $this->auth->attemptLogin($identifier, $password, $rememberMe, [
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'forwarded_for' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'request_method' => $request->method(),
            'request_path' => $request->path(),
            'session_id' => session_id(),
            'session_active' => session_status() === PHP_SESSION_ACTIVE,
            'csrf_check' => 'not_implemented',
            'input_identifier_present' => trim($identifier) !== '',
            'input_password_present' => trim($password) !== '',
        ]);

        if ($result === AuthService::LOGIN_INVALID) {
            $this->session->flash('login_error', 'Login fehlgeschlagen.');
            return Response::redirect('/login');
        }

        if ($result === AuthService::LOGIN_2FA_REQUIRED) {
            return Response::redirect('/login/2fa');
        }

        $this->session->flash('auth_info', 'Login erfolgreich.');
        return Response::redirect('/');
    }

    public function showTwoFactorForm(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $pending = $this->auth->pendingUser();
        if ($pending === null) {
            return Response::redirect('/login');
        }

        return new Response(View::render('auth/twofactor', $this->viewData($request, [
            'title' => '2FA',
            'error' => $this->session->pullFlash('twofa_error'),
            'info' => $this->session->pullFlash('twofa_info'),
            'pending_email' => (string) ($pending['email'] ?? ''),
            'show_totp' => (int) ($pending['totp_enabled'] ?? 0) === 1,
            'show_webauthn' => (int) ($pending['webauthn_enabled'] ?? 0) === 1,
        ])));
    }

    public function verifyTwoFactorTotp(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $code = (string) $request->input('code', '');
        if (!$this->auth->completePendingLoginWithTotp($code)) {
            $this->session->flash('twofa_error', 'TOTP-Code ungültig.');
            return Response::redirect('/login/2fa');
        }

        $this->session->flash('auth_info', 'Login erfolgreich.');
        return Response::redirect('/');
    }

    public function verifyTwoFactorRecovery(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $code = (string) $request->input('code', '');
        if (!$this->auth->completePendingLoginWithRecoveryCode($code)) {
            $this->session->flash('twofa_error', 'Recovery Code ungültig.');
            return Response::redirect('/login/2fa');
        }

        $this->session->flash('auth_info', 'Login erfolgreich.');
        return Response::redirect('/');
    }

    public function webAuthnLoginOptions(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->json(['success' => false, 'message' => 'Service nicht verfügbar.'], 503);
        }

        try {
            $pending = $this->auth->pendingUser();
            $userId = is_array($pending) ? (int) ($pending['id'] ?? 0) : null;
            $options = $this->auth->beginWebAuthnLogin($userId && $userId > 0 ? $userId : null);
            return $this->json(['success' => true, 'publicKey' => $options['publicKey'] ?? $options]);
        } catch (Throwable $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        }
    }

    public function webAuthnLoginVerify(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->json(['success' => false, 'message' => 'Service nicht verfügbar.'], 503);
        }

        $payload = [
            'id' => (string) $request->input('id', ''),
            'clientDataJSON' => (string) $request->input('clientDataJSON', ''),
            'authenticatorData' => (string) $request->input('authenticatorData', ''),
            'signature' => (string) $request->input('signature', ''),
            'userHandle' => (string) $request->input('userHandle', ''),
        ];

        if (!$this->auth->finishWebAuthnLogin($payload)) {
            return $this->json(['success' => false, 'message' => 'Passkey-Verifizierung fehlgeschlagen.'], 400);
        }

        return $this->json(['success' => true, 'redirect' => '/']);
    }

    public function showSecurity(Request $request): Response
    {
        return Response::redirect('/profil/security');
    }

    public function startTotpSetup(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $this->auth->startTotpSetup((int) $user['id']);
        $this->session->flash('security_info', 'TOTP-Secret erzeugt. Bitte QR-Code scannen und Code bestätigen.');
        return Response::redirect('/profil/security');
    }

    public function confirmTotpSetup(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $codes = $this->auth->confirmTotpSetup((int) $user['id'], (string) $request->input('code', ''));
        if ($codes === null) {
            $this->session->flash('security_error', 'TOTP-Code ungültig.');
            return Response::redirect('/profil/security');
        }

        $this->session->flash('security_info', 'TOTP aktiviert und Recovery Codes erstellt.');
        return Response::redirect('/profil/security');
    }

    public function disableTotp(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $this->auth->disableTotp((int) $user['id']);
        $this->session->flash('security_info', 'TOTP deaktiviert.');
        return Response::redirect('/profil/security');
    }

    public function regenerateRecoveryCodes(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $this->auth->regenerateRecoveryCodes((int) $user['id']);
        $this->session->flash('security_info', 'Recovery Codes neu generiert.');
        return Response::redirect('/profil/security');
    }

    public function webAuthnRegisterOptions(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->json(['success' => false, 'message' => 'Service nicht verfügbar.'], 503);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return $this->json(['success' => false, 'message' => 'Nicht eingeloggt.'], 401);
        }

        try {
            $options = $this->auth->beginWebAuthnRegistration($user);
            return $this->json(['success' => true, 'publicKey' => $options['publicKey'] ?? $options]);
        } catch (Throwable $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        }
    }

    public function webAuthnRegisterVerify(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->json(['success' => false, 'message' => 'Service nicht verfügbar.'], 503);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return $this->json(['success' => false, 'message' => 'Nicht eingeloggt.'], 401);
        }

        $payload = [
            'clientDataJSON' => (string) $request->input('clientDataJSON', ''),
            'attestationObject' => (string) $request->input('attestationObject', ''),
            'transports' => $request->inputRaw('transports'),
        ];
        $label = (string) $request->input('label', 'Passkey');

        if (!$this->auth->finishWebAuthnRegistration($user, $payload, $label)) {
            return $this->json(['success' => false, 'message' => 'Passkey konnte nicht gespeichert werden.'], 400);
        }

        return $this->json(['success' => true]);
    }

    public function deleteWebAuthnCredential(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        $user = $this->auth->currentUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $credentialId = (int) $request->input('credential_id', '0');
        if ($credentialId > 0) {
            $this->auth->removeWebAuthnCredential((int) $user['id'], $credentialId);
            $this->session->flash('security_info', 'Passkey entfernt.');
        }

        return Response::redirect('/profil/security');
    }

    public function showRegisterForm(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        if (!$this->publicRegistrationEnabled) {
            return new Response(View::render('errors/403', $this->viewData($request, [
                'title' => '403 Forbidden',
            ])), 403);
        }

        return new Response(View::render('auth/register', $this->viewData($request, [
            'title' => 'Registrierung',
            'error' => $this->session->pullFlash('register_error'),
            'info' => $this->session->pullFlash('register_info'),
        ])));
    }

    public function register(Request $request): Response
    {
        if ($this->auth === null) {
            return $this->serviceUnavailable($request);
        }

        if (!$this->publicRegistrationEnabled) {
            return new Response(View::render('errors/403', $this->viewData($request, [
                'title' => '403 Forbidden',
            ])), 403);
        }

        $name = (string) $request->input('name', '');
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        try {
            $this->auth->register($name, $email, $password);
            $this->session->flash('auth_info', 'Benutzer erfolgreich registriert.');
            return Response::redirect('/login');
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->session->flash('register_error', $exception->getMessage());
            return Response::redirect('/internal/register');
        }
    }

    public function logout(Request $request): Response
    {
        if ($this->auth !== null) {
            $rememberToken = $request->cookie($this->auth->rememberCookieName());
            $this->auth->logout($rememberToken);
        }

        $this->session->start();
        $this->session->flash('auth_info', 'Logout erfolgreich.');

        return Response::redirect('/login');
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

    private function serviceUnavailable(Request $request): Response
    {
        return new Response(
            View::render('errors/500', $this->viewData($request, ['title' => 'Service Unavailable'])),
            503,
        );
    }
}
