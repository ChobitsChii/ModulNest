<?php

declare(strict_types=1);

namespace Modulon\Modules\ExampleNotes;

final class ExampleNotesRepository
{
    public function __construct(private readonly \PDO $pdo) {}
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT id, title, is_active, created_at FROM example_notes WHERE user_id = :user_id ORDER BY id DESC');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll() ?: [];
    }
    public function create(int $userId, string $title): int
    {
        $statement = $this->pdo->prepare('INSERT INTO example_notes (user_id, title, is_active) VALUES (:user_id, :title, 1)');
        $statement->execute(['user_id' => $userId, 'title' => $title]);
        return (int) $this->pdo->lastInsertId();
    }
    public function toggleForUser(int $noteId, int $userId): bool
    {
        $statement = $this->pdo->prepare('UPDATE example_notes SET is_active = 1 - is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $noteId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }
}
