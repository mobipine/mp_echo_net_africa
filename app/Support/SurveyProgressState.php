<?php

namespace App\Support;

final class SurveyProgressState
{
    public const OPEN_STATUSES = [
        'ACTIVE',
        'UPDATING_DETAILS',
        'PENDING',
    ];

    public static function isOpen(?string $status, $completedAt): bool
    {
        return $completedAt === null && in_array($status ?? 'ACTIVE', self::OPEN_STATUSES, true);
    }

    public static function guardFor(?string $status, $completedAt): ?string
    {
        return self::isOpen($status, $completedAt) ? 'open' : null;
    }
}
