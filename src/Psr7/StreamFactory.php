<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use InvalidArgumentException;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class StreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new Stream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $content = file_get_contents($filename);

        if ($content === false) {
            throw new RuntimeException('Unable to read stream file.');
        }

        unset($mode);

        return new Stream($content);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        if (!\is_resource($resource)) {
            throw new InvalidArgumentException('Expected a valid stream resource.');
        }

        $content = stream_get_contents($resource);

        if ($content === false) {
            throw new RuntimeException('Unable to read stream resource.');
        }

        return new Stream($content);
    }
}
