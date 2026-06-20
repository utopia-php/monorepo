<?php

declare(strict_types=1);

namespace Utopia\Fastly\Exception;

use RuntimeException;

final class PurgeException extends RuntimeException
{
    public function __construct(
        public readonly string $surrogateKey,
        public readonly int $statusCode,
        string $body = '',
    ) {
        $detail = trim("status {$statusCode} " . mb_substr($body, 0, 2048));

        parent::__construct("Fastly purge failed for {$surrogateKey}: {$detail}");
    }
}
