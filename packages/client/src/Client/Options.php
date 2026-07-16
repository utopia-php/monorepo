<?php

declare(strict_types=1);

namespace Utopia\Client;

use ValueError;

/**
 * Per-request transport overrides, passed alongside a request to
 * sendRequest()/stream(). Unlike the withX helpers, which reconfigure a clone
 * and drop its connection cache, an override applies to that one transfer only:
 * the client's configured defaults and any reused connection stay untouched.
 * A null field leaves the client's configured value in effect.
 */
final readonly class Options
{
    public function __construct(
        public ?float $timeout = null,
        public ?float $connectTimeout = null,
    ) {
        foreach ([$this->timeout, $this->connectTimeout] as $seconds) {
            if ($seconds !== null && ($seconds < 0.0 || !is_finite($seconds))) {
                throw new ValueError('Timeout must be a finite number greater than or equal to zero.');
            }
        }
    }
}
