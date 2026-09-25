<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use RuntimeException;
use Utopia\Queue\Codec;

/**
 * Binary envelopes via the `igbinary` extension: smaller on the wire and
 * several times faster to decode than JSON, which on a queue is paid once per
 * message per delivery.
 *
 * Payloads are not JSON, so a broker configured with this codec alone cannot
 * read a message an earlier release wrote. {@see Compat} is what makes the
 * switch survivable.
 *
 * What it does not change is the shape a handler receives. igbinary preserves a
 * class where JSON flattens it, so composing it would otherwise hand ninety
 * handlers written against arrays a different type than the one they have always
 * been given -- on every message, with nothing in the package saying so. {@see Plain}
 * is applied in both directions to keep that from being a property of the format.
 */
final class Igbinary implements Codec
{
    public function __construct()
    {
        if (!\function_exists('igbinary_serialize')) {
            throw new RuntimeException('The igbinary extension is required for the Igbinary codec.');
        }
    }

    public function encode(mixed $value): string
    {
        return igbinary_serialize(Plain::of($value)) ?? throw new RuntimeException('igbinary could not serialize the value.');
    }

    public function decode(string $value): mixed
    {
        // Malformed input is reported as a warning (and null), which would be
        // indistinguishable from an encoded null; an empty string fails silently.
        if ($value === '') {
            throw new RuntimeException('Value is not an igbinary payload.');
        }

        set_error_handler(static fn(int $severity, string $message): never => throw new RuntimeException($message));
        try {
            $decoded = igbinary_unserialize($value);
        } finally {
            restore_error_handler();
        }

        // Reading matters as much as writing: bytes outlive the build that wrote them
        // -- queued, in flight, and on dead letters that have no deadline -- so a
        // message a pod that has not rolled yet published still arrives as arrays.
        return Plain::of($decoded);
    }

    public function contentType(): string
    {
        return 'application/vnd.php.igbinary';
    }
}
