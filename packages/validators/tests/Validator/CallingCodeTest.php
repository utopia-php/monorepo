<?php

declare(strict_types=1);

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

final class CallingCodeTest extends TestCase
{
    public function testCanMatchKnownCodes(): void
    {
        $this->assertSame('1', CallingCode::fromPhoneNumber('+14155552680'));
        $this->assertSame('44', CallingCode::fromPhoneNumber('+447911123456'));
        $this->assertSame('55', CallingCode::fromPhoneNumber('+5511552563253'));
        $this->assertSame('1', CallingCode::fromPhoneNumber('0014155552680'));
    }

    public function testCanRejectUnknownCodes(): void
    {
        $this->assertNull(CallingCode::fromPhoneNumber('+8020000000'));
        $this->assertNull(CallingCode::fromPhoneNumber(''));
    }
}
