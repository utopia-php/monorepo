<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\GitLab;

final class GitLabTest extends Base
{
    protected static string $eventHeader = 'x-gitlab-event';
    protected static string $signatureHeader = 'x-gitlab-token';
    protected static string $pushEventName = 'Push Hook';
    protected static string $pullRequestEventName = 'Merge Request Hook';

    /**
     * GitLab names merge request actions as verbs, and a merged one is closed.
     *
     * @var array<string, string>
     */
    protected static array $pullRequestActions = [
        'open' => 'opened',
        'reopen' => 'reopened',
        'update' => 'synchronize',
        'close' => 'closed',
        'merge' => 'closed',
    ];

    protected function createAdapter(): GitLab
    {
        return new GitLab(new Cache(new None()));
    }
    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return $secret;
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false, array $olderCommits = []): string
    {
        $blank = str_repeat('0', 40);
        $repositoryUrl = 'http://example.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

        $olderEntries = array_map(fn(string $hash): array => [
            'id' => $hash,
            'message' => 'Older commit',
            'url' => $repositoryUrl . '/-/commit/' . $hash,
            'author' => ['name' => 'Older Author', 'email' => 'older@example.com'],
        ], $olderCommits);

        return (string) json_encode([
            'object_kind' => 'push',
            'ref' => 'refs/heads/' . $branch,
            // GitLab signals a created or deleted branch with an all-zero sha
            'before' => $created ? $blank : 'abc123',
            'after' => $deleted ? $blank : self::EVENT_COMMIT_HASH,
            'checkout_sha' => $deleted ? '' : self::EVENT_COMMIT_HASH,
            'user_avatar' => 'http://example.com/avatar.png',
            'project' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'namespace' => self::EVENT_OWNER,
                'web_url' => $repositoryUrl,
            ],
            'commits' => $deleted ? [] : [...$olderEntries, [
                'id' => self::EVENT_COMMIT_HASH,
                'message' => self::EVENT_COMMIT_MESSAGE,
                'url' => $repositoryUrl . '/-/commit/' . self::EVENT_COMMIT_HASH,
                'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
                'added' => $added,
                'removed' => $removed,
                'modified' => $modified,
            ]],
        ]);
    }

    protected function pullRequestPayload(bool $external = false, string $action = 'open'): string
    {
        return (string) json_encode([
            'object_kind' => 'merge_request',
            'project' => [
                'id' => (int) self::EVENT_REPOSITORY_ID,
                'name' => self::EVENT_REPOSITORY_NAME,
                'namespace' => self::EVENT_OWNER,
                'web_url' => 'http://example.com/' . self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME,
            ],
            'object_attributes' => [
                'iid' => self::EVENT_PULL_REQUEST_NUMBER,
                'title' => 'Test MR',
                'action' => $action,
                'source_branch' => self::EVENT_HEAD_BRANCH,
                'target_branch' => self::$defaultBranch,
                'source_project_id' => $external ? 456 : (int) self::EVENT_REPOSITORY_ID,
                'target_project_id' => (int) self::EVENT_REPOSITORY_ID,
                'url' => 'http://example.com/mr/' . self::EVENT_PULL_REQUEST_NUMBER,
                'last_commit' => [
                    'id' => self::EVENT_COMMIT_HASH,
                    'message' => self::EVENT_COMMIT_MESSAGE,
                    'url' => 'http://example.com/commit/' . self::EVENT_COMMIT_HASH,
                    'author' => ['name' => self::EVENT_AUTHOR_NAME, 'email' => self::EVENT_AUTHOR_EMAIL],
                ],
            ],
        ]);
    }
}
