<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Tests\Fixtures\BitbucketRepositories;
use Utopia\Tests\Fixtures\ForgejoRepositories;
use Utopia\Tests\Fixtures\GiteaRepositories;
use Utopia\Tests\Fixtures\GitLabRepositories;
use Utopia\Tests\Fixtures\GogsRepositories;

final class RepositorySearchTest extends TestCase
{
    #[DataProvider('providers')]
    public function testSearchZero(string $adapterClass, array $responses, string $parameter, string $value): void
    {
        $adapter = new $adapterClass($responses);

        $adapter->searchRepositories('owner', 1, 10, '0');

        foreach ($adapter->requests as $path) {
            parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
            $this->assertSame($value, $query[$parameter] ?? null);
        }
    }

    #[DataProvider('providers')]
    public function testSearchEmpty(string $adapterClass, array $responses, string $parameter, string $value): void
    {
        $adapter = new $adapterClass($responses);

        $adapter->searchRepositories('owner', 1, 10, '');

        foreach ($adapter->requests as $path) {
            parse_str(parse_url($path, PHP_URL_QUERY) ?? '', $query);
            $this->assertArrayNotHasKey($parameter, $query);
        }
    }

    public static function providers(): \Iterator
    {
        foreach ([GiteaRepositories::class, ForgejoRepositories::class, GogsRepositories::class] as $adapter) {
            yield $adapter => [$adapter, [['body' => ['data' => []]]], 'q', '0'];
        }
        yield 'gitlab group' => [GitLabRepositories::class, [['body' => []]], 'search', '0'];
        yield 'gitlab user' => [GitLabRepositories::class, [['headers' => ['status-code' => 404]], ['body' => []]], 'search', '0'];
        yield 'bitbucket' => [BitbucketRepositories::class, [['body' => ['values' => []]]], 'q', 'name~"0"'];
    }
}
