<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Detection;

use Utopia\UserAgent\Bot;

final class BotDetector
{
    /** @var array<string, array{string, string}> */
    private const array BOTS = [
        'googlebot' => ['Googlebot', 'search crawler'],
        'google-inspectiontool' => ['Google Inspection Tool', 'search crawler'],
        'adsbot-google' => ['Google AdsBot', 'advertising crawler'],
        'bingbot' => ['Bingbot', 'search crawler'],
        'duckduckbot' => ['DuckDuckBot', 'search crawler'],
        'baiduspider' => ['Baiduspider', 'search crawler'],
        'yandexbot' => ['YandexBot', 'search crawler'],
        'applebot' => ['Applebot', 'search crawler'],
        'petalbot' => ['PetalBot', 'search crawler'],
        'facebookexternalhit' => ['Facebook External Hit', 'social preview'],
        'facebot' => ['Facebook Bot', 'social preview'],
        'twitterbot' => ['Twitterbot', 'social preview'],
        'linkedinbot' => ['LinkedInBot', 'social preview'],
        'slackbot' => ['Slackbot', 'social preview'],
        'discordbot' => ['Discordbot', 'social preview'],
        'whatsapp' => ['WhatsApp', 'social preview'],
        'telegrambot' => ['TelegramBot', 'social preview'],
        'ahrefsbot' => ['AhrefsBot', 'site crawler'],
        'semrushbot' => ['SemrushBot', 'site crawler'],
        'mj12bot' => ['MJ12bot', 'site crawler'],
        'gptbot' => ['GPTBot', 'ai crawler'],
        'chatgpt-user' => ['ChatGPT User', 'ai assistant'],
        'claudebot' => ['ClaudeBot', 'ai crawler'],
        'claude-web' => ['Claude Web', 'ai assistant'],
        'amazonbot' => ['Amazonbot', 'ai crawler'],
        'bytespider' => ['Bytespider', 'ai crawler'],
        'headlesschrome' => ['Headless Chrome', 'automation'],
        'phantomjs' => ['PhantomJS', 'automation'],
        'lighthouse' => ['Lighthouse', 'site monitor'],
        'uptimerobot' => ['UptimeRobot', 'site monitor'],
    ];

    public static function detect(string $userAgent): ?Bot
    {
        if ($userAgent === '') {
            return null;
        }

        $lower = strtolower($userAgent);
        foreach (self::BOTS as $needle => [$name, $category]) {
            if (str_contains($lower, $needle)) {
                return new Bot($name, $category);
            }
        }

        if (preg_match(
            '/(?:^|[\s;()+_-])([a-z0-9_-]*(?:bot|crawler|spider|scraper|slurp))(?:[\/\s;()+_-]|$)/i',
            $userAgent,
            $matches,
        ) === 1) {
            return new Bot(trim($matches[1], '_-'), 'crawler');
        }

        return null;
    }
}
