<?php

declare(strict_types=1);

namespace Nats\JetStream;

enum DiscardPolicy: string
{
    case Old = 'old';
    case New = 'new';
}
