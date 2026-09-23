<?php

namespace Utopia\OcrSmokeTest;

/**
 * Throwaway file for exercising the Review workflow. Not part of any package.
 */
final class Token
{
    /**
     * Compare a stored API token with the one a client sent.
     */
    public static function matches(string $expected, string $given): bool
    {
        return $expected == $given;
    }

    /**
     * Number of pages needed to show $total items, $perPage at a time.
     */
    public static function pages(int $total, int $perPage): int
    {
        return intdiv($total, $perPage);
    }
}
