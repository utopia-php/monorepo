<?php

declare(strict_types=1);

namespace Utopia\SMTP\Mime;

/**
 * The encodings a message needs, chosen for the caller rather than configured
 * by them: quoted-printable for text, base64 for attachments, and the encoded
 * words of RFC 2047 for headers that are not plain ASCII.
 */
class Encoding
{
    /** Base64 wraps at 76 characters, which is 57 bytes of input. */
    public const CHUNK = 57;

    public static function isAscii(string $value): bool
    {
        return preg_match('/^[\x00-\x7F]*$/', $value) === 1;
    }

    /**
     * A display name, as it appears before an angle address.
     */
    public static function phrase(string $value): string
    {
        if (! self::isAscii($value)) {
            return self::encodedWord($value);
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    /**
     * The value of a free-form header such as Subject.
     */
    public static function unstructured(string $value): string
    {
        return self::isAscii($value) ? $value : self::encodedWord($value);
    }

    public static function quotedPrintable(string $value): string
    {
        return quoted_printable_encode(self::lineEndings($value));
    }

    public static function base64(string $value): string
    {
        return chunk_split(base64_encode($value), 76, "\r\n");
    }

    /**
     * Every line ending, whatever it started as, becomes CRLF.
     */
    public static function lineEndings(string $value): string
    {
        return preg_replace('/\r\n|\r|\n/', "\r\n", $value) ?? $value;
    }

    public static function boundary(): string
    {
        return '=_' . bin2hex(random_bytes(16));
    }

    /**
     * RFC 2047. Base64 throughout: it costs a third more than quoted-printable
     * on Latin text and half as much on everything else.
     */
    private static function encodedWord(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
