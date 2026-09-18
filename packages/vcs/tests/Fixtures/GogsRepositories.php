<?php

declare(strict_types=1);

namespace Utopia\Tests\Fixtures;

use Utopia\VCS\Adapter\Git\Gogs;

final class GogsRepositories extends Gogs
{
    use RepositorySearch;
}
