<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Validator\MockNumber;

final class MockNumberTest extends TestCase
{
    public function testValidPair(): void
    {
        $validator = new MockNumber();

        $this->assertTrue($validator->isValid([
            'phone' => '+14155552680',
            'otp' => '123456',
        ]));
    }

    public function testRejectsInvalidPayload(): void
    {
        $validator = new MockNumber();

        $this->assertFalse($validator->isValid('not-an-array'));
        $this->assertFalse($validator->isValid(['phone' => '+14155552680']));
        $this->assertFalse($validator->isValid([
            'phone' => '14155552680',
            'otp' => '123456',
        ]));
        $this->assertFalse($validator->isValid([
            'phone' => '+14155552680',
            'otp' => 'abc',
        ]));
    }
}
