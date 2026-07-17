<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Detection;

use Utopia\UserAgent\Client;

final class ClientDetector
{
    public static function detect(string $userAgent): Client
    {
        if ($userAgent === '') {
            return new Client();
        }

        $client = self::edge($userAgent)
            ?? self::opera($userAgent)
            ?? self::samsung($userAgent)
            ?? self::chromeIos($userAgent)
            ?? self::firefoxIos($userAgent)
            ?? self::androidWebView($userAgent)
            ?? self::chrome($userAgent)
            ?? self::firefox($userAgent)
            ?? self::safari($userAgent)
            ?? self::internetExplorer($userAgent)
            ?? self::library($userAgent);

        return $client ?? new Client();
    }

    private static function edge(string $userAgent): ?Client
    {
        if (preg_match('/(?:EdgA|EdgiOS|Edg|Edge)\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome') ?? self::version($matches[1]);

        return new Client('browser', 'PS', 'Microsoft Edge', self::displayVersion($matches[1]), 'Blink', $engineVersion);
    }

    private static function opera(string $userAgent): ?Client
    {
        if (preg_match('/Opera Mini\/([0-9.]+)/i', $userAgent, $matches) === 1) {
            return new Client(
                'browser',
                'OI',
                'Opera Mini',
                self::displayVersion($matches[1]),
                'Presto',
                self::tokenVersion($userAgent, 'Presto'),
            );
        }

        if (preg_match('/(?:OPR|Opera)\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome') ?? self::version($matches[1]);

        return new Client('browser', 'OP', 'Opera', self::displayVersion($matches[1]), 'Blink', $engineVersion);
    }

    private static function samsung(string $userAgent): ?Client
    {
        if (preg_match('/SamsungBrowser\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome') ?? self::version($matches[1]);

        return new Client('browser', 'SB', 'Samsung Browser', self::displayVersion($matches[1]), 'Blink', $engineVersion);
    }

    private static function chromeIos(string $userAgent): ?Client
    {
        if (preg_match('/CriOS\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return new Client(
            'browser',
            'CI',
            'Chrome Mobile iOS',
            self::displayVersion($matches[1]),
            'WebKit',
            self::tokenVersion($userAgent, 'AppleWebKit'),
        );
    }

    private static function firefoxIos(string $userAgent): ?Client
    {
        if (preg_match('/FxiOS\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return new Client(
            'browser',
            'F1',
            'Firefox Mobile iOS',
            self::displayVersion($matches[1]),
            'WebKit',
            self::tokenVersion($userAgent, 'AppleWebKit'),
        );
    }

    private static function androidWebView(string $userAgent): ?Client
    {
        if (!str_contains($userAgent, '; wv)') && stripos($userAgent, 'Version/4.0 Chrome/') === false) {
            return null;
        }

        $engineVersion = self::tokenVersion($userAgent, 'Chrome');
        if ($engineVersion === null) {
            return null;
        }

        return new Client('browser', 'CV', 'Chrome Webview', self::displayVersion($engineVersion), 'Blink', $engineVersion);
    }

    private static function chrome(string $userAgent): ?Client
    {
        if (preg_match('/(?:Chrome|Chromium|HeadlessChrome)\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $engineVersion = self::version($matches[1]);
        $mobile = stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'Android') !== false;

        return new Client(
            'browser',
            $mobile ? 'CM' : 'CH',
            $mobile ? 'Chrome Mobile' : 'Chrome',
            self::displayVersion($matches[1]),
            'Blink',
            $engineVersion,
        );
    }

    private static function firefox(string $userAgent): ?Client
    {
        if (preg_match('/Firefox\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        $version = self::displayVersion($matches[1]);
        $mobile = stripos($userAgent, 'Mobile') !== false || stripos($userAgent, 'Android') !== false;

        return new Client(
            'browser',
            $mobile ? 'FM' : 'FF',
            $mobile ? 'Firefox Mobile' : 'Firefox',
            $version,
            'Gecko',
            $version,
        );
    }

    private static function safari(string $userAgent): ?Client
    {
        if (stripos($userAgent, 'Safari/') === false || stripos($userAgent, 'AppleWebKit/') === false) {
            return null;
        }

        $version = self::tokenVersion($userAgent, 'Version');
        $mobile = stripos($userAgent, 'Mobile/') !== false
            && (stripos($userAgent, 'iPhone') !== false
                || stripos($userAgent, 'iPad') !== false
                || stripos($userAgent, 'iPod') !== false);

        return new Client(
            'browser',
            $mobile ? 'MF' : 'SF',
            $mobile ? 'Mobile Safari' : 'Safari',
            $version,
            'WebKit',
            self::tokenVersion($userAgent, 'AppleWebKit'),
        );
    }

    private static function internetExplorer(string $userAgent): ?Client
    {
        if (preg_match('/(?:MSIE |Trident\/.*?rv:)([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return new Client(
            'browser',
            'IE',
            'Internet Explorer',
            self::version($matches[1]),
            'Trident',
            self::tokenVersion($userAgent, 'Trident'),
        );
    }

    private static function library(string $userAgent): ?Client
    {
        $libraries = [
            '/curl\/([0-9.]+)/i' => 'curl',
            '/Wget\/([0-9.]+)/i' => 'Wget',
            '/PostmanRuntime\/([0-9.]+)/i' => 'Postman Runtime',
            '/okhttp\/([0-9.]+)/i' => 'OkHttp',
            '/Dart\/([0-9.]+)/i' => 'Dart',
            '/GuzzleHttp\/([0-9.]+)/i' => 'Guzzle',
            '/python-requests\/([0-9.]+)/i' => 'Python Requests',
        ];

        foreach ($libraries as $pattern => $name) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                return new Client('library', null, $name, self::displayVersion($matches[1]));
            }
        }

        return null;
    }

    private static function tokenVersion(string $userAgent, string $token): ?string
    {
        if (preg_match('/' . preg_quote($token, '/') . '\/([0-9.]+)/i', $userAgent, $matches) !== 1) {
            return null;
        }

        return self::version($matches[1]);
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
