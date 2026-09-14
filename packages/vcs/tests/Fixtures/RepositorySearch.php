<?php

declare(strict_types=1);

namespace Utopia\Tests\Fixtures;

use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;

trait RepositorySearch
{
    public array $requests = [];

    public function __construct(private array $responses)
    {
        parent::__construct(new Cache(new None()));
        $this->accessToken = 'token';
    }

    #[\Override]
    protected function call(string $method, string $path = '', array $headers = [], array $params = [], bool $decode = true, bool $followRedirects = true): array
    {
        $this->requests[] = $path;
        return array_shift($this->responses) ?? throw new \RuntimeException('Unexpected provider request');
    }
}
