<?php

declare(strict_types=1);

namespace Modulon\Modules\ExampleNotes;

use Modulon\Core\RotatingFileLogger;
use Modulon\Modules\Admin\AppSettingRepository;

final class ExampleNotesService
{
    private const HINT_KEY = 'example_notes.hint';

    public function __construct(private readonly ExampleNotesRepository $notes, private readonly AppSettingRepository $settings, private readonly RotatingFileLogger $logger) {}
    public function notesForUser(int $userId): array { return $this->notes->listForUser($userId); }
    public function title(): string { return 'Example Notes'; }
    public function hint(): string { return $this->settings->get(self::HINT_KEY) ?? 'Dieses Modul demonstriert den nativen Modulvertrag.'; }

    public function create(int $userId, string $title): int
    {
        $title = trim($title);
        if ($userId <= 0 || $title === '' || mb_strlen($title) > 160) { throw new \InvalidArgumentException('Bitte eine Notiz mit höchstens 160 Zeichen angeben.'); }
        $id = $this->notes->create($userId, $title);
        $this->logger->write('modulon', ['event' => 'example_notes_created', 'note_id' => $id, 'user_id' => $userId]);
        return $id;
    }

    public function toggle(int $userId, int $noteId): bool
    {
        if ($userId <= 0 || $noteId <= 0) { return false; }
        return $this->notes->toggleForUser($noteId, $userId);
    }

    public function saveHint(string $hint): void
    {
        $hint = trim($hint);
        if (mb_strlen($hint) > 240) { throw new \InvalidArgumentException('Der Hinweis darf höchstens 240 Zeichen haben.'); }
        $this->settings->set(self::HINT_KEY, $hint);
        $this->logger->write('modulon', ['event' => 'example_notes_setting_saved', 'setting' => self::HINT_KEY]);
    }
}
