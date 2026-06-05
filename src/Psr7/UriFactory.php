<?php

declare(strict_types=1);

namespace Utopia\Psr7;

use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;

final class UriFactory implements UriFactoryInterface
{
    public function createUri(string $uri = ''): UriInterface
    {
        return Uri::parse($uri);
    }
}
