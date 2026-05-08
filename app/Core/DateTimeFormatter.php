<?php

declare(strict_types=1);

namespace Modulon\Core;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class DateTimeFormatter
{
    public static function formatUserDateTime(mixed $value, DateTimeZone|string|null $timezone = null): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($text))
                ->setTimezone(self::resolveTimezone($timezone))
                ->format('d.m.Y H:i:s');
        } catch (Throwable) {
            return $text;
        }
    }

    public static function resolveTimezone(DateTimeZone|string|null $timezone = null): DateTimeZone
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        $candidates = [
            is_string($timezone) ? trim($timezone) : '',
            (string) Env::get('APP_TIMEZONE', ''),
            date_default_timezone_get(),
            'Europe/Berlin',
            'UTC',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '' || !in_array($candidate, DateTimeZone::listIdentifiers(), true)) {
                continue;
            }

            try {
                return new DateTimeZone($candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return new DateTimeZone('UTC');
    }
}
