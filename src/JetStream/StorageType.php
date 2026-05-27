<?php

declare(strict_types=1);

namespace Nats\JetStream;

enum StorageType: string
{
    case File = 'file';
    case Memory = 'memory';
}
