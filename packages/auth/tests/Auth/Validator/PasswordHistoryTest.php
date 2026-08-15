<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\Validator;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Validator\PasswordHistory;

final class PasswordHistoryTest extends TestCase
{
    public function testRejectsPreviousPasswords(): void
    {
        $hash = new Argon2();
        $validator = new PasswordHistory([
            $hash->hash('old-password'),
        ], $hash);

        $this->assertFalse($validator->isValid('old-password'));
        $this->assertTrue($validator->isValid('new-password'));
    }

    public function testIgnoresEmptyHistoryEntries(): void
    {
        $hash = new Argon2();
        $validator = new PasswordHistory([''], $hash);

        $this->assertTrue($validator->isValid('any-password'));
    }
}
