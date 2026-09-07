<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\VCS\Adapter\Git\Bitbucket;

final class BitbucketTest extends Base
{
    // Bitbucket routes by "workspace/slug" rather than a numeric id
    protected const EVENT_REPOSITORY_ID = self::EVENT_OWNER . '/' . self::EVENT_REPOSITORY_NAME;

    private const string REPOSITORY_URL = 'https://bitbucket.org/' . self::EVENT_REPOSITORY_ID;

    private const string REPOSITORY_UUID = '{11111111-2222-3333-4444-555555555555}';

    private const string FORK_UUID = '{99999999-2222-3333-4444-555555555555}';

    // Bitbucket names an empty repository's first branch 'master'
    protected static string $defaultBranch = 'master';
    protected static string $eventHeader = 'x-event-key';
    protected static string $signatureHeader = 'x-hub-signature';
    protected static string $pushEventName = 'repo:push';
    protected static string $pullRequestEventName = 'pullrequest:created';

    /**
     * Bitbucket names the action in the event rather than the payload, and
     * has no reopen event.
     *
     * @var array<string, string>
     */
    protected static array $pullRequestActions = [
        'pullrequest:created' => 'opened',
        'pullrequest:updated' => 'synchronize',
        'pullrequest:fulfilled' => 'closed',
        'pullrequest:rejected' => 'closed',
    ];

    // Bitbucket's push payload carries no file lists at all
    protected static bool $reportsAffectedFilesInPushEvent = false;

    protected function createAdapter(): Bitbucket
    {
        return new Bitbucket(new Cache(new None()));
    }
    protected function signWebhookPayload(string $payload, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $payload, $secret);
    }

    #[\Override]
    protected function pullRequestEventFor(string $action): string
    {
        return $action;
    }

    protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false, array $olderCommits = []): string
    {
        $ref = [
            'type' => 'branch',
            'name' => $branch,
            'target' => [
                'hash' => self::EVENT_COMMIT_HASH,
                'message' => self::EVENT_COMMIT_MESSAGE,
                'author' => ['raw' => self::EVENT_AUTHOR_NAME . ' <' . self::EVENT_AUTHOR_EMAIL . '>'],
                'links' => ['html' => ['href' => self::REPOSITORY_URL . '/commits/' . self::EVENT_COMMIT_HASH]],
            ],
        ];

        // The adapter reads the head off new.target, so the commit list is
        // there to prove the first commit listed is not taken for it
        $commits = array_map(fn(string $hash): array => [
            'hash' => $hash,
            'message' => 'Older commit',
            'author' => ['raw' => 'Older Author <older@example.com>'],
            'links' => ['html' => ['href' => self::REPOSITORY_URL . '/commits/' . $hash]],
        ], $olderCommits);
        $commits[] = $ref['target'];

        // A created branch has no old state and a deleted one no new state. The
        // file lists go unused, Bitbucket naming no files in a push.
        return (string) json_encode([
            'actor' => $this->eventActor(),
            'repository' => $this->eventRepository(),
            'push' => [
                'changes' => [[
                    'created' => $created,
                    'closed' => $deleted,
                    'old' => $created ? null : $ref,
                    'new' => $deleted ? null : $ref,
                    'commits' => $commits,
                ]],
            ],
        ]);
    }

    /**
     * The event names the action, so the payload is the same for every one.
     */
    protected function pullRequestPayload(bool $external = false, string $action = 'pullrequest:created'): string
    {
        return (string) json_encode([
            'actor' => $this->eventActor(),
            'repository' => $this->eventRepository(),
            'pullrequest' => [
                'id' => self::EVENT_PULL_REQUEST_NUMBER,
                'title' => 'Test PR',
                'state' => 'OPEN',
                'source' => [
                    'branch' => ['name' => self::EVENT_HEAD_BRANCH],
                    'commit' => ['hash' => self::EVENT_COMMIT_HASH],
                    // A source in another repository is a fork-based contribution
                    'repository' => ['uuid' => $external ? self::FORK_UUID : self::REPOSITORY_UUID],
                ],
                'destination' => [
                    'branch' => ['name' => self::$defaultBranch],
                    'repository' => ['uuid' => self::REPOSITORY_UUID],
                ],
            ],
        ]);
    }

    private function eventRepository(): array
    {
        return [
            'uuid' => self::REPOSITORY_UUID,
            'name' => self::EVENT_REPOSITORY_NAME,
            'full_name' => self::EVENT_REPOSITORY_ID,
            'workspace' => ['slug' => self::EVENT_OWNER],
            'links' => ['html' => ['href' => self::REPOSITORY_URL]],
        ];
    }

    private function eventActor(): array
    {
        return [
            'display_name' => 'Tester',
            'links' => [
                'html' => ['href' => 'https://bitbucket.org/tester'],
                'avatar' => ['href' => 'https://bitbucket.org/account/tester/avatar/'],
            ],
        ];
    }

    public function testGetEventPushWithLinkedAuthor(): void
    {
        $payload = json_decode($this->pushPayload(self::$defaultBranch), true);
        $this->assertIsArray($payload);
        $payload['push']['changes'][0]['new']['target']['author']['user'] = ['display_name' => 'Linked User'];

        $events = $this->vcsAdapter->getEvents(self::$pushEventName, (string) json_encode($payload));
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame('Linked User', $result['headCommitAuthorName']);
        $this->assertSame(self::EVENT_AUTHOR_EMAIL, $result['headCommitAuthorEmail']);
    }

    public function testGetEventsReportsEveryPushedBranch(): void
    {
        $payload = (string) json_encode([
            'actor' => $this->eventActor(),
            'repository' => $this->eventRepository(),
            'push' => [
                'changes' => [
                    ['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => 'aaa111']], 'created' => false, 'closed' => false],
                    ['new' => ['type' => 'tag', 'name' => 'v1.0.0', 'target' => ['hash' => 'bbb222']]],
                    ['new' => ['type' => 'branch', 'name' => 'feature', 'target' => ['hash' => 'ccc333']], 'created' => true, 'closed' => false],
                ],
            ],
        ]);

        $events = $this->vcsAdapter->getEvents(self::$pushEventName, $payload);

        // The tag between them is left out
        $this->assertCount(2, $events);
        $this->assertSame(['main', 'feature'], array_column($events, 'branch'));
        $this->assertSame(['aaa111', 'ccc333'], array_column($events, 'commitHash'));
        $this->assertTrue($events[1]['branchCreated']);

        // A push carrying nothing but tags has no branch to report
        $tagsOnly = (string) json_encode([
            'repository' => $this->eventRepository(),
            'push' => ['changes' => [['new' => ['type' => 'tag', 'name' => 'v1.0.0', 'target' => ['hash' => 'aaa111']]]]],
        ]);

        $this->assertSame([], $this->vcsAdapter->getEvents(self::$pushEventName, $tagsOnly));
    }
}
