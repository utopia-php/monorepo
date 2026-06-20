<?php

namespace Utopia\Replication\Tests\Unit\Source\MySQL;

use PHPUnit\Framework\TestCase;
use Utopia\Replication\Source\MySQL\BinaryReader;

class BinaryReaderTest extends TestCase
{
    public function testFixedWidthIntegersAreLittleEndian(): void
    {
        $reader = new BinaryReader("\x01\x02\x00\x03\x00\x00\x00");

        $this->assertSame(1, $reader->readUInt8());
        $this->assertSame(2, $reader->readUInt16());
        $this->assertSame(3, $reader->readUInt32());
        $this->assertTrue($reader->eof());
    }

    public function testLengthEncodedInteger(): void
    {
        // 0xFA inline, 0xFC + 2 bytes, 0xFB => NULL.
        $reader = new BinaryReader("\xFA\xFC\x00\x01\xFB");

        $this->assertSame(0xFA, $reader->readLengthEncodedInt());
        $this->assertSame(256, $reader->readLengthEncodedInt());
        $this->assertNull($reader->readLengthEncodedInt());
    }

    public function testLengthEncodedString(): void
    {
        $reader = new BinaryReader("\x05hello\xFB");

        $this->assertSame('hello', $reader->readLengthEncodedString());
        $this->assertNull($reader->readLengthEncodedString());
    }

    public function testNullTerminatedString(): void
    {
        $reader = new BinaryReader("appwrite\x00rest");

        $this->assertSame('appwrite', $reader->readNullTerminatedString());
        $this->assertSame('rest', $reader->read($reader->remaining()));
    }

    public function testSkipAdvancesCursor(): void
    {
        $reader = new BinaryReader("\xAA\xBB\xCC");
        $reader->skip(2);

        $this->assertSame(0xCC, $reader->readUInt8());
    }

    public function testWideAndOddWidthIntegers(): void
    {
        // 8-byte, then a 3-byte, then a 6-byte little-endian read.
        $reader = new BinaryReader("\x01\x00\x00\x00\x00\x00\x00\x00" . "\x00\x01\x00" . "\xFF\x00\x00\x00\x00\x00");

        $this->assertSame(1, $reader->readUInt64());
        $this->assertSame(256, $reader->readUInt(3));
        $this->assertSame(255, $reader->readUInt(6));
        $this->assertTrue($reader->eof());
    }

    public function testLengthEncodedThreeAndEightByteForms(): void
    {
        // 0xFD => 3-byte, 0xFE => 8-byte.
        $reader = new BinaryReader("\xFD\x01\x00\x00" . "\xFE\x02\x00\x00\x00\x00\x00\x00\x00");

        $this->assertSame(1, $reader->readLengthEncodedInt());
        $this->assertSame(2, $reader->readLengthEncodedInt());
    }

    public function testRemainingAndPositionTrackConsumption(): void
    {
        $reader = new BinaryReader("\x01\x02\x03\x04");

        $this->assertSame(0, $reader->position());
        $this->assertSame(4, $reader->remaining());

        $reader->read(3);
        $this->assertSame(3, $reader->position());
        $this->assertSame(1, $reader->remaining());
        $this->assertFalse($reader->eof());
    }

    public function testReadOfZeroOrNegativeBytesReturnsEmpty(): void
    {
        $reader = new BinaryReader("\xAA");

        $this->assertSame('', $reader->read(0));
        $this->assertSame('', $reader->read(-5));
        $this->assertSame(0, $reader->position()); // cursor untouched
        $this->assertSame(0xAA, $reader->readUInt8());
    }

    public function testNullTerminatedStringWithoutTerminatorReadsToEnd(): void
    {
        $reader = new BinaryReader('no-terminator');

        $this->assertSame('no-terminator', $reader->readNullTerminatedString());
        $this->assertTrue($reader->eof());
    }

    public function testLongLengthEncodedString(): void
    {
        $value = str_repeat('x', 300);
        // 0xFC marks a 2-byte length prefix.
        $reader = new BinaryReader("\xFC" . pack('v', 300) . $value);

        $this->assertSame($value, $reader->readLengthEncodedString());
    }
}
