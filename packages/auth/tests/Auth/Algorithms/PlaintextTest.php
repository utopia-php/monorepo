<?php

namespace Utopia\Tests\Auth\Algorithms;

use PHPUnit\Framework\TestCase;
use Utopia\Auth\Hashes\Plaintext;

class PlaintextTest extends TestCase
{
    protected Plaintext $plaintext;

    protected function setUp(): void
    {
        $this->plaintext = new Plaintext();
    }

    public function testHash(): void
    {
        $password = 'test123';
        $hash = $this->plaintext->hash($password);

        $this->assertNotEmpty($hash);
        $this->assertIsString($hash);
        $this->assertEquals($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
        $this->assertFalse($this->plaintext->verify('wrongpassword', $hash));
    }

    public function testSpecialCharacters(): void
    {
        $password = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        $hash = $this->plaintext->hash($password);

        $this->assertEquals($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
    }

    public function testUnicodeCharacters(): void
    {
        $password = 'Hello 世界';
        $hash = $this->plaintext->hash($password);

        $this->assertEquals($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
    }

    public function testEmptyString(): void
    {
        $password = '';
        $hash = $this->plaintext->hash($password);

        $this->assertEquals($password, $hash);
        $this->assertTrue($this->plaintext->verify($password, $hash));
    }

    public function testGetName(): void
    {
        $this->assertEquals('plaintext', $this->plaintext->getName());
    }
}
