<?php

declare(strict_types=1);

namespace Modulon\Modules\Auth;

use DateTimeZone;
use InvalidArgumentException;
use chillerlan\QRCode\QRCode;
use lbuchs\WebAuthn\WebAuthn;
use Modulon\Core\CsrfTokenManager;
use Modulon\Core\RotatingFileLogger;
use Modulon\Core\Session;
use RobThree\Auth\Providers\Qr\GoogleChartsQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use RuntimeException;
use Throwable;

final class AuthService
{
    public const LOGIN_INVALID = 'invalid';
    public const LOGIN_SUCCESS = 'success';
    public const LOGIN_2FA_REQUIRED = '2fa_required';

    private const SESSION_USER_ID = 'auth_user_id';
    private const SESSION_PENDING_USER_ID = 'auth_pending_user_id';
    private const SESSION_PENDING_REMEMBER = 'auth_pending_remember';
    private const SESSION_TOTP_SETUP = 'auth_totp_setup_secret';
    private const SESSION_WEBAUTHN_CHALLENGE = 'auth_webauthn_challenge';
    private const SESSION_WEBAUTHN_MODE = 'auth_webauthn_mode';
    private const SESSION_WEBAUTHN_EXPECTED_USER_ID = 'auth_webauthn_expected_user_id';
    private const SESSION_RECOVERY_CODES_PLAIN = 'auth_recovery_codes_plain';
    private const DEFAULT_REMEMBER_COOKIE = 'modulon_remember';

    public function __construct(
        private readonly UserRepository $users,
        private readonly RememberTokenRepository $rememberTokens,
        private readonly WebAuthnCredentialRepository $webauthnCredentials,
        private readonly RecoveryCodeRepository $recoveryCodes,
        private readonly Session $session,
        private readonly array $config,
        private readonly CsrfTokenManager $csrfTokenManager,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function register(string $name, string $email, string $password): void
    {
        $name = trim($name);
        $email = trim(strtolower($email));

        if ($name === '') {
            throw new InvalidArgumentException('Name ist erforderlich.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Ungültige E-Mail-Adresse.');
        }

        if (mb_strlen($password) < 8) {
            throw new InvalidArgumentException('Passwort muss mindestens 8 Zeichen haben.');
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new RuntimeException('E-Mail ist bereits vergeben.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Passwort konnte nicht gehasht werden.');
        }

        $userId = $this->users->createUser($name, $email, $passwordHash);
        $this->users->attachRoleByName($userId, 'user');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function attemptLogin(string $identifier, string $password, bool $rememberMe, array $context = []): string
    {
        $rawIdentifier = trim($identifier);
        $normalizedEmail = trim(strtolower($rawIdentifier));
        $isEmailIdentifier = filter_var($rawIdentifier, FILTER_VALIDATE_EMAIL) !== false;

        $user = null;
        if ($isEmailIdentifier) {
            $user = $this->users->findByEmail($normalizedEmail);
        } elseif ($rawIdentifier !== '') {
            $user = $this->users->findByUsername($rawIdentifier);
            if ($user === null) {
                // Browser-Autofill kann je nach Feldzuordnung variieren.
                $user = $this->users->findByEmail($normalizedEmail);
            }
        }

        if ($user === null) {
            $this->logAuthEvent('auth_login_attempt', [
                'status' => 'failed',
                'reason' => 'user_not_found',
                'identifier' => $rawIdentifier,
                'identifier_type' => $isEmailIdentifier ? 'email' : ($rawIdentifier === '' ? 'empty' : 'username'),
                'user_found' => false,
                'password_verified' => false,
                'user_blocked' => false,
                'remember_requested' => $rememberMe,
                'context' => $this->sanitizeLoginContext($context),
            ]);
            return self::LOGIN_INVALID;
        }
        if ((int) ($user['is_blocked'] ?? 0) === 1) {
            $this->logAuthEvent('auth_login_attempt', [
                'status' => 'failed',
                'reason' => 'user_blocked',
                'identifier' => $rawIdentifier,
                'identifier_type' => $isEmailIdentifier ? 'email' : 'username',
                'user_id' => (int) ($user['id'] ?? 0),
                'user_found' => true,
                'password_verified' => false,
                'user_blocked' => true,
                'remember_requested' => $rememberMe,
                'context' => $this->sanitizeLoginContext($context),
            ]);
            return self::LOGIN_INVALID;
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            $this->logAuthEvent('auth_login_attempt', [
                'status' => 'failed',
                'reason' => $hash === '' ? 'password_hash_missing' : 'password_mismatch',
                'identifier' => $rawIdentifier,
                'identifier_type' => $isEmailIdentifier ? 'email' : 'username',
                'user_id' => (int) ($user['id'] ?? 0),
                'user_found' => true,
                'password_verified' => false,
                'user_blocked' => false,
                'remember_requested' => $rememberMe,
                'context' => $this->sanitizeLoginContext($context),
            ]);
            return self::LOGIN_INVALID;
        }

        $userId = (int) $user['id'];
        if ($this->requiresTwoFactor($user)) {
            $this->logAuthEvent('auth_login_attempt', [
                'status' => 'pending_2fa',
                'reason' => 'two_factor_required',
                'identifier' => $rawIdentifier,
                'identifier_type' => $isEmailIdentifier ? 'email' : 'username',
                'user_id' => $userId,
                'user_found' => true,
                'password_verified' => true,
                'user_blocked' => false,
                'remember_requested' => $rememberMe,
                'context' => $this->sanitizeLoginContext($context),
            ]);
            $this->session->set(self::SESSION_PENDING_USER_ID, $userId);
            $this->session->set(self::SESSION_PENDING_REMEMBER, $rememberMe);
            return self::LOGIN_2FA_REQUIRED;
        }

        $this->logAuthEvent('auth_login_attempt', [
            'status' => 'success',
            'reason' => 'login_success',
            'identifier' => $rawIdentifier,
            'identifier_type' => $isEmailIdentifier ? 'email' : 'username',
            'user_id' => $userId,
            'user_found' => true,
            'password_verified' => true,
            'user_blocked' => false,
            'remember_requested' => $rememberMe,
            'context' => $this->sanitizeLoginContext($context),
        ]);
        $this->signIn($userId, $rememberMe);
        return self::LOGIN_SUCCESS;
    }

    public function logout(?string $rememberToken = null): void
    {
        if (is_string($rememberToken) && $rememberToken !== '') {
            $this->rememberTokens->deleteByHash($this->hashRememberToken($rememberToken));
        }

        $this->clearRememberCookie();
        $this->clearPendingLogin();
        $this->session->invalidate();
    }

    public function completePendingLoginWithTotp(string $code): bool
    {
        $user = $this->pendingUser();
        if ($user === null || (int) ($user['totp_enabled'] ?? 0) !== 1) {
            return false;
        }

        $secret = (string) ($user['totp_secret'] ?? '');
        if ($secret === '') {
            return false;
        }

        if (!$this->buildTotp()->verifyCode($secret, trim($code), 1)) {
            return false;
        }

        $this->completePendingLogin((int) $user['id']);
        return true;
    }

    public function completePendingLoginWithRecoveryCode(string $code): bool
    {
        $user = $this->pendingUser();
        if ($user === null) {
            return false;
        }

        $normalized = strtoupper(str_replace([' ', '-'], '', trim($code)));
        if ($normalized === '') {
            return false;
        }

        $hash = hash('sha256', $normalized);
        $ok = $this->recoveryCodes->consumeCode((int) $user['id'], $hash);
        if (!$ok) {
            return false;
        }

        $this->completePendingLogin((int) $user['id']);
        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingUser(): ?array
    {
        $pendingUserId = $this->session->get(self::SESSION_PENDING_USER_ID);
        if (is_string($pendingUserId) && ctype_digit($pendingUserId)) {
            $pendingUserId = (int) $pendingUserId;
        }

        if (!is_int($pendingUserId) || $pendingUserId <= 0) {
            return null;
        }

        return $this->users->findById($pendingUserId);
    }

    public function hasPendingLogin(): bool
    {
        return $this->pendingUser() !== null;
    }

    public function clearPendingLogin(): void
    {
        $this->session->remove(self::SESSION_PENDING_USER_ID);
        $this->session->remove(self::SESSION_PENDING_REMEMBER);
        $this->session->remove(self::SESSION_WEBAUTHN_EXPECTED_USER_ID);
        $this->session->remove(self::SESSION_WEBAUTHN_CHALLENGE);
        $this->session->remove(self::SESSION_WEBAUTHN_MODE);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        $userId = $this->session->get(self::SESSION_USER_ID);
        if (is_string($userId) && ctype_digit($userId)) {
            $userId = (int) $userId;
        }

        if (!is_int($userId)) {
            return null;
        }
        $user = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }

        if ((int) ($user['is_blocked'] ?? 0) === 1) {
            $this->session->remove(self::SESSION_USER_ID);
            return null;
        }

        return $user;
    }

    public function isAuthenticated(): bool
    {
        return $this->currentUser() !== null;
    }

    public function isAdmin(): bool
    {
        $user = $this->currentUser();
        if ($user === null) {
            return false;
        }

        return $this->users->hasRole((int) $user['id'], 'admin');
    }

    /**
     * Liefert die gültige IANA-Zeitzone eines Benutzers oder den Fallback UTC.
     *
     * @param array<string, mixed>|null $user
     */
    public function resolveUserTimezoneName(?array $user = null): string
    {
        $user ??= $this->currentUser();
        $candidate = trim((string) ($user['timezone'] ?? ''));
        if ($candidate !== '' && in_array($candidate, DateTimeZone::listIdentifiers(), true)) {
            return $candidate;
        }

        return 'UTC';
    }

    /**
     * Liefert ein DateTimeZone-Objekt für den Benutzer mit sicherem UTC-Fallback.
     *
     * @param array<string, mixed>|null $user
     */
    public function resolveUserTimezone(?array $user = null): DateTimeZone
    {
        try {
            return new DateTimeZone($this->resolveUserTimezoneName($user));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    public function enforceSessionLifetime(): void
    {
        $idle = $this->getIntConfig('session_idle_timeout', 1800);
        $absolute = $this->getIntConfig('session_absolute_timeout', 28800);
        $this->session->enforceLifetime($idle, $absolute);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function tryRememberLogin(?string $rememberToken, array $context = []): bool
    {
        if (!is_string($rememberToken) || $rememberToken === '') {
            return false;
        }

        $tokenHash = $this->hashRememberToken($rememberToken);
        $tokenRow = $this->rememberTokens->findValidByHash($tokenHash);
        if ($tokenRow === null) {
            $this->logAuthEvent('auth_remember_attempt', [
                'status' => 'failed',
                'reason' => 'remember_token_not_found_or_expired',
                'user_found' => false,
                'token_present' => true,
                'context' => $this->sanitizeLoginContext($context),
            ]);
            $this->clearRememberCookie();
            return false;
        }

        $userId = (int) ($tokenRow['user_id'] ?? 0);
        $user = $this->users->findById($userId);
        if ($userId <= 0 || $user === null || (int) ($user['is_blocked'] ?? 0) === 1) {
            $this->logAuthEvent('auth_remember_attempt', [
                'status' => 'failed',
                'reason' => $user === null ? 'remember_user_not_found' : 'remember_user_blocked',
                'user_id' => $userId,
                'user_found' => $user !== null,
                'user_blocked' => is_array($user) && (int) ($user['is_blocked'] ?? 0) === 1,
                'token_present' => true,
                'context' => $this->sanitizeLoginContext($context),
            ]);
            $this->rememberTokens->deleteByHash($tokenHash);
            $this->clearRememberCookie();
            return false;
        }

        $this->rememberTokens->deleteByHash($tokenHash);
        $this->signIn($userId, true);
        $this->logAuthEvent('auth_remember_attempt', [
            'status' => 'success',
            'reason' => 'remember_login_success',
            'user_id' => $userId,
            'user_found' => true,
            'user_blocked' => false,
            'token_present' => true,
            'token_rotated' => true,
            'context' => $this->sanitizeLoginContext($context),
        ]);
        return true;
    }

    public function rememberCookieName(): string
    {
        $cookie = (string) ($this->config['remember_cookie_name'] ?? self::DEFAULT_REMEMBER_COOKIE);
        return $cookie !== '' ? $cookie : self::DEFAULT_REMEMBER_COOKIE;
    }

    /**
     * @return array{secret:string,qr_data_uri:string,otpauth_url:string}
     */
    public function startTotpSetup(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            $this->session->remove(self::SESSION_TOTP_SETUP . '_' . $userId);
            throw new RuntimeException('TOTP ist bereits aktiv.');
        }

        $tfa = $this->buildTotp();
        $secret = $tfa->createSecret();
        $label = (string) ($user['email'] ?? ('user-' . $userId));
        $qrText = $tfa->getQRText($label, $secret);

        $this->session->set(self::SESSION_TOTP_SETUP . '_' . $userId, $secret);

        return [
            'secret' => $secret,
            'qr_data_uri' => $this->renderTotpQrDataUri($qrText),
            'otpauth_url' => $qrText,
        ];
    }

    /**
     * @return array{secret:string,qr_data_uri:string,otpauth_url:string}|null
     */
    public function pendingTotpSetup(int $userId): ?array
    {
        $secret = $this->session->get(self::SESSION_TOTP_SETUP . '_' . $userId);
        if (!is_string($secret) || $secret === '') {
            return null;
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }
        if ((int) ($user['totp_enabled'] ?? 0) === 1) {
            $this->session->remove(self::SESSION_TOTP_SETUP . '_' . $userId);
            return null;
        }

        $tfa = $this->buildTotp();
        $label = (string) ($user['email'] ?? ('user-' . $userId));

        return [
            'secret' => $secret,
            'qr_data_uri' => $this->renderTotpQrDataUri($tfa->getQRText($label, $secret)),
            'otpauth_url' => $tfa->getQRText($label, $secret),
        ];
    }

    /**
     * @return array{recovery_codes_created:bool,recovery_codes:array<int, string>}|null
     */
    public function confirmTotpSetup(int $userId, string $code): ?array
    {
        $secret = $this->session->get(self::SESSION_TOTP_SETUP . '_' . $userId);
        if (!is_string($secret) || $secret === '') {
            return null;
        }

        if (!$this->buildTotp()->verifyCode($secret, trim($code), 1)) {
            return null;
        }

        $hasActiveRecoveryCodes = $this->recoveryCodes->activeCount($userId) > 0;
        $this->users->setTotp($userId, $secret, true);
        $this->session->remove(self::SESSION_TOTP_SETUP . '_' . $userId);

        if ($hasActiveRecoveryCodes) {
            return [
                'recovery_codes_created' => false,
                'recovery_codes' => [],
            ];
        }

        return [
            'recovery_codes_created' => true,
            'recovery_codes' => $this->regenerateRecoveryCodes($userId),
        ];
    }

    public function disableTotp(int $userId): void
    {
        $this->users->setTotp($userId, null, false);
        $this->session->remove(self::SESSION_TOTP_SETUP . '_' . $userId);
    }

    /**
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(int $userId, int $count = 8): array
    {
        $plainCodes = [];
        $hashes = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(4)));
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $plainCodes[] = $code;
            $hashes[] = hash('sha256', str_replace('-', '', $code));
        }

        $this->recoveryCodes->replaceForUser($userId, $hashes);
        $this->session->set(self::SESSION_RECOVERY_CODES_PLAIN . '_' . $userId, $plainCodes);

        return $plainCodes;
    }

    /**
     * @return array<int, string>
     */
    public function pullGeneratedRecoveryCodes(int $userId): array
    {
        $key = self::SESSION_RECOVERY_CODES_PLAIN . '_' . $userId;
        $codes = $this->session->get($key, []);
        $this->session->remove($key);

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    public function recoveryCodeCount(int $userId): int
    {
        return $this->recoveryCodes->activeCount($userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function beginWebAuthnRegistration(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Benutzer ungültig.');
        }

        $excludeIds = [];
        foreach ($this->webauthnCredentials->listForUser($userId) as $credential) {
            $excludeIds[] = $this->decodeBase64Url((string) $credential['credential_id']);
        }

        $webauthn = $this->createWebAuthn();
        $createArgs = $webauthn->getCreateArgs(
            (string) $userId,
            (string) ($user['email'] ?? ('user-' . $userId)),
            (string) ($user['name'] ?? ('User ' . $userId)),
            240,
            'required',
            true,
            null,
            $excludeIds,
        );

        $challenge = $webauthn->getChallenge();
        $this->session->set(self::SESSION_WEBAUTHN_CHALLENGE, base64_encode($challenge->getBinaryString()));
        $this->session->set(self::SESSION_WEBAUTHN_MODE, 'register');
        $this->session->set(self::SESSION_WEBAUTHN_EXPECTED_USER_ID, $userId);

        return json_decode(json_encode($createArgs), true, flags: JSON_THROW_ON_ERROR);
    }

    public function finishWebAuthnRegistration(array $user, array $payload, string $label): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        $mode = $this->session->get(self::SESSION_WEBAUTHN_MODE);
        $expectedUser = $this->session->get(self::SESSION_WEBAUTHN_EXPECTED_USER_ID);
        $challenge64 = $this->session->get(self::SESSION_WEBAUTHN_CHALLENGE);
        if ($mode !== 'register' || !is_int($expectedUser) || $expectedUser !== $userId || !is_string($challenge64)) {
            return false;
        }

        try {
            $clientDataJSON = $this->decodeBase64Any((string) ($payload['clientDataJSON'] ?? ''));
            $attestationObject = $this->decodeBase64Any((string) ($payload['attestationObject'] ?? ''));
            $challenge = base64_decode($challenge64, true);
            if ($clientDataJSON === null || $attestationObject === null || $challenge === false) {
                return false;
            }

            $webauthn = $this->createWebAuthn();
            $data = $webauthn->processCreate($clientDataJSON, $attestationObject, $challenge, true, true, false);
        } catch (Throwable) {
            return false;
        }

        $credentialId = $this->encodeBase64Url((string) $data->credentialId);
        $transports = $payload['transports'] ?? null;
        $transportsJson = is_array($transports) ? json_encode($transports) : null;
        $safeLabel = trim($label) !== '' ? trim($label) : 'Passkey ' . date('Y-m-d H:i');

        $this->webauthnCredentials->create(
            $userId,
            $safeLabel,
            $credentialId,
            (string) $data->credentialPublicKey,
            is_int($data->signatureCounter) ? $data->signatureCounter : null,
            is_string($transportsJson) ? $transportsJson : null,
        );
        $this->users->setWebAuthnEnabled($userId, true);

        $this->session->remove(self::SESSION_WEBAUTHN_MODE);
        $this->session->remove(self::SESSION_WEBAUTHN_EXPECTED_USER_ID);
        $this->session->remove(self::SESSION_WEBAUTHN_CHALLENGE);
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function beginWebAuthnLogin(?int $userId = null): array
    {
        $credentialIds = [];

        if ($userId !== null) {
            foreach ($this->webauthnCredentials->listForUser($userId) as $credential) {
                $credentialIds[] = $this->decodeBase64Url((string) $credential['credential_id']);
            }
        }

        $webauthn = $this->createWebAuthn();
        $args = $webauthn->getGetArgs(
            $credentialIds,
            240,
            true,
            true,
            true,
            true,
            true,
            true,
        );

        $challenge = $webauthn->getChallenge();
        $this->session->set(self::SESSION_WEBAUTHN_CHALLENGE, base64_encode($challenge->getBinaryString()));
        $this->session->set(self::SESSION_WEBAUTHN_MODE, 'login');
        $this->session->set(self::SESSION_WEBAUTHN_EXPECTED_USER_ID, $userId);

        return json_decode(json_encode($args), true, flags: JSON_THROW_ON_ERROR);
    }

    public function finishWebAuthnLogin(array $payload, bool $rememberMe = false): bool
    {
        $mode = $this->session->get(self::SESSION_WEBAUTHN_MODE);
        $expectedUserId = $this->session->get(self::SESSION_WEBAUTHN_EXPECTED_USER_ID);
        $challenge64 = $this->session->get(self::SESSION_WEBAUTHN_CHALLENGE);
        if ($mode !== 'login' || !is_string($challenge64)) {
            return false;
        }

        $idRaw = $this->decodeBase64Any((string) ($payload['id'] ?? ''));
        $clientDataJSON = $this->decodeBase64Any((string) ($payload['clientDataJSON'] ?? ''));
        $authenticatorData = $this->decodeBase64Any((string) ($payload['authenticatorData'] ?? ''));
        $signature = $this->decodeBase64Any((string) ($payload['signature'] ?? ''));
        $challenge = base64_decode($challenge64, true);

        if ($idRaw === null || $clientDataJSON === null || $authenticatorData === null || $signature === null || $challenge === false) {
            return false;
        }

        $credentialId = $this->encodeBase64Url($idRaw);
        $credential = $this->webauthnCredentials->findByCredentialId($credentialId);
        if ($credential === null) {
            return false;
        }

        $credentialUserId = (int) ($credential['user_id'] ?? 0);
        if (is_int($expectedUserId) && $expectedUserId > 0 && $expectedUserId !== $credentialUserId) {
            return false;
        }

        try {
            $webauthn = $this->createWebAuthn();
            $webauthn->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                (string) $credential['public_key'],
                $challenge,
                isset($credential['sign_count']) ? (int) $credential['sign_count'] : null,
                true,
                true,
            );
        } catch (Throwable) {
            return false;
        }

        $this->webauthnCredentials->updateSignCountAndLastUsed(
            (int) $credential['id'],
            $webauthn->getSignatureCounter(),
        );

        if (is_int($expectedUserId) && $expectedUserId > 0) {
            $pendingRemember = $this->session->get(self::SESSION_PENDING_REMEMBER) === true;
            $this->completePendingLogin($credentialUserId);
            $this->logAuthEvent('auth_webauthn_login', [
                'status' => 'success',
                'reason' => 'two_factor_success',
                'user_id' => $credentialUserId,
                'remember_requested' => $pendingRemember,
            ]);
        } else {
            $this->signIn($credentialUserId, $rememberMe);
            $this->logAuthEvent('auth_webauthn_login', [
                'status' => 'success',
                'reason' => 'passkey_login_success',
                'user_id' => $credentialUserId,
                'remember_requested' => $rememberMe,
            ]);
        }

        $this->session->remove(self::SESSION_WEBAUTHN_MODE);
        $this->session->remove(self::SESSION_WEBAUTHN_EXPECTED_USER_ID);
        $this->session->remove(self::SESSION_WEBAUTHN_CHALLENGE);
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listWebAuthnCredentials(int $userId): array
    {
        return $this->webauthnCredentials->listForUser($userId);
    }

    public function removeWebAuthnCredential(int $userId, int $credentialId): void
    {
        $this->webauthnCredentials->deleteForUser($userId, $credentialId);
        $this->users->setWebAuthnEnabled($userId, $this->webauthnCredentials->countForUser($userId) > 0);
    }

    private function requiresTwoFactor(array $user): bool
    {
        return (int) ($user['totp_enabled'] ?? 0) === 1 || (int) ($user['webauthn_enabled'] ?? 0) === 1;
    }

    private function completePendingLogin(int $userId): void
    {
        $remember = $this->session->get(self::SESSION_PENDING_REMEMBER) === true;
        $this->clearPendingLogin();
        $this->signIn($userId, $remember);
    }

    private function signIn(int $userId, bool $rememberMe): void
    {
        $this->session->regenerateId();
        $this->csrfTokenManager->rotate();
        $this->session->set(self::SESSION_USER_ID, $userId);

        if ($rememberMe) {
            $this->issueRememberToken($userId);
        } else {
            $this->clearRememberCookie();
        }
    }

    private function issueRememberToken(int $userId): void
    {
        $this->rememberTokens->deleteExpired();

        $token = bin2hex(random_bytes(32));
        $tokenHash = $this->hashRememberToken($token);
        $lifetime = $this->getIntConfig('remember_token_lifetime', 1209600);
        $expiresAt = time() + max(60, $lifetime);

        $this->rememberTokens->insert($userId, $tokenHash, $expiresAt);
        $this->setRememberCookie($token, $expiresAt);
    }

    private function hashRememberToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function setRememberCookie(string $token, int $expiresAt): void
    {
        $isSecure = (bool) ($this->config['remember_cookie_secure'] ?? false);
        $sameSite = (string) ($this->config['remember_cookie_samesite'] ?? 'Lax');
        $sameSite = in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax';

        setcookie($this->rememberCookieName(), $token, [
            'expires' => $expiresAt,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    private function clearRememberCookie(): void
    {
        $sameSite = (string) ($this->config['remember_cookie_samesite'] ?? 'Lax');
        $sameSite = in_array($sameSite, ['Lax', 'Strict', 'None'], true) ? $sameSite : 'Lax';

        setcookie($this->rememberCookieName(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (bool) ($this->config['remember_cookie_secure'] ?? false),
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }

    private function getIntConfig(string $key, int $default): int
    {
        $value = $this->config[$key] ?? $default;
        if (!is_int($value)) {
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }

            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function logAuthEvent(string $eventName, array $event): void
    {
        $payload = [
            'timestamp' => date('c'),
            'event' => $eventName,
            'data' => $event,
        ];

        $line = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($line) || $line === '') {
            return;
        }

        if (!(new RotatingFileLogger(dirname(__DIR__, 3)))->write('auth-login', $payload)) {
            error_log('[auth-login] ' . $line);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeLoginContext(array $context): array
    {
        $allowedKeys = [
            'ip',
            'forwarded_for',
            'user_agent',
            'request_method',
            'request_path',
            'session_active',
            'csrf_check',
            'input_identifier_present',
            'input_password_present',
            'remember_cookie_present',
        ];

        $sanitized = [];
        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];
            if (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function buildTotp(): TwoFactorAuth
    {
        $issuer = (string) ($this->config['totp_issuer'] ?? 'Modulon');
        return new TwoFactorAuth(new GoogleChartsQrCodeProvider(), $issuer);
    }

    private function renderTotpQrDataUri(string $otpauthUrl): string
    {
        $rendered = (new QRCode())->render($otpauthUrl);

        if (str_starts_with($rendered, 'data:')) {
            return $rendered;
        }

        return 'data:image/svg+xml;base64,' . base64_encode($rendered);
    }

    private function createWebAuthn(): WebAuthn
    {
        $rpName = (string) ($this->config['webauthn_rp_name'] ?? 'Modulon');
        $rpId = strtolower(trim((string) ($this->config['webauthn_rp_id'] ?? '')));
        if ($rpId === '') {
            if (($this->config['webauthn_require_explicit_rp_id'] ?? false) === true) {
                throw new RuntimeException('WEBAUTHN_RP_ID muss in Produktion explizit konfiguriert werden.');
            }
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $rpId = strtolower(trim(explode(':', $host)[0]));
        }

        if (!$this->isValidWebAuthnRpId($rpId)) {
            throw new RuntimeException('WEBAUTHN_RP_ID ist ungültig.');
        }

        return new WebAuthn($rpName, $rpId, ['none'], true);
    }

    private function isValidWebAuthnRpId(string $rpId): bool
    {
        if ($rpId === 'localhost' || filter_var($rpId, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $rpId) === 1;
    }

    private function decodeBase64Any(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $std = base64_decode($value, true);
        if (is_string($std)) {
            return $std;
        }

        return $this->decodeBase64Url($value);
    }

    private function encodeBase64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function decodeBase64Url(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);
        if (!is_string($decoded)) {
            throw new RuntimeException('Base64URL konnte nicht dekodiert werden.');
        }

        return $decoded;
    }
}
