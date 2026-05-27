<?php

declare(strict_types=1);

namespace Nats\JetStream;

enum ReplayPolicy: string
{
    case Instant = 'instant';
    case Original = 'original';
}
