<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Tests\Fixtures\GitHubRepositories;

final class GitHubRepositorySearchTest extends TestCase
{
    public function testSearchZero(): void
    {
        $match = ['id' => 2, 'name' => 'release-0'];
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'selected']],
            ['body' => ['repositories' => [
                ['id' => 1, 'name' => 'unrelated'],
                $match,
                ['id' => 3, 'name' => 'other'],
            ], 'total_count' => 3]],
        ]);

        $this->assertSame(['items' => [$match], 'total' => 1], $adapter->searchRepositories('owner', 1, 10, '0'));
    }

    public function testSearchZeroFiltersBeforePagination(): void
    {
        $match = ['id' => 3, 'name' => 'version-0'];
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'selected']],
            ['body' => ['repositories' => [
                ['id' => 1, 'name' => 'unrelated'],
                ['id' => 2, 'name' => 'release-0'],
                $match,
            ], 'total_count' => 3]],
        ]);

        $this->assertSame(['items' => [$match], 'total' => 2], $adapter->searchRepositories('owner', 2, 1, '0'));
    }

    public function testSearchEmpty(): void
    {
        $repositories = [['id' => 1, 'name' => 'unrelated'], ['id' => 2, 'name' => 'release-0']];
        $adapter = new GitHubRepositories([
            ['body' => ['repository_selection' => 'selected']],
            ['body' => ['repositories' => $repositories, 'total_count' => 2]],
        ]);

        $this->assertSame(['items' => $repositories, 'total' => 2], $adapter->searchRepositories('owner', 1, 10, ''));
    }
}
