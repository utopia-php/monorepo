<?php

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\InvalidResourceException;
use Utopia\Auth\OAuth2\ResourceIndicators;

class ResourceIndicatorsTest extends TestCase
{
    public function testNormalizesResources(): void
    {
        $this->assertSame([], ResourceIndicators::from(null)->toArray());
        $this->assertSame([], ResourceIndicators::from('')->toArray());
        $this->assertSame(['https://api.example.com/'], ResourceIndicators::from('https://api.example.com/')->toArray());

        $this->assertSame(
            ['https://api.example.com/', 'urn:example:resource'],
            ResourceIndicators::from([
                'https://api.example.com/',
                'urn:example:resource',
                'https://api.example.com/',
            ])->toArray(),
        );
    }

    /**
     * @param string|array<int, mixed> $resources
     */
    #[DataProvider('invalidResourceProvider')]
    public function testRejectsInvalidResources(string|array $resources, string $message): void
    {
        $this->assertSame('invalid_target', InvalidResourceException::ERROR_CODE);

        $this->expectException(InvalidResourceException::class);
        $this->expectExceptionMessage($message);

        ResourceIndicators::from($resources);
    }

    public function testComparesResourceSets(): void
    {
        $this->assertTrue(
            ResourceIndicators::from(['https://api.example.com/'])
                ->isSubsetOf(ResourceIndicators::from(['https://api.example.com/', 'https://files.example.com/'])),
        );
        $this->assertFalse(
            ResourceIndicators::from(['https://api.example.com/'])
                ->isSubsetOf(ResourceIndicators::from(['https://files.example.com/'])),
        );

        $this->assertTrue(
            ResourceIndicators::from(['https://api.example.com/', 'https://files.example.com/'])
                ->equals(ResourceIndicators::from(['https://files.example.com/', 'https://api.example.com/'])),
        );
    }

    public function testBuildsAudience(): void
    {
        $this->assertSame(
            ['https://cloud.appwrite.io/v1/project1'],
            ResourceIndicators::from(null)->audience('https://cloud.appwrite.io/v1/project1'),
        );

        $this->assertSame(
            ['https://mcp.example.com/'],
            ResourceIndicators::from([
                'https://mcp.example.com/',
            ])->audience('https://cloud.appwrite.io/v1/project1'),
        );

        $this->assertSame(
            ['https://cloud.appwrite.io/v1/project1'],
            ResourceIndicators::from([
                'https://cloud.appwrite.io/v1/project1',
            ])->audience('https://cloud.appwrite.io/v1/project1'),
        );
    }

    /**
     * @return array<string, array{resources: string|array<int, mixed>, message: string}>
     */
    public static function invalidResourceProvider(): array
    {
        return [
            'fragment' => [
                'resources' => 'https://api.example.com/#section',
                'message' => 'resource must be an absolute URI with no fragment component.',
            ],
            'relative URI' => [
                'resources' => '/relative',
                'message' => 'resource must be an absolute URI with no fragment component.',
            ],
            'non-string' => [
                'resources' => ['https://api.example.com/', 42],
                'message' => 'resource must be a non-empty absolute URI.',
            ],
        ];
    }
}
