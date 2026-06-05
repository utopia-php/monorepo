<?php

declare(strict_types=1);

namespace Utopia\Client;

use Psr\Http\Client\ClientInterface;

interface Adapter extends ClientInterface
{
    public function withTimeout(float $seconds): static;

    public function withConnectTimeout(float $seconds): static;
}
