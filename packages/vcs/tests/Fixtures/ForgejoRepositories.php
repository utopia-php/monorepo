<?php

declare(strict_types=1);

namespace Utopia\Tests\Fixtures;

use Utopia\VCS\Adapter\Git\Forgejo;

final class ForgejoRepositories extends Forgejo
{
    use RepositorySearch;
}
