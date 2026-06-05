<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class Response extends Message implements ResponseInterface
{
    public function __construct(
        private int $statusCode = 200,
        private string $reasonPhrase = '',
        ?StreamInterface $body = null,
    ) {
        $this->assertStatusCode($statusCode);

        parent::__construct(body: $body ?? new Stream());
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $this->assertStatusCode($code);

        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    private function assertStatusCode(int $statusCode): void
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('Invalid response status code.');
        }
    }
}
