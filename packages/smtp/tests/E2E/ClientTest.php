<?php

declare(strict_types=1);

namespace Utopia\SMTP\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Auth\Login;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\AuthenticationException;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Envelope;
use Utopia\SMTP\Message;
use Utopia\SMTP\Outcome;
use Utopia\SMTP\Tls;
use Utopia\SMTP\TransactionException;
use Utopia\SMTP\Transport\Native;
use Utopia\SMTP\Verification;

/**
 * Against a real server, which is the only way to find out whether the bytes
 * this library writes are the bytes a server expects to read.
 */
final class ClientTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const int PORT = 11026;

    private const string API = 'http://127.0.0.1:18026';

    protected function setUp(): void
    {
        // Answers with an empty body, so it is not read as an object.
        $this->get('DELETE', '/api/v1/messages');
    }

    /**
     * The server presents the self-signed pair in tests/fixtures/certs. No
     * trust store knows the issuer, but the certificate does name the host we
     * dial, so that half is still checked.
     */
    private function client(Encryption $encryption = Encryption::StartTls, string ...$credentials): Client
    {
        $authenticators = $credentials === [] ? [] : [new Plain(...$credentials)];

        return new Client(
            new Native(self::HOST, self::PORT, new Tls(verify: Verification::SelfSigned)),
            'tests.example.test',
            $authenticators,
            $encryption,
        );
    }

    private function get(string $method, string $path): string
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true]]);
        $body = file_get_contents(self::API . $path, false, $context);

        if ($body === false) {
            $this->fail("Mailpit did not answer {$method} {$path}");
        }

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function api(string $method, string $path): array
    {
        $decoded = json_decode($this->get($method, $path), true);

        $this->assertIsArray($decoded, "Mailpit answered {$path} with something other than an object");

        return $decoded;
    }

    /**
     * A field the API promises is a string.
     *
     * @param  array<mixed>  $message
     */
    private function field(array $message, string $key): string
    {
        $this->assertArrayHasKey($key, $message);
        $this->assertIsString($message[$key]);

        return $message[$key];
    }

    /**
     * A field the API promises is a list.
     *
     * @param  array<mixed>  $message
     * @return array<mixed>
     */
    private function listOf(array $message, string $key): array
    {
        $this->assertArrayHasKey($key, $message);
        $this->assertIsArray($message[$key]);

        return $message[$key];
    }

    private function id(): string
    {
        $messages = $this->listOf($this->api('GET', '/api/v1/messages'), 'messages');

        $this->assertCount(1, $messages, 'expected exactly one delivered message');
        $this->assertIsArray($messages[0]);

        return $this->field($messages[0], 'ID');
    }

    /**
     * @return array<mixed>
     */
    private function delivered(): array
    {
        return $this->api('GET', '/api/v1/message/' . $this->id());
    }

    /**
     * The bytes as they arrived, before the server interprets them.
     */
    private function source(): string
    {
        return $this->get('GET', '/api/v1/message/' . $this->id() . '/raw');
    }

    public function testUpgradesThroughStartTlsAndDelivers(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'secret');

        $result = $client->send(new Message(
            from: new Address('jane@example.test', 'Jane Doe'),
            to: [new Address('john@example.test')],
            subject: 'Hello from Utopia',
            text: 'Plain body',
        ));

        $client->close();

        $this->assertTrue($result->isComplete());
        $this->assertSame(['john@example.test'], $result->accepted);

        $delivered = $this->delivered();
        $this->assertSame('Hello from Utopia', $this->field($delivered, 'Subject'));
        $this->assertStringContainsString('Plain body', $this->field($delivered, 'Text'));
    }

    public function testAdvertisesTheExtensionsWeRelyOn(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'secret');
        $capabilities = $client->capabilities();

        $this->assertTrue($capabilities->has('AUTH'));
        $this->assertTrue($capabilities->has('ENHANCEDSTATUSCODES'));
        $this->assertContains('PLAIN', $capabilities->mechanisms());
        $this->assertContains('LOGIN', $capabilities->mechanisms());

        // The server advertises "SIZE 0", which means it sets no fixed maximum.
        $this->assertNull($capabilities->maxSize());

        // STARTTLS is gone from the reply read after the upgrade, which is how
        // we know the capabilities were rebuilt rather than reused.
        $this->assertFalse($capabilities->has('STARTTLS'));

        $client->close();
    }

    public function testSendsWithoutEncryptionWhenAsked(): void
    {
        $client = $this->client(Encryption::None, 'jane', 'secret');

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Plaintext',
            text: 'Body',
        ));

        $client->close();

        $this->assertSame('Plaintext', $this->field($this->delivered(), 'Subject'));
    }

    public function testLoginMechanismAlsoAuthenticates(): void
    {
        $client = new Client(
            new Native(self::HOST, self::PORT, new Tls(verify: Verification::SelfSigned)),
            'tests.example.test',
            [new Login('jane', 'secret')],
            Encryption::StartTls,
        );

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Login',
            text: 'Body',
        ));

        $client->close();

        $this->assertSame('Login', $this->field($this->delivered(), 'Subject'));
    }

    public function testRefusesBadCredentials(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'wrong');

        $this->expectException(AuthenticationException::class);

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Nope',
            text: 'Body',
        ));
    }

    public function testDeliversToEveryRecipientIncludingBlindOnes(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'secret');

        $result = $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Wide',
            text: 'Body',
            cc: [new Address('ada@example.test')],
            bcc: [new Address('eve@example.test')],
        ));

        $client->close();

        $this->assertSame(
            ['john@example.test', 'ada@example.test', 'eve@example.test'],
            $result->accepted,
        );

        $delivered = $this->delivered();
        $this->assertCount(1, $this->listOf($delivered, 'To'));
        $this->assertCount(1, $this->listOf($delivered, 'Cc'));

        // All three were delivered, but the blind one reached the server only
        // through RCPT TO. Mailpit records that by prepending its own Bcc,
        // Return-Path and Received fields, so the claim is about what we wrote:
        // everything from the Date header down.
        $source = $this->source();
        $ours = substr($source, (int) strpos($source, 'Date: '));

        $this->assertStringContainsString('Bcc: eve@example.test', $source, 'the server saw the blind recipient');
        $this->assertStringNotContainsString('eve@example.test', $ours, 'and we did not put it in a header');
        $this->assertStringNotContainsString('Bcc', $ours);
    }

    public function testCarriesAnAttachmentThroughIntact(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'secret');

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'With a file',
            text: 'See attached',
            html: '<p>See attached</p>',
            attachments: [Attachment::fromString('the file body', 'notes.txt', 'text/plain')],
        ));

        $client->close();

        $delivered = $this->delivered();
        $attachments = $this->listOf($delivered, 'Attachments');

        $this->assertCount(1, $attachments);
        $this->assertIsArray($attachments[0]);
        $this->assertSame('notes.txt', $this->field($attachments[0], 'FileName'));
        $this->assertStringContainsString('See attached', $this->field($delivered, 'HTML'));
    }

    public function testStuffsADotThatWouldOtherwiseEndTheMessage(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'secret');

        $client->send(new Message(
            from: new Address('jane@example.test'),
            to: [new Address('john@example.test')],
            subject: 'Transparency',
            text: "before\r\n.\r\nafter",
        ));

        $client->close();

        // Without stuffing the server would have stopped reading at the dot and
        // the second half would be gone.
        $this->assertStringContainsString('after', $this->field($this->delivered(), 'Text'));
    }

    public function testReportsARefusedRecipientWithoutLosingTheRest(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'secret');

        // The server is configured to allow @example.test and nothing else, so
        // the second recipient draws a real refusal mid-transaction.
        $result = $client->sendRaw(
            new Envelope('jane@example.test', ['john@example.test', 'blocked@example.invalid']),
            "Subject: Partial\r\n\r\nBody\r\n",
        );

        $client->close();

        $this->assertSame(['john@example.test'], $result->accepted);
        $this->assertArrayHasKey('blocked@example.invalid', $result->rejected);
        $this->assertSame(Outcome::Permanent, $result->rejected['blocked@example.invalid']->outcome);
        $this->assertSame('Partial', $this->field($this->delivered(), 'Subject'));
    }

    public function testSurfacesARefusalAsATransactionFailure(): void
    {
        $client = $this->client(Encryption::StartTls, 'jane', 'secret');

        try {
            $client->sendRaw(new Envelope('jane@example.test', ['blocked@example.invalid']), "Subject: X\r\n\r\nBody\r\n");
            $this->fail('Expected the send to fail');
        } catch (TransactionException $exception) {
            $this->assertTrue($exception->isPermanent());
            $this->assertFalse($exception->isTransient());
        }

        $client->close();
    }
}
