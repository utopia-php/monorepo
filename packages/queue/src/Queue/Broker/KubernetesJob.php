<?php

namespace Utopia\Queue\Broker;

use RenokiCo\PhpK8s\K8s;
use RenokiCo\PhpK8s\Kinds\K8sJob as Manifest;
use RenokiCo\PhpK8s\KubernetesCluster;
use RenokiCo\PhpK8s\ResourcesList;
use Utopia\Queue\Message;
use Utopia\Queue\Publisher;
use Utopia\Queue\Queue;

class KubernetesJob implements Publisher
{
    private const string LABEL_PREFIX = 'queue.utopia-php.com';
    private const string ENV_MESSAGE = 'UTOPIA_QUEUE_MESSAGE';
    private const string CONTAINER_NAME = 'job';

    /**
     * @var (callable(Manifest): void)|null
     */
    protected $jobConfigHook = null;

    /**
     * @param array<string> $command Container entrypoint override.
     * @param array<string> $args Container arguments.
     * @param array<string, string> $env Additional environment variables for every job.
     */
    public function __construct(
        private readonly KubernetesCluster $cluster,
        private readonly string $image,
        private readonly string $kubernetesNamespace = 'default',
        private readonly array $command = [],
        private readonly array $args = [],
        private readonly array $env = [],
        private readonly int $backoffLimit = 0,
        private readonly int $ttlSecondsAfterFinished = 86400,
        private readonly ?string $imagePullPolicy = null,
        private readonly ?string $priorityClassName = null,
    ) {}

    /**
     * Customise the Kubernetes Job manifest before it is created.
     *
     * @param callable(Manifest): void $callback
     */
    public function configureJob(callable $callback): void
    {
        $this->jobConfigHook = $callback;
    }

    public function enqueue(Queue $queue, array $payload, bool $priority = false): bool
    {
        $message = [
            'pid' => uniqid(more_entropy: true),
            'queue' => $queue->name,
            'timestamp' => time(),
            'payload' => $payload,
        ];

        $this->buildJob($queue, $message, $priority)->create();

        return true;
    }

    public function retry(Queue $queue, ?int $limit = null): void
    {
        // No-op: per-job retries are handled natively by the Job's backoffLimit.
    }

    public function getQueueSize(Queue $queue, bool $failedJobs = false): int
    {
        $size = 0;

        foreach ($this->listJobs($queue) as $job) {
            if (!$job instanceof Manifest) {
                continue;
            }

            if ($failedJobs === $this->isFailed($job) && ($failedJobs || !$job->hasCompleted())) {
                $size++;
            }
        }

        return $size;
    }

    /**
     * Rebuilds the queue Message from the environment of the running pod.
     * Intended to be called from inside the container the Job triggers.
     */
    public static function message(): Message
    {
        $raw = getenv(self::ENV_MESSAGE);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException(\sprintf('Environment variable "%s" is not set.', self::ENV_MESSAGE));
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Invalid JSON in environment variable "%s".', self::ENV_MESSAGE));
        }

        $decoded['timestamp'] = (int) ($decoded['timestamp'] ?? 0);

        return new Message($decoded);
    }

    /**
     * @param array{pid: string, queue: string, timestamp: int, payload: array<mixed>} $message
     */
    protected function buildJob(Queue $queue, array $message, bool $priority = false): Manifest
    {
        $container = K8s::container()
            ->setName(self::CONTAINER_NAME)
            ->setAttribute('image', $this->image)
            ->setEnv(array_merge($this->env, [
                self::ENV_MESSAGE => json_encode($message, JSON_THROW_ON_ERROR),
            ]));

        if ($this->command !== []) {
            $container->setCommand($this->command);
        }

        if ($this->args !== []) {
            $container->setArgs($this->args);
        }

        if ($this->imagePullPolicy !== null) {
            $container->setImagePullPolicy($this->imagePullPolicy);
        }

        $pod = K8s::pod()
            ->setContainers([$container])
            ->neverRestart();

        if ($priority && $this->priorityClassName !== null) {
            $pod->setSpec('priorityClassName', $this->priorityClassName);
        }

        $job = K8s::job($this->cluster)
            ->setName($this->jobName($queue, $message['pid']))
            ->setNamespace($this->kubernetesNamespace)
            ->setLabels([
                self::LABEL_PREFIX . '/namespace' => $this->sanitizeLabel($queue->namespace),
                self::LABEL_PREFIX . '/queue' => $this->sanitizeLabel($queue->name),
            ])
            ->setAnnotations([
                self::LABEL_PREFIX . '/pid' => $message['pid'],
                self::LABEL_PREFIX . '/queue' => $queue->name,
                self::LABEL_PREFIX . '/timestamp' => (string) $message['timestamp'],
            ])
            ->setSpec('backoffLimit', $this->backoffLimit)
            ->setTTL($queue->jobTtl > 0 ? $queue->jobTtl : $this->ttlSecondsAfterFinished)
            ->setTemplate($pod);

        if (\is_callable($this->jobConfigHook)) {
            ($this->jobConfigHook)($job);
        }

        return $job;
    }

    private function listJobs(Queue $queue): ResourcesList
    {
        $selector = \sprintf(
            '%s/namespace=%s,%s/queue=%s',
            self::LABEL_PREFIX,
            $this->sanitizeLabel($queue->namespace),
            self::LABEL_PREFIX,
            $this->sanitizeLabel($queue->name),
        );

        return K8s::job($this->cluster)
            ->setNamespace($this->kubernetesNamespace)
            ->all(['labelSelector' => $selector]);
    }

    private function isFailed(Manifest $job): bool
    {
        // Terminal failure only: the "Failed" condition is set once backoffLimit
        // is exhausted, unlike getFailedPodsCount() which counts in-flight retries.
        foreach ($job->getConditions() as $condition) {
            if (!\is_array($condition)) {
                continue;
            }

            if (($condition['type'] ?? null) === 'Failed' && ($condition['status'] ?? null) === 'True') {
                return true;
            }
        }

        return false;
    }

    private function jobName(Queue $queue, string $pid): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($queue->name . '-' . $pid)) ?? '';
        $slug = trim($slug, '-');

        return trim(substr($slug, 0, 63), '-');
    }

    private function sanitizeLabel(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $value) ?? '';

        return trim(substr(trim($value, '-_.'), 0, 63), '-_.');
    }
}
