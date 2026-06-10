<?php

/**
 * Utopia Http
 * @package Http
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/Http
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

class IPTest extends TestCase
{
    protected IP $validator;

    public function testIsValidIP()
    {
        $validator = new IP();

        // Assertions
        $this->assertSame(true, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertSame(true, $validator->isValid('109.67.204.101'));
        $this->assertSame(false, $validator->isValid(23.5));
        $this->assertSame(false, $validator->isValid('23.5'));
        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
        $this->assertSame('string', $validator->getType());
    }

    public function testIsValidIPALL()
    {
        $validator = new IP(IP::ALL);

        // Assertions
        $this->assertSame(true, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertSame(true, $validator->isValid('109.67.204.101'));
        $this->assertSame(false, $validator->isValid(23.5));
        $this->assertSame(false, $validator->isValid('23.5'));
        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }

    public function testIsValidIPV4()
    {
        $validator = new IP(IP::V4);

        // Assertions
        $this->assertSame(false, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertSame(true, $validator->isValid('109.67.204.101'));
        $this->assertSame(false, $validator->isValid(23.5));
        $this->assertSame(false, $validator->isValid('23.5'));
        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }

    public function testIsValidIPV6()
    {
        $validator = new IP(IP::V6);

        // Assertions
        $this->assertSame(true, $validator->isValid('2001:0db8:85a3:08d3:1319:8a2e:0370:7334'));
        $this->assertSame(false, $validator->isValid('109.67.204.101'));
        $this->assertSame(false, $validator->isValid(23.5));
        $this->assertSame(false, $validator->isValid('23.5'));
        $this->assertSame(false, $validator->isValid(null));
        $this->assertSame(false, $validator->isValid(true));
        $this->assertSame(false, $validator->isValid(false));
    }
}
