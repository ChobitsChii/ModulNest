<?php

declare(strict_types=1);

namespace Modulon\Modules\Auth;

use PDO;

final class RecoveryCodeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function replaceForUser(int $userId, array $hashes): void
    {
        $deleteStatement = $this->pdo->prepare('DELETE FROM recovery_codes WHERE user_id = :user_id');
        $deleteStatement->execute(['user_id' => $userId]);

        $insertStatement = $this->pdo->prepare(
            'INSERT INTO recovery_codes (user_id, code_hash) VALUES (:user_id, :code_hash)'
        );

        foreach ($hashes as $hash) {
            $insertStatement->execute([
                'user_id' => $userId,
                'code_hash' => $hash,
            ]);
        }
    }

    public function consumeCode(int $userId, string $codeHash): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE recovery_codes
             SET used_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id
               AND code_hash = :code_hash
               AND used_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'user_id' => $userId,
            'code_hash' => $codeHash,
        ]);

        return $statement->rowCount() > 0;
    }

    public function activeCount(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM recovery_codes WHERE user_id = :user_id AND used_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }
}
