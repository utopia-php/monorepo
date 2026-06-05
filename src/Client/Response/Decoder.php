<?php

declare(strict_types=1);

namespace Utopia\Client\Response;

use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Utopia\Client\Response\Multipart\Part;
use ValueError;

final class Decoder
{
    /**
     * @throws JsonException
     */
    public function json(ResponseInterface $response, bool $associative = true, int $depth = 512, int $flags = 0): mixed
    {
        if ($depth < 1) {
            throw new ValueError('JSON decode depth must be greater than zero.');
        }

        return json_decode((string) $response->getBody(), $associative, $depth, $flags | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function form(ResponseInterface $response): array
    {
        parse_str((string) $response->getBody(), $data);

        return $data;
    }

    /**
     * @return array<int, Part>
     */
    public function multipart(ResponseInterface $response): array
    {
        $boundary = $this->boundary($response);
        $body = (string) $response->getBody();
        $segments = $this->segments($body, $boundary);
        $parts = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $parts[] = $this->part($segment);
        }

        return $parts;
    }

    private function boundary(ResponseInterface $response): string
    {
        $contentType = $response->getHeaderLine('Content-Type');

        if (preg_match('/(?:^|;\s*)boundary=(?:"(?P<quoted>[^"]+)"|(?P<plain>[^;\s]+))/', $contentType, $matches) !== 1) {
            throw new InvalidArgumentException('Multipart response is missing a boundary.');
        }

        return $matches['quoted'] !== '' ? $matches['quoted'] : $matches['plain'];
    }

    /**
     * @return array<int, string>
     */
    private function segments(string $body, string $boundary): array
    {
        $pattern = '/(^|\r\n|\n|\r)--' . preg_quote($boundary, '/') . '(--)?[ \t]*(?:\r\n|\n|\r|$)/';

        if (preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $segments = [];
        $previousEnd = null;

        foreach ($matches as $match) {
            $delimiterOffset = $match[0][1];

            if ($previousEnd !== null) {
                $segments[] = substr($body, $previousEnd, $delimiterOffset - $previousEnd);
            }

            $previousEnd = $delimiterOffset + \strlen($match[0][0]);

            if (isset($match[2]) && $match[2][0] === '--') {
                break;
            }
        }

        return $segments;
    }

    private function part(string $segment): Part
    {
        [$rawHeaders, $body] = $this->splitPart($segment);

        return new Part($this->headers($rawHeaders), $body);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitPart(string $segment): array
    {
        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $separator) {
            $position = strpos($segment, $separator);

            if ($position !== false) {
                return [
                    substr($segment, 0, $position),
                    substr($segment, $position + \strlen($separator)),
                ];
            }
        }

        return ['', $segment];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function headers(string $rawHeaders): array
    {
        $headers = [];
        $lines = preg_split("/\r\n|\n|\r/", $rawHeaders);

        foreach ($lines === false ? [] : $lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = trim($name);

            if ($name !== '') {
                $headers[$name][] = trim($value);
            }
        }

        return $headers;
    }
}
