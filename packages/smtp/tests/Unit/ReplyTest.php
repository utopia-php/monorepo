<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Reply;

final class ReplyTest extends TestCase
{
    public function testReadsTheEnhancedStatusCode(): void
    {
        $this->assertSame('2.1.5', (new Reply(250, ['2.1.5 Recipient ok']))->status);
    }

    public function testLeavesTheStatusNullWhenThereIsNone(): void
    {
        $this->assertNull((new Reply(250, ['Recipient ok']))->status);
    }

    public function testDoesNotMistakeAVersionForAStatus(): void
    {
        $this->assertNull((new Reply(220, ['mail.example.test ESMTP Postfix 3.7.1']))->status);
    }

    public function testClassifiesByRange(): void
    {
        $this->assertTrue((new Reply(250, ['Ok']))->isPositive());
        $this->assertTrue((new Reply(451, ['Try later']))->isTransient());
        $this->assertTrue((new Reply(550, ['No such user']))->isPermanent());
        $this->assertFalse((new Reply(451, ['Try later']))->isPermanent());
    }

    public function testJoinsContinuationLines(): void
    {
        $this->assertSame('one two', (new Reply(250, ['one', 'two']))->text());
    }
}
