<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * One server response: a three digit code, the text of each line, and the
 * enhanced status code of RFC 3463 when the server advertises RFC 2034.
 */
class Reply implements \Stringable
{
    public readonly ?string $status;

    /**
     * @param  list<string>  $lines  Line text, with the code and separator stripped.
     */
    public function __construct(
        public readonly int $code,
        public readonly array $lines,
    ) {
        $this->status = preg_match('/^([245]\.\d{1,3}\.\d{1,3})(?: |$)/', $lines[0] ?? '', $matches) === 1
            ? $matches[1]
            : null;
    }

    public function text(): string
    {
        return implode(' ', $this->lines);
    }

    /**
     * A 2yz completion.
     */
    public function isPositive(): bool
    {
        return $this->code >= 200 && $this->code < 300;
    }

    /**
     * A 4yz failure. The same command may succeed later.
     */
    public function isTransient(): bool
    {
        return $this->code >= 400 && $this->code < 500;
    }

    /**
     * A 5yz failure. Retrying sends the same message to the same refusal.
     */
    public function isPermanent(): bool
    {
        return $this->code >= 500 && $this->code < 600;
    }

    public function __toString(): string
    {
        return $this->code . ' ' . $this->text();
    }
}
