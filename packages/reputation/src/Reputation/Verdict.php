<?php

declare(strict_types=1);

namespace Utopia\Reputation;

final class Verdict
{
    public const string CLEAN = 'clean';
    public const string LOW = 'low';
    public const string SUSPICIOUS = 'suspicious';
    public const string BLOCK = 'block';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CLEAN,
            self::LOW,
            self::SUSPICIOUS,
            self::BLOCK,
        ];
    }
}
