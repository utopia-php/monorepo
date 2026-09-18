<?php

declare(strict_types=1);

namespace Utopia\Tests\E2E;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Tests\Services;
use Utopia\VCS\Adapter\Git\Forgejo;

final class ForgejoTest extends GiteaBase
{
    protected static string $owner = '';
    protected static string $avatarDomain = '/avatars/';

    // Forgejo's API user carries html_url, which Gitea 1.21's does not
    protected static bool $reportsCommitAuthorUrl = true;

    protected function setupAdapter(): void
    {
        $adapter = new Forgejo(new Cache(new None()));
        $adapter->initializeVariables(
            installationId: '',
            privateKey: '',
            appId: '',
            accessToken: Services::token('forgejo'),
            refreshToken: '',
        );
        $adapter->setEndpoint(Services::FORGEJO_URL);

        if (self::$owner === '') {
            self::$owner = $adapter->createOrganization('test-org-' . uniqid());
        }

        $this->vcsAdapter = $adapter;
    }

    protected function anonymousCloneUrl(string $repositoryName): string
    {
        return Services::FORGEJO_URL . '/' . $this->ownerPath() . '/' . $repositoryName . '.git';
    }
}
