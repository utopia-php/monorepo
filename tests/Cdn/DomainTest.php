<?php

namespace Utopia\Tests\Cdn;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Domain;

class DomainTest extends TestCase
{
    public function testFoldsCaseToCanonicalForm(): void
    {
        $this->assertSame('app.example.com', Domain::validate('APP.Example.CoM'));
        $this->assertSame('app.example.com', Domain::validate('app.example.com'));
    }

    /** @return array<string, array<int, string>> */
    public static function malformed(): array
    {
        return [
            'empty' => [''],
            'scheme' => ['https://app.example.com'],
            'port' => ['app.example.com:443'],
            'path' => ['app.example.com/certs'],
            'trailing slash' => ['app.example.com/'],
            'underscore' => ['app_example.com'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testRejectsMalformedDomains(string $domain): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Domain::validate($domain);
    }
}
