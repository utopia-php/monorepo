<?php

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use RenokiCo\PhpK8s\KubernetesCluster;
use Utopia\Queue\Broker\KubernetesJob;
use Utopia\Queue\Queue;

class KubernetesJobManifestTest extends TestCase
{
    private function broker(
        string $namespace = 'default',
        array $command = [],
        array $args = [],
        array $env = [],
        int $backoffLimit = 0,
        int $ttl = 86400,
        ?string $imagePullPolicy = null,
        ?string $priorityClassName = null,
    ) {
        return new class (
            new KubernetesCluster('https://localhost:6443'),
            'registry.example.com:5000/appwrite/worker:1.0',
            $namespace,
            $command,
            $args,
            $env,
            $backoffLimit,
            $ttl,
            $imagePullPolicy,
            $priorityClassName,
        ) extends KubernetesJob {
            public function build(Queue $queue, array $message, bool $priority = false): array
            {
                return $this->buildJob($queue, $message, $priority)->toArray();
            }
        };
    }

    private function message(string $pid = '64a1b2c3.999', string $queue = 'my-queue', array $payload = ['type' => 'test_string', 'value' => 'lorem']): array
    {
        return [
            'pid' => $pid,
            'queue' => $queue,
            'timestamp' => 1700000000,
            'payload' => $payload,
        ];
    }

    private function findEnv(array $manifest, string $name): ?string
    {
        $envs = $manifest['spec']['template']['spec']['containers'][0]['env'] ?? [];
        foreach ($envs as $env) {
            if (($env['name'] ?? null) === $name) {
                return $env['value'] ?? null;
            }
        }

        return null;
    }

    public function testManifestStructure(): void
    {
        $manifest = $this->broker('queues', backoffLimit: 3, ttl: 3600)
            ->build(new Queue('my-queue', 'utopia-queue'), $this->message());

        $this->assertSame('Job', $manifest['kind']);
        $this->assertSame('batch/v1', $manifest['apiVersion']);
        $this->assertSame('queues', $manifest['metadata']['namespace']);
        $this->assertSame('registry.example.com:5000/appwrite/worker:1.0', $manifest['spec']['template']['spec']['containers'][0]['image']);
        $this->assertSame('Never', $manifest['spec']['template']['spec']['restartPolicy']);
        $this->assertSame(3, $manifest['spec']['backoffLimit']);
        $this->assertSame(3600, $manifest['spec']['ttlSecondsAfterFinished']);
    }

    public function testLabelsAndAnnotations(): void
    {
        $manifest = $this->broker()
            ->build(new Queue('my-queue', 'utopia-queue'), $this->message());

        $labels = $manifest['metadata']['labels'];
        $this->assertSame('utopia-queue', $labels['queue.utopia-php.com/namespace']);
        $this->assertSame('my-queue', $labels['queue.utopia-php.com/queue']);

        $annotations = $manifest['metadata']['annotations'];
        $this->assertSame('64a1b2c3.999', $annotations['queue.utopia-php.com/pid']);
        $this->assertSame('my-queue', $annotations['queue.utopia-php.com/queue']);
        $this->assertSame('1700000000', $annotations['queue.utopia-php.com/timestamp']);
    }

    public function testJobNameIsDnsSafe(): void
    {
        $manifest = $this->broker()
            ->build(new Queue('My_Weird.Queue!', 'utopia-queue'), $this->message('64A1.B2C3.XYZ'));

        $name = $manifest['metadata']['name'];

        $this->assertLessThanOrEqual(63, \strlen($name));
        $this->assertSame(1, preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $name), "Job name '{$name}' is not a valid DNS-1123 label");
    }

    public function testMessageIsCarriedInEnvironment(): void
    {
        $message = $this->message();
        $manifest = $this->broker()
            ->build(new Queue('my-queue', 'utopia-queue'), $message);

        $raw = $this->findEnv($manifest, 'UTOPIA_QUEUE_MESSAGE');
        $this->assertNotNull($raw);
        $this->assertSame($message, json_decode((string) $raw, true));
    }

    public function testCustomEnvironmentIsMerged(): void
    {
        $manifest = $this->broker(env: ['FOO' => 'bar'])
            ->build(new Queue('my-queue', 'utopia-queue'), $this->message());

        $this->assertSame('bar', $this->findEnv($manifest, 'FOO'));
        $this->assertNotNull($this->findEnv($manifest, 'UTOPIA_QUEUE_MESSAGE'));
    }

    public function testReservedEnvironmentCannotBeOverridden(): void
    {
        $manifest = $this->broker(env: ['UTOPIA_QUEUE_MESSAGE' => 'tampered'])
            ->build(new Queue('my-queue', 'utopia-queue'), $this->message());

        $this->assertNotSame('tampered', $this->findEnv($manifest, 'UTOPIA_QUEUE_MESSAGE'));
    }

    public function testCommandArgsAndPullPolicy(): void
    {
        $defaults = $this->broker()
            ->build(new Queue('my-queue', 'utopia-queue'), $this->message());
        $this->assertArrayNotHasKey('command', $defaults['spec']['template']['spec']['containers'][0]);
        $this->assertArrayNotHasKey('args', $defaults['spec']['template']['spec']['containers'][0]);
        $this->assertArrayNotHasKey('imagePullPolicy', $defaults['spec']['template']['spec']['containers'][0]);

        $custom = $this->broker(command: ['php', 'worker.php'], args: ['--queue', 'my-queue'], imagePullPolicy: 'Never')
            ->build(new Queue('my-queue', 'utopia-queue'), $this->message());
        $container = $custom['spec']['template']['spec']['containers'][0];
        $this->assertSame(['php', 'worker.php'], $container['command']);
        $this->assertSame(['--queue', 'my-queue'], $container['args']);
        $this->assertSame('Never', $container['imagePullPolicy']);
    }

    public function testQueueJobTtlOverridesDefault(): void
    {
        $manifest = $this->broker(ttl: 86400)
            ->build(new Queue('my-queue', 'utopia-queue', jobTtl: 120), $this->message());

        $this->assertSame(120, $manifest['spec']['ttlSecondsAfterFinished']);
    }

    public function testPriorityClassNameAppliedOnlyWhenPrioritised(): void
    {
        $broker = $this->broker(priorityClassName: 'high');

        $normal = $broker->build(new Queue('my-queue', 'utopia-queue'), $this->message());
        $this->assertArrayNotHasKey('priorityClassName', $normal['spec']['template']['spec']);

        $prioritised = $broker->build(new Queue('my-queue', 'utopia-queue'), $this->message(), priority: true);
        $this->assertSame('high', $prioritised['spec']['template']['spec']['priorityClassName']);
    }

    public function testConfigureJobHook(): void
    {
        $broker = $this->broker();
        $broker->configureJob(function ($job) {
            $job->setSpec('activeDeadlineSeconds', 120);
        });

        $manifest = $broker->build(new Queue('my-queue', 'utopia-queue'), $this->message());

        $this->assertSame(120, $manifest['spec']['activeDeadlineSeconds']);
    }

    public function testMessageHelperRoundTrip(): void
    {
        $message = $this->message();
        putenv('UTOPIA_QUEUE_MESSAGE=' . json_encode($message));

        try {
            $rebuilt = KubernetesJob::message();
            $this->assertSame($message['pid'], $rebuilt->getPid());
            $this->assertSame($message['queue'], $rebuilt->getQueue());
            $this->assertSame($message['timestamp'], $rebuilt->getTimestamp());
            $this->assertSame($message['payload'], $rebuilt->getPayload());
        } finally {
            putenv('UTOPIA_QUEUE_MESSAGE');
        }
    }

    public function testMessageHelperThrowsWhenMissing(): void
    {
        putenv('UTOPIA_QUEUE_MESSAGE');

        $this->expectException(\RuntimeException::class);
        KubernetesJob::message();
    }
}
