<?php

namespace Utopia\OcrSmokeTest;

/**
 * Throwaway file for exercising the Review workflow. Not part of any package.
 */
final class Token
{
    /**
     * Compare a stored API token with the one a client sent, in constant time.
     * An empty stored token never matches.
     */
    public static function matches(string $expected, string $given): bool
    {
        return $expected !== '' && \hash_equals($expected, $given);
    }

    /**
     * Number of pages needed to show $total items, $perPage at a time.
     *
     * @throws \InvalidArgumentException when $total is negative or $perPage is not positive
     */
    public static function pages(int $total, int $perPage): int
    {
        if ($total < 0 || $perPage < 1) {
            throw new \InvalidArgumentException('Total must not be negative and perPage must be positive.');
        }

        return \intdiv($total, $perPage) + ($total % $perPage === 0 ? 0 : 1);
    }
}
