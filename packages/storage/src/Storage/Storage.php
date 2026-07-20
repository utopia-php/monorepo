<?php

declare(strict_types=1);

namespace Utopia\Storage;

/**
 * @see \Utopia\Tests\Storage\StorageTest
 */
final class Storage
{
    /**
     * Human readable data size format from bytes input.
     *
     * Based on: https://stackoverflow.com/a/38659168/2299554
     *
     * @param  int  $decimals
     * @param  string  $system
     */
    public static function human(int $bytes, $decimals = 2, $system = 'metric'): string
    {
        $mod = ($system === 'binary') ? 1024 : 1000;

        $units = [
            'binary' => [
                'B',
                'KiB',
                'MiB',
                'GiB',
                'TiB',
                'PiB',
                'EiB',
                'ZiB',
                'YiB',
            ],
            'metric' => [
                'B',
                'kB',
                'MB',
                'GB',
                'TB',
                'PB',
                'EB',
                'ZB',
                'YB',
            ],
        ];

        $factor = (int) floor((\strlen((string) $bytes) - 1) / 3);

        return \sprintf("%.{$decimals}f%s", $bytes / $mod ** $factor, $units[$system][$factor]);
    }
}
