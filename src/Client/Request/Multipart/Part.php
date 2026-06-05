<?php

declare(strict_types=1);

namespace Utopia\Client\Request\Multipart;

use RuntimeException;

final readonly class Part
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        private string $name,
        private string $body,
        private ?string $filename = null,
        private ?string $contentType = null,
        private array $headers = [],
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function field(string $name, string $value, array $headers = []): self
    {
        return new self($name, $value, headers: $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function file(string $name, string $path, ?string $filename = null, ?string $contentType = null, array $headers = []): self
    {
        $body = file_get_contents($path);

        if ($body === false) {
            throw new RuntimeException('Unable to read multipart file.');
        }

        return new self(
            $name,
            $body,
            $filename ?? basename($path),
            $contentType,
            $headers,
        );
    }

    /**
     * @param array<string, string> $headers
     */
    public static function body(string $name, string $body, ?string $filename = null, ?string $contentType = null, array $headers = []): self
    {
        return new self($name, $body, $filename, $contentType, $headers);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function bodyContent(): string
    {
        return $this->body;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
