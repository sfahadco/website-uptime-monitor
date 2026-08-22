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

    /**
     * True only when a site has just gone down, which is when we mail. UNKNOWN
     * counts as "not down", so a site that fails its very first check alerts.
     */
    public function isNewOutageFrom(?self $previous): bool
    {
        return $this === self::DOWN && $previous !== self::DOWN;
    }
}
