<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Detection;

use Utopia\UserAgent\Device;

final class DeviceDetector
{
    public static function detect(string $userAgent): Device
    {
        if ($userAgent === '') {
            return new Device();
        }

        $device = self::console($userAgent)
            ?? self::television($userAgent)
            ?? self::apple($userAgent)
            ?? self::windowsPhone($userAgent)
            ?? self::kindle($userAgent)
            ?? self::android($userAgent)
            ?? self::blackBerry($userAgent)
            ?? self::desktop($userAgent);

        return $device ?? new Device();
    }

    private static function console(string $userAgent): ?Device
    {
        if (preg_match('/Xbox(?: One| Series [XS])?/i', $userAgent, $matches) === 1) {
            return new Device('console', 'Microsoft', $matches[0]);
        }

        if (preg_match('/PlayStation(?: Vita| [345])/i', $userAgent, $matches) === 1) {
            return new Device('console', 'Sony', $matches[0]);
        }

        if (preg_match('/Nintendo (?:Switch|WiiU?|3DS)/i', $userAgent, $matches) === 1) {
            return new Device('console', 'Nintendo', $matches[0]);
        }

        return null;
    }

    private static function television(string $userAgent): ?Device
    {
        if (stripos($userAgent, 'AppleTV') !== false) {
            return new Device('tv', 'Apple', 'Apple TV');
        }

        if (preg_match('/(?:Smart-?TV|SMARTTV|HbbTV|GoogleTV|Android TV|BRAVIA|NetCast|Tizen TV|webOS)/i', $userAgent) === 1) {
            return new Device('tv', self::brand($userAgent));
        }

        return null;
    }

    private static function apple(string $userAgent): ?Device
    {
        if (stripos($userAgent, 'iPad') !== false) {
            return new Device('tablet', 'Apple', 'iPad');
        }

        if (stripos($userAgent, 'iPhone') !== false) {
            return new Device('smartphone', 'Apple', 'iPhone');
        }

        if (stripos($userAgent, 'iPod') !== false) {
            return new Device('portable media player', 'Apple', 'iPod');
        }

        if (stripos($userAgent, 'Watch') !== false && stripos($userAgent, 'Apple') !== false) {
            return new Device('wearable', 'Apple', 'Apple Watch');
        }

        return null;
    }

    private static function windowsPhone(string $userAgent): ?Device
    {
        if (stripos($userAgent, 'Windows Phone') === false) {
            return null;
        }

        return new Device('smartphone', 'Microsoft', self::model($userAgent));
    }

    private static function kindle(string $userAgent): ?Device
    {
        if (preg_match('/(?:Kindle|Silk\/|KF[A-Z0-9]+)/i', $userAgent) !== 1) {
            return null;
        }

        return new Device('tablet', 'Amazon', self::model($userAgent));
    }

    private static function android(string $userAgent): ?Device
    {
        if (stripos($userAgent, 'Android') === false) {
            return null;
        }

        $model = self::model($userAgent);
        $type = stripos($userAgent, 'Mobile') === false || self::hasTabletModel($model)
            ? 'tablet'
            : 'smartphone';

        return new Device($type, self::brand($userAgent, $model), $model);
    }

    private static function hasTabletModel(?string $model): bool
    {
        if ($model === null) {
            return false;
        }

        return preg_match(
            '/^(?:SM-[TX]|GT-P|Nexus (?:7|9|10)\b|Pixel (?:C|Tablet)\b|(?:Lenovo )?(?:TB-|YT-|Tab\b)|(?:Huawei )?MediaPad\b|(?:Xiaomi |Redmi |OnePlus )?Pad\b)/i',
            $model,
        ) === 1;
    }

    private static function blackBerry(string $userAgent): ?Device
    {
        if (preg_match('/(?:BlackBerry|BB10)/i', $userAgent) !== 1) {
            return null;
        }

        return new Device('smartphone', 'BlackBerry', self::model($userAgent));
    }

    private static function desktop(string $userAgent): ?Device
    {
        if (preg_match('/(?:Windows NT|Macintosh|X11|CrOS|Linux x86_64|Linux i[3-6]86)/i', $userAgent) !== 1) {
            return null;
        }

        return new Device(
            'desktop',
            stripos($userAgent, 'Macintosh') !== false ? 'Apple' : null,
        );
    }

    private static function model(string $userAgent): ?string
    {
        $patterns = [
            '/Android[^;)]*;(?:\s*[a-z]{2}(?:[-_][A-Z]{2})?;)?\s*([^;)]+?)(?:\s+Build\/[^;)]*)?[;)]/i',
            '/Windows Phone[^;)]*;[^;)]*;\s*([^;)]+)/i',
            '/\b(KF[A-Z0-9]{2,})\b/i',
            '/BlackBerry[^;\/]*[\/]?([A-Z0-9-]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $userAgent, $matches) === 1) {
                $model = trim($matches[1]);
                if ($model !== '' && strcasecmp($model, 'wv') !== 0) {
                    return $model;
                }
            }
        }

        return null;
    }

    private static function brand(string $userAgent, ?string $model = null): ?string
    {
        $subject = $model . ' ' . $userAgent;
        $brands = [
            'Samsung' => '/(?:\bSM-[A-Z0-9]+|Samsung)/i',
            'Google' => '/(?:\bPixel\b|Nexus)/i',
            'Huawei' => '/(?:Huawei|\bHUAWEI\b|\bANE-|\bELE-|\bVOG-)/i',
            'Honor' => '/(?:Honor|\bHLK-|\bBKL-)/i',
            'Xiaomi' => '/(?:Xiaomi|Redmi|\bMi [A-Z0-9])/i',
            'OnePlus' => '/(?:OnePlus|\bONEPLUS\b)/i',
            'Oppo' => '/(?:\bOPPO\b|\bCPH[0-9]+)/i',
            'Vivo' => '/(?:\bvivo\b|\bV[0-9]{4})/i',
            'Motorola' => '/(?:Motorola|\bmoto\b|\bXT[0-9]{4})/i',
            'Nokia' => '/Nokia/i',
            'Sony' => '/(?:Sony|Xperia)/i',
            'LG' => '/(?:\bLG[- ]|\bLM-[A-Z0-9]+)/i',
            'Amazon' => '/(?:Kindle|Silk\/|\bKF[A-Z0-9]+)/i',
        ];

        foreach ($brands as $brand => $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                return $brand;
            }
        }

        return null;
    }
}
