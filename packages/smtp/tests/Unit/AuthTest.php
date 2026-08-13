<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Auth\Login;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Auth\XOAuth2;

final class AuthTest extends TestCase
{
    public function testPlainSendsBothCredentialsAtOnce(): void
    {
        $this->assertSame("\0jane\0secret", (new Plain('jane', 'secret'))->initial());
    }

    public function testLoginWaitsToBeAsked(): void
    {
        $login = new Login('jane', 'secret');

        $this->assertNull($login->initial());
        $this->assertSame('jane', $login->respond('Username:'));
        $this->assertSame('secret', $login->respond('Password:'));
    }

    public function testLoginForgetsTheLastExchange(): void
    {
        $login = new Login('jane', 'secret');

        $login->initial();
        $login->respond('Username:');
        $login->respond('Password:');

        // An authenticator outlives the connection it was built for. Without a
        // reset, the reconnect answers the first prompt with the password and
        // perfectly good credentials fail.
        $login->initial();

        $this->assertSame('jane', $login->respond('Username:'));
        $this->assertSame('secret', $login->respond('Password:'));
    }

    public function testXOAuth2CarriesABearerToken(): void
    {
        $this->assertSame(
            "user=jane@example.test\1auth=Bearer token-value\1\1",
            (new XOAuth2('jane@example.test', 'token-value'))->initial(),
        );
    }

    public function testXOAuth2EndsTheExchangeOnAChallenge(): void
    {
        // A challenge here is the server explaining the refusal. Answering with
        // nothing closes the exchange so the failure surfaces as one.
        $this->assertSame('', (new XOAuth2('jane@example.test', 'token'))->respond('{"status":"401"}'));
    }
}
