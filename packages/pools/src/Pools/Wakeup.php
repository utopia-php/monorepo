<?php

declare(strict_types=1);

namespace Utopia\Pools;

/** A capacity change, distinct from an idle resource or an expired wait. */
enum Wakeup
{
    case Capacity;
}
