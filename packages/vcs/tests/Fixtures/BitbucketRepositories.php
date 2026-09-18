<?php

declare(strict_types=1);

namespace Utopia\Tests\Fixtures;

use Utopia\VCS\Adapter\Git\Bitbucket;

final class BitbucketRepositories extends Bitbucket
{
    use RepositorySearch;
}
