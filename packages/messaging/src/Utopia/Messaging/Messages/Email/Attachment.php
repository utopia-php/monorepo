<?php

namespace Utopia\Messaging\Messages\Email;

class Attachment
{
    /**
     * @param string $name  The name of the file.
     * @param string $path  The path of the file.
     * @param string $type  The MIME type of the file.
     * @param ?string $content  Raw string content of the file (used instead of path when non-null).
     */
    public function __construct(
        private string $name,
        private string $path,
        private string $type,
        private ?string $content = null,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }
}
