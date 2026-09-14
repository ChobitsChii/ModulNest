<?php

declare(strict_types=1);

namespace Modulon\Core;

final class UserAvatarHelper
{
    public static function initial(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return 'U';
        }
        return mb_strtoupper(mb_substr($trimmed, 0, 1));
    }

    public static function gradient(string $name): string
    {
        $gradients = [
            'linear-gradient(135deg, #6366f1 0%, #a855f7 100%)',
            'linear-gradient(135deg, #3b82f6 0%, #06b6d4 100%)',
            'linear-gradient(135deg, #10b981 0%, #14b8a6 100%)',
            'linear-gradient(135deg, #f59e0b 0%, #f97316 100%)',
            'linear-gradient(135deg, #ec4899 0%, #f43f5e 100%)',
            'linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%)',
        ];
        $hash = crc32(strtolower(trim($name)));
        $index = abs($hash) % count($gradients);
        return $gradients[$index];
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public static function render(?array $user, int $size = 32, string $extraClass = ''): string
    {
        $name = (string) ($user['name'] ?? $user['user_name'] ?? 'User');
        $initial = self::initial($name);
        $gradient = self::gradient($name);
        $avatarPath = (string) ($user['avatar_path'] ?? '');
        $userId = (int) ($user['id'] ?? 0);

        if ($avatarPath !== '' && $userId > 0) {
            $url = '/avatar?u=' . $userId . '&v=' . substr(md5($avatarPath), 0, 6);
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="rounded-circle app-user-avatar ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') . '" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:cover;" />';
        }

        $fontSize = max(10, (int) round($size * 0.44));
        return '<div class="app-user-avatar-initial rounded-circle d-inline-flex align-items-center justify-content-center text-white fw-semibold ' . htmlspecialchars($extraClass, ENT_QUOTES, 'UTF-8') . '" style="width:' . $size . 'px;height:' . $size . 'px;background:' . $gradient . ';font-size:' . $fontSize . 'px;line-height:1;user-select:none;">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}
