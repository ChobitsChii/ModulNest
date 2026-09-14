<?php

declare(strict_types=1);

namespace Modulon\Modules\Auth;

use Modulon\Core\Modules\ModuleManagementColumns;
use PDO;
use Throwable;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Sucht einen Benutzer per E-Mail.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, username, email, timezone, theme_mode, theme_switcher_visible, admin_nav_layout, favorite_modules, header_modules, avatar_path, dashboard_auto_refresh_enabled, dashboard_auto_refresh_interval_minutes, password_hash, is_blocked, totp_secret, totp_enabled, webauthn_enabled
             FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /**
     * Sucht einen Benutzer per Benutzername.
     *
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, username, email, timezone, theme_mode, theme_switcher_visible, admin_nav_layout, favorite_modules, header_modules, avatar_path, dashboard_auto_refresh_enabled, dashboard_auto_refresh_interval_minutes, password_hash, is_blocked, totp_secret, totp_enabled, webauthn_enabled
             FROM users WHERE username = :username LIMIT 1'
        );
        $statement->execute(['username' => $username]);

        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    /**
     * Sucht einen Benutzer per ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, username, email, timezone, theme_mode, theme_switcher_visible, admin_nav_layout, favorite_modules, header_modules, avatar_path, dashboard_auto_refresh_enabled, dashboard_auto_refresh_interval_minutes, is_blocked, totp_secret, totp_enabled, webauthn_enabled
             FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);

        $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }

    public function createUser(string $name, string $email, string $passwordHash, ?string $username = null): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, username, email, password_hash) VALUES (:name, :username, :email, :password_hash)'
        );
        $statement->execute([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function hasRole(int $userId, string $roleName): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id
             FROM roles r
             INNER JOIN user_role ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id AND r.name = :role_name
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'role_name' => $roleName,
        ]);

        return $statement->fetch() !== false;
    }

    /**
     * Weist eine Rolle nach Name zu, falls diese existiert und noch nicht zugewiesen ist.
     */
    public function attachRoleByName(int $userId, string $roleName): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
        $statement->execute(['name' => $roleName]);

        $role = $statement->fetch();
        if (!is_array($role) || !isset($role['id'])) {
            return;
        }

        $linkStatement = $this->pdo->prepare(
            'INSERT IGNORE INTO user_role (user_id, role_id) VALUES (:user_id, :role_id)'
        );
        $linkStatement->execute([
            'user_id' => $userId,
            'role_id' => (int) $role['id'],
        ]);
    }

    public function setTotp(int $userId, ?string $secret, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET totp_secret = :totp_secret,
                 totp_enabled = :totp_enabled,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'totp_secret' => $secret,
            'totp_enabled' => $enabled ? 1 : 0,
        ]);
    }

    public function setWebAuthnEnabled(int $userId, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET webauthn_enabled = :webauthn_enabled,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'webauthn_enabled' => $enabled ? 1 : 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        $statement = $this->pdo->query(
            "SELECT u.id, u.name, u.username, u.email, u.is_blocked, u.created_at,
                    CASE
                        WHEN EXISTS (
                            SELECT 1
                            FROM user_role ur
                            INNER JOIN roles r ON r.id = ur.role_id
                            WHERE ur.user_id = u.id AND r.name = 'admin'
                        ) THEN 'admin'
                        ELSE 'user'
                    END AS role_name
             FROM users u
             ORDER BY u.created_at DESC, u.id DESC"
        );

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    public function setBlocked(int $userId, bool $blocked): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET is_blocked = :is_blocked,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'is_blocked' => $blocked ? 1 : 0,
        ]);
    }

    public function deleteUser(int $userId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    public function setPrimaryRole(int $userId, string $roleName): void
    {
        $cleanup = $this->pdo->prepare(
            "DELETE ur
             FROM user_role ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.name IN ('user', 'admin')"
        );
        $cleanup->execute(['user_id' => $userId]);

        $this->attachRoleByName($userId, $roleName === 'admin' ? 'admin' : 'user');
    }

    public function countAdmins(): int
    {
        $statement = $this->pdo->query(
            "SELECT COUNT(*) AS cnt
             FROM user_role ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.name = 'admin'"
        );

        $row = $statement->fetch();
        return is_array($row) ? (int) ($row['cnt'] ?? 0) : 0;
    }

    public function emailExists(string $email, int $excludeUserId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM users WHERE email = :email AND id <> :exclude_id LIMIT 1'
        );
        $statement->execute([
            'email' => $email,
            'exclude_id' => $excludeUserId,
        ]);

        return $statement->fetch() !== false;
    }

    public function usernameExists(string $username, int $excludeUserId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM users WHERE username = :username AND id <> :exclude_id LIMIT 1'
        );
        $statement->execute([
            'username' => $username,
            'exclude_id' => $excludeUserId,
        ]);

        return $statement->fetch() !== false;
    }

    public function updateProfile(int $userId, string $name, ?string $username, string $email): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET name = :name,
                 username = :username,
                 email = :email,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'name' => $name,
            'username' => $username,
            'email' => $email,
        ]);
    }

    public function updateTimezone(int $userId, string $timezone): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET timezone = :timezone,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'timezone' => $timezone,
        ]);
    }

    public function updateSettings(
        int $userId,
        string $timezone,
        bool $dashboardAutoRefreshEnabled,
        int $dashboardAutoRefreshIntervalMinutes,
        string $themeMode,
        bool $themeSwitcherVisible,
        string $adminNavLayout = 'tabs',
    ): void {
        $adminNavLayout = in_array($adminNavLayout, ['tabs', 'sidebar'], true) ? $adminNavLayout : 'tabs';
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET timezone = :timezone,
                 dashboard_auto_refresh_enabled = :dashboard_auto_refresh_enabled,
                 dashboard_auto_refresh_interval_minutes = :dashboard_auto_refresh_interval_minutes,
                 theme_mode = :theme_mode,
                 theme_switcher_visible = :theme_switcher_visible,
                 admin_nav_layout = :admin_nav_layout,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'timezone' => $timezone,
            'dashboard_auto_refresh_enabled' => $dashboardAutoRefreshEnabled ? 1 : 0,
            'dashboard_auto_refresh_interval_minutes' => $dashboardAutoRefreshIntervalMinutes,
            'theme_mode' => $themeMode,
            'theme_switcher_visible' => $themeSwitcherVisible ? 1 : 0,
            'admin_nav_layout' => $adminNavLayout,
        ]);
    }

    public function updateAdminNavLayout(int $userId, string $layout): void
    {
        $layout = in_array($layout, ['tabs', 'sidebar'], true) ? $layout : 'tabs';
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET admin_nav_layout = :admin_nav_layout,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId, 'admin_nav_layout' => $layout]);
    }

    public function updateThemeMode(int $userId, string $themeMode): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET theme_mode = :theme_mode,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId, 'theme_mode' => $themeMode]);
    }

    /** @return list<string> */
    public function moduleManagementColumns(int $userId): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT module_management_columns FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $value = $statement->fetchColumn();
            if (!is_string($value) || trim($value) === '') {
                return ModuleManagementColumns::DEFAULTS;
            }
            return ModuleManagementColumns::normalize(json_decode($value, true, 16, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return ModuleManagementColumns::DEFAULTS;
        }
    }

    /** @param list<string>|null $columns */
    public function updateModuleManagementColumns(int $userId, ?array $columns): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET module_management_columns = :columns, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'columns' => $columns === null ? null : json_encode(ModuleManagementColumns::normalize($columns), JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<string> */
    public function favoriteModules(int $userId): array
    {
        try {
            $statement = $this->pdo->prepare('SELECT favorite_modules FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $value = $statement->fetchColumn();
            if (!is_string($value) || trim($value) === '') {
                return [];
            }
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return [];
            }
            return array_values(array_filter(array_map('strval', $decoded), fn ($k) => trim($k) !== ''));
        } catch (Throwable) {
            return [];
        }
    }

    /** @param list<string> $favorites */
    public function updateFavorites(int $userId, array $favorites): void
    {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $favorites), fn ($k) => trim($k) !== '')));
        $statement = $this->pdo->prepare(
            'UPDATE users SET favorite_modules = :favorites, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'favorites' => json_encode($normalized, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return list<string>|null */
    public function headerModules(int $userId): ?array
    {
        try {
            $statement = $this->pdo->prepare('SELECT header_modules FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $value = $statement->fetchColumn();
            if (!is_string($value) || trim($value) === '') {
                return null;
            }
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                return null;
            }
            return array_values(array_filter(array_map('strval', $decoded), fn ($k) => trim($k) !== ''));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<string>|null $modules */
    public function updateHeaderModules(int $userId, ?array $modules): void
    {
        $value = null;
        if ($modules !== null) {
            $normalized = array_values(array_unique(array_filter(array_map('strval', $modules), fn ($k) => trim($k) !== '')));
            $value = json_encode($normalized, JSON_THROW_ON_ERROR);
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET header_modules = :modules, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'modules' => $value,
        ]);
    }

    public function updateAvatar(int $userId, ?string $avatarPath): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET avatar_path = :avatar_path, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'avatar_path' => $avatarPath !== null && trim($avatarPath) !== '' ? trim($avatarPath) : null,
        ]);
    }

    public function updateDashboardAutoRefreshSettings(int $userId, bool $enabled, int $intervalMinutes): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET dashboard_auto_refresh_enabled = :enabled,
                 dashboard_auto_refresh_interval_minutes = :interval_minutes,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'enabled' => $enabled ? 1 : 0,
            'interval_minutes' => $intervalMinutes,
        ]);
    }

    public function findPasswordHashById(int $userId): ?string
    {
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }

        $hash = $row['password_hash'] ?? null;
        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users
             SET password_hash = :password_hash,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
        ]);
    }

    public function updateUserByAdmin(
        int $userId,
        string $name,
        ?string $username,
        string $email,
        string $role,
        bool $blocked,
        ?string $passwordHash = null
    ): void {
        if ($passwordHash !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE users
                 SET name = :name,
                     username = :username,
                     email = :email,
                     is_blocked = :is_blocked,
                     password_hash = :password_hash,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $userId,
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'is_blocked' => $blocked ? 1 : 0,
                'password_hash' => $passwordHash,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE users
                 SET name = :name,
                     username = :username,
                     email = :email,
                     is_blocked = :is_blocked,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $userId,
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'is_blocked' => $blocked ? 1 : 0,
            ]);
        }

        $this->setPrimaryRole($userId, $role === 'admin' ? 'admin' : 'user');
    }
}
