<?php

namespace Utopia\Tests\Auth\Algorithms;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\ScryptModified;

class ScryptModifiedTest extends TestCase
{
    protected ScryptModified $scryptModified;

    protected function setUp(): void
    {
        $this->scryptModified = new ScryptModified();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->scryptModified->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertIsString($hash);
        $this->assertTrue($this->scryptModified->verify($password, $hash));
        $this->assertFalse($this->scryptModified->verify('wrongpassword', $hash));
    }

    public function testCustomOptions(): void
    {
        $this->scryptModified->setSalt(base64_encode('custom-salt'))
            ->setSaltSeparator(base64_encode('custom-separator'))
            ->setSignerKey(base64_encode('custom-signer-key'));

        $password = 'test123';
        $hash = $this->scryptModified->hash($password);

        $this->assertTrue($this->scryptModified->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertEquals('scryptMod', $this->scryptModified->getName());
    }
}
