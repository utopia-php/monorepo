<?php

declare(strict_types=1);

namespace Utopia\NATS\ObjectStore;

final class ObjectMeta
{
    public function __construct(
        public readonly string $name,
        public readonly string $bucket,
        public readonly string $nuid,
        public readonly int $size,
        public readonly int $chunks,
        public readonly string $digest,
        public readonly ?string $description = null,
        public readonly ?string $modified = null,
        public readonly bool $deleted = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'bucket' => $this->bucket,
            'nuid' => $this->nuid,
            'size' => $this->size,
            'chunks' => $this->chunks,
            'digest' => $this->digest,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        if ($this->modified !== null) {
            $data['mtime'] = $this->modified;
        }
        if ($this->deleted) {
            $data['deleted'] = true;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            bucket: (string) ($data['bucket'] ?? ''),
            nuid: (string) ($data['nuid'] ?? ''),
            size: (int) ($data['size'] ?? 0),
            chunks: (int) ($data['chunks'] ?? 0),
            digest: (string) ($data['digest'] ?? ''),
            description: isset($data['description']) ? (string) $data['description'] : null,
            modified: isset($data['mtime']) ? (string) $data['mtime'] : null,
            deleted: (bool) ($data['deleted'] ?? false),
        );
    }
}
