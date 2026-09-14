<?php

declare(strict_types=1);

namespace Utopia\Tests\Fixtures;

use Utopia\VCS\Adapter\Git\Gitea;

final class GiteaRepositories extends Gitea
{
    use RepositorySearch;
}
