<?php

namespace App\Enums;

enum WebsiteStatusEnum: string
{
    case UNKNOWN = 'unknown';
    case UP = 'up';
    case DOWN = 'down';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isNewOutageFrom(?self $previous): bool
    {
        return $this === self::DOWN && $previous !== self::DOWN;
    }
}
