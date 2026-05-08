<?php

declare(strict_types=1);

namespace Modulon\Modules\Auth;

use PDO;

final class WebAuthnCredentialRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, label, credential_id, public_key, sign_count, transports, created_at, last_used_at
             FROM webauthn_credentials
             WHERE user_id = :user_id
             ORDER BY created_at DESC'
        );
        $statement->execute(['user_id' => $userId]);

        $rows = $statement->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, label, credential_id, public_key, sign_count
             FROM webauthn_credentials
             WHERE credential_id = :credential_id
             LIMIT 1'
        );
        $statement->execute(['credential_id' => $credentialId]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function create(
        int $userId,
        string $label,
        string $credentialId,
        string $publicKey,
        ?int $signCount,
        ?string $transports,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO webauthn_credentials (user_id, label, credential_id, public_key, sign_count, transports)
             VALUES (:user_id, :label, :credential_id, :public_key, :sign_count, :transports)'
        );
        $statement->execute([
            'user_id' => $userId,
            'label' => $label,
            'credential_id' => $credentialId,
            'public_key' => $publicKey,
            'sign_count' => $signCount,
            'transports' => $transports,
        ]);
    }

    public function updateSignCountAndLastUsed(int $id, ?int $signCount): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE webauthn_credentials
             SET sign_count = :sign_count,
                 last_used_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $id,
            'sign_count' => $signCount,
        ]);
    }

    public function deleteForUser(int $userId, int $credentialId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM webauthn_credentials WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'id' => $credentialId,
            'user_id' => $userId,
        ]);
    }

    public function countForUser(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }
}
