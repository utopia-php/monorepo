<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * Where the bytes go, which is not the same question as what the headers say.
 *
 * A blind recipient belongs here and nowhere else: Envelope::fromMessage puts
 * it in RCPT TO, and the renderer leaves it out of the headers.
 */
class Envelope
{
    /**
     * @param  string  $sender  The reverse-path. Empty for a bounce.
     * @param  list<string>  $recipients  Forward-paths.
     */
    public function __construct(
        public readonly string $sender,
        public readonly array $recipients,
    ) {
        if ($recipients === []) {
            throw new Exception('An envelope needs at least one recipient');
        }

        foreach ([$sender, ...$recipients] as $path) {
            if (preg_match('/[\r\n\x00]/', $path) === 1) {
                throw new Exception('A path must not span lines');
            }
        }
    }

    public static function fromMessage(Message $message): self
    {
        $recipients = [];

        foreach ([...$message->to, ...$message->cc, ...$message->bcc] as $address) {
            $recipients[$address->email] = true;
        }

        return new self($message->from->email, array_keys($recipients));
    }

    /**
     * Whether any path needs the SMTPUTF8 extension of RFC 6531.
     */
    public function isInternational(): bool
    {
        foreach ([$this->sender, ...$this->recipients] as $path) {
            if (preg_match('/^[\x00-\x7F]*$/', $path) !== 1) {
                return true;
            }
        }

        return false;
    }
}
