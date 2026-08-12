<?php

declare(strict_types=1);

namespace Utopia\Http\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Utopia\Http\Adapter\Swoole\Request;

#[RequiresPhpExtension('swoole')]
final class SwooleRequestTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bodies(): array
    {
        return [
            'bare empty object' => ['{"data":{}}', '{"data":{}}'],
            'nested empty object' => ['{"data":{"inner":{}}}', '{"data":{"inner":{}}}'],
            'empty object beside a value' => ['{"data":{"a":{},"b":1}}', '{"data":{"a":{},"b":1}}'],
            'empty object two levels down' => ['{"data":{"l1":{"l2":{}}}}', '{"data":{"l1":{"l2":{}}}}'],
            'empty object inside a list' => ['{"data":{"arr":[{},{"x":1}]}}', '{"data":{"arr":[{},{"x":1}]}}'],
            'populated object' => ['{"data":{"a":1}}', '{"data":{"a":1}}'],
            'empty object as the only param' => ['{"data":{},"other":{}}', '{"data":{},"other":{}}'],
            'braces inside a string literal' => ['{"data":{"note":"braces {} in text"}}', '{"data":{"note":"braces {} in text"}}'],
            'whitespace inside the empty object' => ['{"data":{"a":{ }}}', '{"data":{"a":{}}}'],
            'newline inside the empty object' => ["{\"data\":{\"a\":{\n}}}", '{"data":{"a":{}}}'],
            'empty list stays a list' => ['{"data":{"a":[]}}', '{"data":{"a":[]}}'],
        ];
    }

    /**
     * A param holding an empty JSON object has to re-encode as the object that was
     * sent. `json_decode($body, true)` returns `[]` for both `{}` and `[]`, so an
     * empty object silently became an empty array in every stored payload.
     */
    #[DataProvider('bodies')]
    public function testJSONBodyRoundTrips(string $body, string $expected): void
    {
        $request = new Request($this->swooleRequest($body));

        $this->assertSame(
            $expected,
            json_encode($request->getParams()),
            'the decoded params must re-encode as the body that was sent',
        );
    }

    public function testPopulatedObjectsStayAssociativeArrays(): void
    {
        $request = new Request($this->swooleRequest('{"data":{"a":{},"b":{"c":1}}}'));

        $data = $request->getPayload('data');

        $this->assertIsArray($data);
        $this->assertInstanceOf(\stdClass::class, $data['a']);
        $this->assertIsArray($data['b'], 'a populated object must stay an associative array');
        $this->assertSame(['c' => 1], $data['b']);
    }

    public function testBodyWithoutParamsDecodesToNoParams(): void
    {
        foreach (['{}', '{ }', '[]', '', 'not json', '"a string"', '5'] as $body) {
            $request = new Request($this->swooleRequest($body));

            $this->assertSame([], $request->getParams(), 'body ' . $body . ' carries no params');
        }
    }

    public function testFormBodyIsUnaffected(): void
    {
        $swoole = $this->swooleRequest('data=value', 'application/x-www-form-urlencoded');

        $this->assertSame(['data' => 'value'], (new Request($swoole))->getParams());
    }

    private function swooleRequest(string $body, string $contentType = 'application/json'): SwooleRequest
    {
        $request = SwooleRequest::create(['parse_cookie' => false, 'parse_files' => false]);
        $request->parse(
            "POST /v1/documents HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . 'Content-Type: ' . $contentType . "\r\n"
            . 'Content-Length: ' . \strlen($body) . "\r\n"
            . "\r\n"
            . $body,
        );

        return $request;
    }
}
