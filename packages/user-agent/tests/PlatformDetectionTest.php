<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class PlatformDetectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function platforms(): array
    {
        return [
            'Chrome OS' => [
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 Chrome/101.0.0.0 Safari/537.36',
                'COS',
                'Chrome OS',
                'desktop',
            ],
            'Ubuntu' => [
                'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'UBT',
                'Ubuntu',
                'desktop',
            ],
            'Windows Phone' => [
                'Mozilla/5.0 (Windows Phone 10.0; Android 6.0.1; Microsoft; Lumia 950 XL)',
                'WPH',
                'Windows Phone',
                'smartphone',
            ],
            'Tizen TV' => [
                'Mozilla/5.0 (SMART-TV; Linux; Tizen 6.0) AppleWebKit/537.36 TV Safari/537.36',
                'TIZ',
                'Tizen',
                'tv',
            ],
        ];
    }

    #[DataProvider('platforms')]
    public function testPlatform(string $userAgent, string $code, string $name, string $deviceType): void
    {
        $agent = UserAgent::parse($userAgent);

        $this->assertSame($code, $agent->operatingSystem()->code);
        $this->assertSame($name, $agent->operatingSystem()->name);
        $this->assertSame($deviceType, $agent->device()->type);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function specialDevices(): array
    {
        return [
            'Xbox' => ['Mozilla/5.0 (Xbox One; Xbox One OS 10.0)', 'console', 'Microsoft'],
            'PlayStation' => ['Mozilla/5.0 (PlayStation 5/1.00)', 'console', 'Sony'],
            'Nintendo' => ['Mozilla/5.0 (Nintendo Switch; WifiWebAuthApplet)', 'console', 'Nintendo'],
            'Kindle' => ['Mozilla/5.0 (Linux; U; en-US) AppleWebKit/533.16 Silk/3.13 Safari/533.16', 'tablet', 'Amazon'],
            'BlackBerry' => ['Mozilla/5.0 (BB10; Touch) AppleWebKit/537.35 Mobile Safari/537.35', 'smartphone', 'BlackBerry'],
        ];
    }

    #[DataProvider('specialDevices')]
    public function testSpecialDevice(string $userAgent, string $type, string $brand): void
    {
        $device = UserAgent::parse($userAgent)->device();

        $this->assertSame($type, $device->type);
        $this->assertSame($brand, $device->brand);
    }
}
