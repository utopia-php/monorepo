<?php

/**
 * Utopia Http
 *
 * @package Http
 * @subpackage Tests
 *
 * @link https://github.com/utopia-php/http
 * @author Appwrite Team <team@appwrite.io>
 * @version 1.0 RC4
 * @license The MIT License (MIT) <http://www.opensource.org/licenses/mit-license.php>
 */

namespace Utopia\Validator;

use PHPUnit\Framework\TestCase;

class URLTest extends TestCase
{
    protected ?URL $url;

    public function setUp(): void
    {
        $this->url = new URL();
    }

    public function tearDown(): void
    {
        $this->url = null;
    }

    public function testIsValid(): void
    {
        $this->assertSame('Value must be a valid URL', $this->url->getDescription());
        $this->assertSame(true, $this->url->isValid('http://example.com'));
        $this->assertSame(true, $this->url->isValid('https://example.com'));
        $this->assertSame(true, $this->url->isValid('htts://example.com')); // does not validate protocol
        $this->assertSame(false, $this->url->isValid('example.com')); // though, requires some kind of protocol
        $this->assertSame(false, $this->url->isValid('http:/example.com'));
        $this->assertSame(true, $this->url->isValid('http://exa-mple.com'));
        $this->assertSame(false, $this->url->isValid('htt@s://example.com'));
        $this->assertSame(true, $this->url->isValid('http://www.example.com/foo%2\u00c2\u00a9zbar'));
        $this->assertSame(true, $this->url->isValid('http://www.example.com/?q=%3Casdf%3E'));
        $this->assertSame(true, $this->url->isValid('https://example.com/callback#fragment'));
    }

    public function testIsValidAllowedSchemes(): void
    {
        $this->url = new URL(['http', 'https']);
        $this->assertSame('Value must be a valid URL with following schemes (http, https)', $this->url->getDescription());
        $this->assertSame(true, $this->url->isValid('http://example.com'));
        $this->assertSame(true, $this->url->isValid('https://example.com'));
        $this->assertSame(false, $this->url->isValid('gopher://www.example.com'));
    }

    public function testAllowEmpty(): void
    {
        $urlAllowEmpty = new URL([], true);
        $this->assertSame(true, $urlAllowEmpty->isValid(''));
        $this->assertSame(false, $urlAllowEmpty->isValid(null));
        $this->assertSame(true, $urlAllowEmpty->isValid('https://example.com'));
        $this->assertSame(false, $urlAllowEmpty->isValid('not-a-url'));

        $this->assertSame(false, $this->url->isValid(''));
        $this->assertSame(false, $this->url->isValid(null));
    }

    public function testDisallowFragments(): void
    {
        $urlWithoutFragments = new URL(allowFragments: false);

        $this->assertSame('Value must be a valid URL without a fragment component', $urlWithoutFragments->getDescription());
        $this->assertSame(true, $urlWithoutFragments->isValid('https://example.com/callback'));
        $this->assertSame(false, $urlWithoutFragments->isValid('https://example.com/callback#fragment'));
        $this->assertSame(false, $urlWithoutFragments->isValid('https://example.com/callback#'));
    }

    public function testDisallowFragmentsAllowedSchemes(): void
    {
        $urlWithoutFragments = new URL(['http', 'https'], allowFragments: false);

        $this->assertSame('Value must be a valid URL with following schemes (http, https) and without a fragment component', $urlWithoutFragments->getDescription());
        $this->assertSame(true, $urlWithoutFragments->isValid('https://example.com/callback'));
        $this->assertSame(false, $urlWithoutFragments->isValid('https://example.com/callback#fragment'));
        $this->assertSame(false, $urlWithoutFragments->isValid('gopher://www.example.com'));
    }
}
