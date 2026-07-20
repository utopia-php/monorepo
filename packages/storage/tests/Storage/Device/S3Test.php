<?php

declare(strict_types=1);

namespace Utopia\Tests\Storage\Device;

use Utopia\Storage\Acl;
use Utopia\Storage\Device\S3;
use Utopia\Storage\DeviceType;
use Utopia\Tests\Storage\S3Base;

final class S3Test extends S3Base
{
    private function env(string $key, string $default): string
    {
        $value = $_SERVER[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    protected function init(): void
    {
        $this->root = '/root';
        $host = $this->env('S3_HOST', 'http://utopia-storage-test.localhost:9805');
        $key = $this->env('S3_ACCESS_KEY', 'minioadmin');
        $secret = $this->env('S3_SECRET', 'minioadmin');

        $this->object = new S3($this->root, $key, $secret, $host, 'us-east-1', Acl::Private);
    }

    protected function getAdapterName(): string
    {
        return 'S3 Storage';
    }

    protected function getAdapterType(): DeviceType
    {
        return $this->object->getType();
    }

    protected function getAdapterDescription(): string
    {
        return 'S3 Storage drive for generic S3-compatible provider';
    }
}
