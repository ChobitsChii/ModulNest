<?php

declare(strict_types=1);

namespace Modulon\Modules\Admin;

use PDO;

final class AppSettingRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM app_settings WHERE `key` = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO app_settings (`key`, value)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'key' => $key,
            'value' => $value,
        ]);
    }

    public function getBool(string $key, bool $default): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }
}
