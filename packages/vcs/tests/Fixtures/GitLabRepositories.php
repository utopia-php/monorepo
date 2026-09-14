<?php

declare(strict_types=1);

namespace Utopia\Tests\Fixtures;

use Utopia\VCS\Adapter\Git\GitLab;

final class GitLabRepositories extends GitLab
{
    use RepositorySearch;
}
