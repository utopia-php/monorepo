<?php

declare(strict_types=1);

namespace Utopia\Queue\Exception;

final class Unsupported extends \LogicException
{
    public function __construct(
        public readonly string $capability,
    ) {
        parent::__construct("The queue connection does not support {$capability}.");
    }
}
