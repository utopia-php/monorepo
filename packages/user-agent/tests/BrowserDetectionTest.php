<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class BrowserDetectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string, string, string|null}>
     */
    public static function browsers(): array
    {
        return [
            'Edge' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/126.0.0.0',
                'PS',
                'Microsoft Edge',
                'Blink',
                '126.0',
                '124.0.0.0',
            ],
            'Opera' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 OPR/110.0.0.0',
                'OP',
                'Opera',
                'Blink',
                '110.0',
                '124.0.0.0',
            ],
            'Samsung Internet' => [
                'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) SamsungBrowser/25.0 Chrome/121.0.0.0 Mobile Safari/537.36',
                'SB',
                'Samsung Browser',
                'Blink',
                '25.0',
                '121.0.0.0',
            ],
            'Chrome iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/126.0.6478.54 '
                . 'Mobile/15E148 Safari/604.1',
                'CI',
                'Chrome Mobile iOS',
                'WebKit',
                '126.0',
                '605.1.15',
            ],
            'Firefox iOS' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/127.0 '
                . 'Mobile/15E148 Safari/605.1.15',
                'F1',
                'Firefox Mobile iOS',
                'WebKit',
                '127.0',
                '605.1.15',
            ],
            'Android WebView' => [
                'Mozilla/5.0 (Linux; Android 13; Pixel 6 Build/TQ3A; wv) '
                . 'AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 '
                . 'Chrome/120.0.0.0 Mobile Safari/537.36',
                'CV',
                'Chrome Webview',
                'Blink',
                '120.0',
                '120.0.0.0',
            ],
            'Internet Explorer' => [
                'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko',
                'IE',
                'Internet Explorer',
                'Trident',
                '11.0',
                '7.0',
            ],
            'Opera Mini' => [
                'Opera/9.80 (Android; Opera Mini/36.2.2254/191.249; U; en) '
                . 'Presto/2.12.423 Version/12.16',
                'OI',
                'Opera Mini',
                'Presto',
                '36.2',
                '2.12.423',
            ],
        ];
    }

    #[DataProvider('browsers')]
    public function testBrowser(
        string $userAgent,
        string $code,
        string $name,
        string $engine,
        string $version,
        ?string $engineVersion,
    ): void {
        $client = UserAgent::parse($userAgent)->client();

        $this->assertSame('browser', $client->type);
        $this->assertSame($code, $client->code);
        $this->assertSame($name, $client->name);
        $this->assertSame($engine, $client->engine);
        $this->assertSame($version, $client->version);
        $this->assertSame($engineVersion, $client->engineVersion);
    }
}
