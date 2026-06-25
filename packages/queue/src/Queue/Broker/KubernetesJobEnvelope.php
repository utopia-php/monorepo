<?php

namespace Utopia\Queue\Broker;

use Utopia\Queue\Message;

/**
 * Wire contract for a queue message handed to a Kubernetes Job's pod through an
 * environment variable. KubernetesJob (the publisher) writes it; the worker the
 * Job runs reads it back with read() — neither side needs the other's class.
 */
final class KubernetesJobEnvelope
{
    public const string ENV = 'UTOPIA_QUEUE_MESSAGE';

    /**
     * Rebuild the Message from the running pod's environment.
     */
    public static function read(): Message
    {
        $raw = getenv(self::ENV);
        if ($raw === false || $raw === '') {
            throw new \RuntimeException(\sprintf('Environment variable "%s" is not set.', self::ENV));
        }

        $decoded = json_decode($raw, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf('Invalid JSON in environment variable "%s".', self::ENV));
        }

        $decoded['timestamp'] = (int) ($decoded['timestamp'] ?? 0);

        return new Message($decoded);
    }
}
