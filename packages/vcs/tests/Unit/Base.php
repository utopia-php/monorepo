<?php

declare(strict_types=1);

namespace Utopia\Tests\Unit;

use Exception;
use PHPUnit\Framework\TestCase;
use Utopia\VCS\Adapter\Git;

/**
 * The half of the adapter contract that needs no provider: reading a webhook
 * delivery and verifying its signature. Every provider answers it from a
 * payload the subclass builds, so the suite runs on a bare host.
 */
abstract class Base extends TestCase
{
    /**
     * Facts the webhook payload builders below carry, asserted back out of the
     * normalized event. Bitbucket overrides the repository id, having none.
     */
    protected const EVENT_REPOSITORY_ID = '123';

    protected const EVENT_REPOSITORY_NAME = 'test-repo';

    protected const EVENT_OWNER = 'test-owner';

    protected const EVENT_COMMIT_HASH = 'def456';

    protected const EVENT_COMMIT_MESSAGE = 'Test commit message';

    protected const EVENT_AUTHOR_NAME = 'Test Author';

    protected const EVENT_AUTHOR_EMAIL = 'author@example.com';

    protected const EVENT_HEAD_BRANCH = 'feature-branch';

    protected const EVENT_PULL_REQUEST_NUMBER = 42;

    protected Git $vcsAdapter;

    protected static string $defaultBranch = 'main';

    /**
     * Scopes the provider accepts webhooks at. Only GitHub registers them
     * once per installation as well as per repository.
     *
     * @var array<string>
     */
    protected static array $supportedWebhookScopes = [Git::WEBHOOK_SCOPE_REPOSITORY];

    /**
     * Names the provider uses for the events it delivers.
     */
    protected static string $pushEventName = 'push';

    protected static string $pullRequestEventName = 'pull_request';

    /**
     * Actions the provider sends for a pull request, each with the shared
     * vocabulary it normalizes to. GitHub's names are that vocabulary.
     *
     * @var array<string, string>
     */
    protected static array $pullRequestActions = [
        'opened' => 'opened',
        'reopened' => 'reopened',
        'synchronize' => 'synchronize',
        'closed' => 'closed',
    ];

    /**
     * Headers the provider sends its webhook event type and signature under.
     */
    protected static string $eventHeader = '';

    protected static string $signatureHeader = '';

    /**
     * Whether a push event names the files it touched. Bitbucket's payload
     * carries no file lists at all.
     */
    protected static bool $reportsAffectedFilesInPushEvent = true;

    /**
     * Build the adapter under test. It stays uninitialized against any
     * provider - nothing here is allowed to reach the network.
     */
    abstract protected function createAdapter(): Git;

    /**
     * Sign a payload the way the provider signs its webhooks.
     */
    abstract protected function signWebhookPayload(string $payload, string $secret): string;

    /**
     * Build a push payload shaped the way this provider sends one, carrying the
     * EVENT_* facts above.
     *
     * @param array<string> $added
     * @param array<string> $removed
     * @param array<string> $modified
     * @param array<string> $olderCommits Hashes listed before the head commit, each with its own message and author
     */
    abstract protected function pushPayload(string $branch, array $added = [], array $removed = [], array $modified = [], bool $created = false, bool $deleted = false, array $olderCommits = []): string;

    /**
     * Build a pull request payload shaped the way this provider sends one,
     * opening EVENT_HEAD_BRANCH against the default branch. The action is the
     * provider's own name for it, and defaults to its opened one.
     */
    abstract protected function pullRequestPayload(bool $external = false, string $action = 'opened'): string;

    /**
     * Event a pull request action is delivered under. Bitbucket names the
     * action in the event rather than the payload.
     */
    protected function pullRequestEventFor(string $action): string
    {
        return static::$pullRequestEventName;
    }

    protected function setUp(): void
    {
        $this->vcsAdapter = $this->createAdapter();
    }

    public function testWebhookHeaderNames(): void
    {
        $this->assertSame(static::$eventHeader, $this->vcsAdapter->getEventHeaderName());
        $this->assertSame(static::$signatureHeader, $this->vcsAdapter->getSignatureHeaderName());
    }

    public function testGetSupportedWebhookScopes(): void
    {
        $this->assertSame(static::$supportedWebhookScopes, $this->vcsAdapter->getSupportedWebhookScopes());
    }
    public function testValidateWebhookEvent(): void
    {
        $payload = '{"object_kind":"push","action":"push"}';
        $secret = 'my-webhook-secret';

        $this->assertTrue(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, $secret), $secret),
        );
        $this->assertFalse($this->vcsAdapter->validateWebhookEvent($payload, 'not-the-signature', $secret));
        $this->assertFalse(
            $this->vcsAdapter->validateWebhookEvent($payload, $this->signWebhookPayload($payload, 'another-secret'), $secret),
        );
    }
    public function testGetEventPush(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, ['file1.txt'], ['file2.txt'], ['file3.txt']),
        );
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame(static::$defaultBranch, $result['branch']);
        $this->assertSame(static::EVENT_REPOSITORY_ID, $result['repositoryId']);
        $this->assertSame(self::EVENT_REPOSITORY_NAME, $result['repositoryName']);
        $this->assertSame(self::EVENT_OWNER, $result['owner']);
        $this->assertSame(self::EVENT_COMMIT_HASH, $result['commitHash']);
        $this->assertSame(self::EVENT_COMMIT_MESSAGE, $result['headCommitMessage']);
        $this->assertSame(self::EVENT_AUTHOR_NAME, $result['headCommitAuthorName']);
        $this->assertSame(self::EVENT_AUTHOR_EMAIL, $result['headCommitAuthorEmail']);
        $this->assertNotEmpty($result['headCommitUrl']);
        $this->assertNotEmpty($result['repositoryUrl']);
        $this->assertNotEmpty($result['branchUrl']);
        $this->assertFalse($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
        $this->assertEqualsCanonicalizing(
            static::$reportsAffectedFilesInPushEvent ? ['file1.txt', 'file2.txt', 'file3.txt'] : [],
            $result['affectedFiles'],
        );
    }

    /**
     * A push lists every commit it carried; the event describes the head, not
     * the first one listed.
     */
    public function testGetEventPushReportsHeadCommit(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, olderCommits: ['aaa111', 'bbb222']),
        );
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame(self::EVENT_COMMIT_HASH, $result['commitHash']);
        $this->assertSame(self::EVENT_COMMIT_MESSAGE, $result['headCommitMessage']);
        $this->assertSame(self::EVENT_AUTHOR_NAME, $result['headCommitAuthorName']);
        $this->assertSame(self::EVENT_AUTHOR_EMAIL, $result['headCommitAuthorEmail']);
        // Providers shape the commit url differently, but each ends it with the hash
        $this->assertStringEndsWith('/' . self::EVENT_COMMIT_HASH, $result['headCommitUrl']);
    }

    public function testGetEventPushDetectsBranchCreated(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, created: true),
        );
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertTrue($result['branchCreated']);
        $this->assertFalse($result['branchDeleted']);
    }

    public function testGetEventPushDetectsBranchDeleted(): void
    {
        $events = $this->vcsAdapter->getEvents(
            static::$pushEventName,
            $this->pushPayload(static::$defaultBranch, deleted: true),
        );
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertFalse($result['branchCreated']);
        $this->assertTrue($result['branchDeleted']);
    }

    public function testGetEventPullRequest(): void
    {
        $events = $this->vcsAdapter->getEvents(static::$pullRequestEventName, $this->pullRequestPayload());
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertSame('opened', $result['action']);
        $this->assertSame(self::EVENT_HEAD_BRANCH, $result['branch']);
        $this->assertSame(self::EVENT_PULL_REQUEST_NUMBER, $result['pullRequestNumber']);
        $this->assertSame(static::EVENT_REPOSITORY_ID, $result['repositoryId']);
        $this->assertSame(self::EVENT_REPOSITORY_NAME, $result['repositoryName']);
        $this->assertSame(self::EVENT_OWNER, $result['owner']);
        $this->assertSame(self::EVENT_COMMIT_HASH, $result['commitHash']);
        $this->assertFalse($result['external']);
    }

    public function testGetEventPullRequestDetectsExternal(): void
    {
        $events = $this->vcsAdapter->getEvents(static::$pullRequestEventName, $this->pullRequestPayload(external: true));
        $this->assertCount(1, $events);
        $result = $events[0];

        $this->assertTrue($result['external']);
    }

    /**
     * Every pull request action normalizes to the shared vocabulary, and each
     * provider sends the three consumers act on: opened, synchronize, closed.
     */
    public function testGetEventPullRequestNormalizesAction(): void
    {
        $vocabulary = ['opened', 'reopened', 'synchronize', 'closed'];

        $this->assertSame([], array_diff(static::$pullRequestActions, $vocabulary));
        $this->assertSame([], array_diff(['opened', 'synchronize', 'closed'], static::$pullRequestActions));

        foreach (static::$pullRequestActions as $native => $normalized) {
            $events = $this->vcsAdapter->getEvents(
                $this->pullRequestEventFor($native),
                $this->pullRequestPayload(action: $native),
            );
            $this->assertCount(1, $events, "No event for the '{$native}' action");
            $this->assertSame($normalized, $events[0]['action'], "The '{$native}' action did not normalize to '{$normalized}'");
        }
    }

    public function testGetEventInvalidPayload(): void
    {
        $this->expectException(Exception::class);
        $this->vcsAdapter->getEvents('push', 'invalid json');
    }

    public function testGetEventUnsupportedEvent(): void
    {
        $payload = json_encode(['test' => 'data']);

        if ($payload === false) {
            $this->fail('Failed to encode JSON payload');
        }

        $result = $this->vcsAdapter->getEvents('unsupported_event', $payload);

        $this->assertEmpty($result);
    }
}
