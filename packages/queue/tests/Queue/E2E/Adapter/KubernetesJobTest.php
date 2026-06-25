<?php

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use RenokiCo\PhpK8s\K8s;
use RenokiCo\PhpK8s\Kinds\K8sJob;
use RenokiCo\PhpK8s\KubernetesCluster;
use Utopia\Queue\Broker\KubernetesJob as Broker;
use Utopia\Queue\Queue;

/**
 * Real end-to-end coverage: enqueues messages, lets the kind cluster run each
 * one as a Kubernetes Job, and asserts the Jobs reach the expected state.
 *
 * Provisioned by tests/e2e.sh (kind cluster + Tilt). Skips unless that harness
 * marks the environment ready via KUBERNETES_E2E=true.
 */
class KubernetesJobTest extends TestCase
{
    private const string LOGICAL_NAMESPACE = 'utopia-queue';
    private const string KUBERNETES_NAMESPACE = 'utopia-queue-e2e';
    private const string IMAGE = 'utopia-queue-worker:e2e';
    private const string LABEL_PREFIX = 'queue.utopia-php.com';
    private const int TIMEOUT = 120;

    private KubernetesCluster $cluster;

    /** @var array<Queue> */
    private array $queues = [];

    /** @var array<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (getenv('KUBERNETES_E2E') !== 'true') {
            $this->markTestSkipped('KubernetesJob e2e requires the kind cluster harness (KUBERNETES_E2E=true).');
        }

        $this->cluster = $this->connect();
    }

    protected function tearDown(): void
    {
        foreach ($this->queues as $queue) {
            foreach ($this->listJobs($queue) as $job) {
                try {
                    $job->delete();
                } catch (\Throwable) {
                }
            }
        }
        $this->queues = [];

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    /**
     * Connect using the active kubeconfig context, read via kubectl as JSON so
     * the suite needs no YAML extension on the host.
     */
    private function connect(): KubernetesCluster
    {
        $json = shell_exec('kubectl config view --raw --minify --output json 2>/dev/null');
        $config = json_decode((string) $json, true);
        if (!\is_array($config) || empty($config['clusters']) || empty($config['users'])) {
            $this->fail('Unable to read the active kubeconfig via kubectl.');
        }

        $cluster = $config['clusters'][0]['cluster'];
        $user = $config['users'][0]['user'];

        return KubernetesCluster::fromUrl($cluster['server'])
            ->withCaCertificate($this->writeTemp('ca', $cluster['certificate-authority-data']))
            ->withCertificate($this->writeTemp('crt', $user['client-certificate-data']))
            ->withPrivateKey($this->writeTemp('key', $user['client-key-data']));
    }

    private function writeTemp(string $suffix, string $base64): string
    {
        $path = tempnam(sys_get_temp_dir(), 'k8s-' . $suffix . '-');
        file_put_contents($path, base64_decode($base64));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function broker(): Broker
    {
        return new Broker(
            cluster: $this->cluster,
            image: self::IMAGE,
            kubernetesNamespace: self::KUBERNETES_NAMESPACE,
            ttlSecondsAfterFinished: 300,
            imagePullPolicy: 'Never',
        );
    }

    private function queue(string $name): Queue
    {
        $queue = new Queue($name . '-' . uniqid(), self::LOGICAL_NAMESPACE);
        $this->queues[] = $queue;

        return $queue;
    }

    /**
     * @return array<K8sJob>
     */
    private function listJobs(Queue $queue): array
    {
        $selector = \sprintf(
            '%s/namespace=%s,%s/queue=%s',
            self::LABEL_PREFIX,
            $queue->namespace,
            self::LABEL_PREFIX,
            $queue->name,
        );

        $jobs = [];
        foreach (K8s::job($this->cluster)->setNamespace(self::KUBERNETES_NAMESPACE)->all(['labelSelector' => $selector]) as $job) {
            if ($job instanceof K8sJob) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    private function completedCount(Queue $queue): int
    {
        $completed = 0;
        foreach ($this->listJobs($queue) as $job) {
            if ($job->hasCompleted()) {
                $completed++;
            }
        }

        return $completed;
    }

    private function waitUntil(callable $condition, string $message): void
    {
        $deadline = time() + self::TIMEOUT;
        while (time() < $deadline) {
            if ($condition()) {
                return;
            }
            sleep(2);
        }

        $this->fail('Timed out after ' . self::TIMEOUT . "s waiting for: {$message}");
    }

    public function testJobsRunToCompletion(): void
    {
        $broker = $this->broker();
        $queue = $this->queue('e2e-success');

        $payloads = [
            ['type' => 'test_string', 'value' => 'lorem ipsum'],
            ['type' => 'test_number', 'value' => 123],
            ['type' => 'test_assoc', 'value' => ['string' => 'ipsum', 'number' => 123, 'bool' => true, 'null' => null]],
        ];

        foreach ($payloads as $payload) {
            $this->assertTrue($broker->enqueue($queue, $payload));
        }

        $expected = \count($payloads);
        $this->waitUntil(
            fn(): bool => $this->completedCount($queue) === $expected,
            "all {$expected} jobs to complete",
        );

        $this->assertSame($expected, $this->completedCount($queue));
        $this->assertSame(0, $broker->getQueueSize($queue, failedJobs: true), 'no jobs should have failed');
        $this->assertSame(0, $broker->getQueueSize($queue), 'no jobs should remain pending');
    }

    public function testFailingJobIsMarkedFailed(): void
    {
        $broker = $this->broker();
        $queue = $this->queue('e2e-failure');

        $this->assertTrue($broker->enqueue($queue, ['type' => 'test_exception']));

        $this->waitUntil(
            fn(): bool => $broker->getQueueSize($queue, failedJobs: true) >= 1,
            'the job to be marked failed',
        );

        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true));
    }
}
