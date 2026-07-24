<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

enum Result
{
    case Enqueued;
    case Existing;
}
