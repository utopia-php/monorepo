<?php

declare(strict_types=1);

namespace Utopia\Tests\Client;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Options;
use ValueError;

final class OptionsTest extends TestCase
{
    public function testItHoldsPerRequestOverrides(): void
    {
        $options = new Options(timeout: 2.5, connectTimeout: 0.5);

        $this->assertEqualsWithDelta(2.5, $options->timeout, PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.5, $options->connectTimeout, PHP_FLOAT_EPSILON);
    }

    public function testItDefaultsToNoOverrides(): void
    {
        $options = new Options();

        $this->assertNull($options->timeout);
        $this->assertNull($options->connectTimeout);
    }

    public function testItRejectsNonFiniteTimeouts(): void
    {
        $this->expectException(ValueError::class);

        new Options(timeout: \INF);
    }

    public function testItRejectsNegativeConnectTimeouts(): void
    {
        $this->expectException(ValueError::class);

        new Options(connectTimeout: -0.001);
    }
}
