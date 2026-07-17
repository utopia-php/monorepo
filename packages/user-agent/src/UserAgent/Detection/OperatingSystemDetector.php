<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Detection;

use Utopia\UserAgent\OperatingSystem;

final class OperatingSystemDetector
{
    /** @var array<string, string> */
    private const array WINDOWS_VERSIONS = [
        '10.0' => '10',
        '6.4' => '10',
        '6.3' => '8.1',
        '6.2' => '8',
        '6.1' => '7',
        '6.0' => 'Vista',
        '5.2' => 'XP',
        '5.1' => 'XP',
        '5.0' => '2000',
    ];

    public static function detect(string $userAgent): OperatingSystem
    {
        if ($userAgent === '') {
            return new OperatingSystem();
        }

        if (preg_match('/Windows Phone(?: OS)?[ \/]([0-9._]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('WPH', 'Windows Phone', self::version($matches[1]));
        }

        if (preg_match('/Windows NT[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
            $version = self::WINDOWS_VERSIONS[$matches[1]] ?? $matches[1];

            return new OperatingSystem('WIN', 'Windows', $version);
        }

        if (preg_match('/(?:CPU (?:iPhone )?OS|iPhone OS)[ \/]([0-9_]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('IOS', 'iOS', self::version($matches[1]));
        }

        if (preg_match('/Android(?: |\/)([0-9][0-9._-]*)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('AND', 'Android', self::version($matches[1]));
        }

        if (preg_match('/CrOS [^ )]+ ([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('COS', 'Chrome OS', self::version($matches[1]));
        }

        if (preg_match('/Mac OS X[ \/]([0-9_\.]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('MAC', 'Mac', self::displayVersion($matches[1]));
        }

        if (preg_match('/Tizen[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('TIZ', 'Tizen', self::version($matches[1]));
        }

        if (preg_match('/KaiOS[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new OperatingSystem('KOS', 'KaiOS', self::version($matches[1]));
        }

        if (stripos($userAgent, 'Ubuntu') !== false) {
            $version = null;
            if (preg_match('/Ubuntu[ \/]([0-9.]+)/i', $userAgent, $matches) === 1) {
                $version = self::version($matches[1]);
            }

            return new OperatingSystem('UBT', 'Ubuntu', $version);
        }

        if (stripos($userAgent, 'Linux') !== false || stripos($userAgent, 'X11') !== false) {
            return new OperatingSystem('LIN', 'GNU/Linux');
        }

        return new OperatingSystem();
    }

    private static function version(string $version): string
    {
        return trim(str_replace('_', '.', $version), '.-');
    }

    private static function displayVersion(string $version): string
    {
        return implode('.', \array_slice(explode('.', self::version($version)), 0, 2));
    }
}
