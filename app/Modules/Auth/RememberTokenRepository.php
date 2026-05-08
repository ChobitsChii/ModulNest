<?php

declare(strict_types=1);

namespace Modulon\Modules\Auth;

use PDO;

final class RememberTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findValidByHash(string $tokenHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, token_hash, expires_at
             FROM remember_tokens
             WHERE token_hash = :token_hash
               AND expires_at > NOW()
             LIMIT 1'
        );
        $statement->execute(['token_hash' => $tokenHash]);

        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    public function insert(int $userId, string $tokenHash, int $expiresAtUnix): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO remember_tokens (user_id, token_hash, expires_at)
             VALUES (:user_id, :token_hash, :expires_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiresAtUnix),
        ]);
    }

    public function deleteByHash(string $tokenHash): void
    {
        $statement = $this->pdo->prepare('DELETE FROM remember_tokens WHERE token_hash = :token_hash');
        $statement->execute(['token_hash' => $tokenHash]);
    }

    public function deleteExpired(): void
    {
        $statement = $this->pdo->prepare('DELETE FROM remember_tokens WHERE expires_at <= NOW()');
        $statement->execute();
    }
}
