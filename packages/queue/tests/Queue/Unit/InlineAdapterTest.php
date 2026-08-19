<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Adapter\Inline;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;
use Utopia\Queue\Server;

final class InlineAdapterTest extends TestCase
{
    public function testEnqueueRunsTheJobBeforeReturning(): void
    {
        $processed = [];
        $adapter = new Inline();
        $server = new Server($adapter);
        $server
            ->job('v1-mails')
            ->inject('message')
            ->action(function (Message $message) use (&$processed): void {
                $processed[] = $message->getPayload()['n'];
            });

        $server->start();

        $this->assertTrue($adapter->enqueue(new Queue('v1-mails'), ['n' => 1]));
        $this->assertTrue($adapter->enqueue(new Queue('v1-mails'), ['n' => 2]));
        $this->assertSame([1, 2], $processed);
        $this->assertSame(0, $adapter->getQueueSize(new Queue('v1-mails')));
    }

    public function testStartReturnsWithoutBlocking(): void
    {
        $adapter = new Inline();
        $server = new Server($adapter);
        $server->job('v1-mails')->action(static fn(): null => null);

        $this->assertSame($server, $server->start());
    }

    public function testEnqueueBeforeStartIsRejected(): void
    {
        $adapter = new Inline();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('start() before enqueue()');

        $adapter->enqueue(new Queue('v1-mails'), ['n' => 1]);
    }

    public function testUnknownQueueIsAcceptedWithoutRunningAJob(): void
    {
        $processed = 0;
        $adapter = new Inline();
        $server = new Server($adapter);
        $server->job('v1-mails')->action(function () use (&$processed): void {
            $processed++;
        });
        $server->start();

        $this->assertTrue($adapter->enqueue(new Queue('v1-functions'), ['n' => 1]));
        $this->assertSame(0, $processed);
    }

    public function testFailedJobDoesNotFailEnqueue(): void
    {
        $errors = [];
        $processed = [];
        $adapter = new Inline();
        $server = new Server($adapter);
        $server
            ->job('v1-mails')
            ->inject('message')
            ->action(function (Message $message): void {
                if ($message->getPayload()['ok'] === false) {
                    throw new \RuntimeException('boom');
                }
            });
        $server
            ->error()
            ->inject('error')
            ->action(function (\Throwable $error) use (&$errors): void {
                $errors[] = $error->getMessage();
            });
        $server->start();

        $this->assertTrue($adapter->enqueue(new Queue('v1-mails'), ['ok' => false]));
        $this->assertTrue($adapter->enqueue(new Queue('v1-mails'), ['ok' => true]));
        $this->assertSame(['boom'], $errors);
        $this->assertSame(0, $adapter->getQueueSize(new Queue('v1-mails'), failedJobs: true));
    }

    public function testNestedEnqueueRunsTheChildBeforeTheParentReturns(): void
    {
        $order = [];
        $adapter = new Inline();
        $server = new Server($adapter);
        $server
            ->job('v1-events')
            ->action(function () use ($adapter, &$order): void {
                $order[] = 'events';
                $adapter->enqueue(new Queue('v1-functions'), []);
                $order[] = 'events-after';
            });
        $server
            ->job('v1-functions')
            ->action(function () use (&$order): void {
                $order[] = 'functions';
            });
        $server->start();

        $adapter->enqueue(new Queue('v1-events'), []);

        $this->assertSame(['events', 'functions', 'events-after'], $order);
    }

    public function testEachJobGetsAnIsolatedContext(): void
    {
        $seen = [];
        $adapter = new Inline();
        $server = new Server($adapter);
        $server
            ->job('v1-mails')
            ->action(function () use ($server, &$seen): void {
                $seen[] = $server->context()->has('marker') ? $server->context()->get('marker') : null;
                $server->context()->set('marker', fn(): string => 'set');
            });
        $server->start();

        $adapter->enqueue(new Queue('v1-mails'), []);
        $adapter->enqueue(new Queue('v1-mails'), []);

        $this->assertSame([null, null], $seen);
    }

    public function testMultipleJobsAreArmedOnStart(): void
    {
        $processed = [];
        $adapter = new Inline();
        $server = new Server($adapter);
        $server->job('v1-mails', 1)->action(function () use (&$processed): void {
            $processed[] = 'mails';
        });
        $server->job('v1-functions', 8)->action(function () use (&$processed): void {
            $processed[] = 'functions';
        });
        $server->start();

        $adapter->enqueue(new Queue('v1-functions'), []);
        $adapter->enqueue(new Queue('v1-mails'), []);

        $this->assertSame(['functions', 'mails'], $processed);
    }

    public function testStopInvokesWorkerStopHooks(): void
    {
        $stopped = false;
        $adapter = new Inline();
        $server = new Server($adapter);
        $server->job('v1-mails')->action(static fn(): null => null);
        $server->workerStop()->action(function () use (&$stopped): void {
            $stopped = true;
        });
        $server->start();
        $server->stop();

        $this->assertTrue($stopped);
    }

    public function testRetryIsANoOp(): void
    {
        $adapter = new Inline();
        $server = new Server($adapter);
        $server->job('v1-mails')->action(static fn(): null => null);
        $server->start();

        $adapter->retry(new Queue('v1-mails'));

        $this->assertSame(0, $adapter->getQueueSize(new Queue('v1-mails')));
        $this->assertSame(0, $adapter->getQueueSize(new Queue('v1-mails'), failedJobs: true));
    }

    public function testEnqueueJsonRoundTripsPayloadLikeRedis(): void
    {
        $seen = null;
        $adapter = new Inline();
        $server = new Server($adapter);
        $server
            ->job('v1-mails')
            ->inject('message')
            ->action(function (Message $message) use (&$seen): void {
                $seen = $message->getPayload();
            });
        $server->start();

        $document = new class () implements \JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['$id' => 'proj', 'prefs' => new \stdClass()];
            }
        };

        $this->assertTrue($adapter->enqueue(new Queue('v1-mails'), [
            'project' => $document,
            'emptyObject' => new \stdClass(),
            'nested' => ['user' => $document],
        ]));

        $this->assertSame([
            'project' => ['$id' => 'proj', 'prefs' => []],
            'emptyObject' => [],
            'nested' => ['user' => ['$id' => 'proj', 'prefs' => []]],
        ], $seen);
    }
}
