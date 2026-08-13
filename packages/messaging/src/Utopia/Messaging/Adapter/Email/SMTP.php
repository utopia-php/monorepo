<?php

namespace Utopia\Messaging\Adapter\Email;

use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\Messaging\Messages\Email\Attachment as EmailAttachment;
use Utopia\Messaging\Response;
use Utopia\SMTP\Address;
use Utopia\SMTP\Attachment;
use Utopia\SMTP\Auth\Login;
use Utopia\SMTP\Auth\Plain;
use Utopia\SMTP\Client;
use Utopia\SMTP\Encryption;
use Utopia\SMTP\Exception\SmtpException;
use Utopia\SMTP\Exception\TransactionException;
use Utopia\SMTP\Message as SmtpMessage;
use Utopia\SMTP\Timeouts;
use Utopia\SMTP\Transport\Native;

class SMTP extends EmailAdapter
{
    protected const NAME = 'SMTP';

    private ?Client $client = null;

    /**
     * @param string $host SMTP hosts. Either a single hostname or multiple semicolon-delimited hostnames. You can also specify a different port for each host by using this format: [hostname:port] (e.g. "smtp1.example.com:25;smtp2.example.com"). You can also specify encryption type, for example: (e.g. "tls://smtp1.example.com:587;ssl://smtp2.example.com:465"). Hosts will be tried in order.
     * @param int $port The default SMTP server port.
     * @param string $username Authentication username.
     * @param string $password Authentication password.
     * @param string $smtpSecure SMTP Secure prefix. Can be '', 'ssl' or 'tls'
     * @param bool $smtpAutoTLS Enable/disable SMTP AutoTLS feature. Defaults to false.
     * @param string $xMailer The value to use for the X-Mailer header.
     * @param int $timeout SMTP timeout in seconds.
     * @param bool $keepAlive Whether to reuse the SMTP connection across process() calls.
     * @param int $timelimit SMTP command timelimit in seconds.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port = 25,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $smtpSecure = '',
        private readonly bool $smtpAutoTLS = false,
        private readonly string $xMailer = '',
        private readonly int $timeout = 30,
        private readonly bool $keepAlive = false,
        private readonly int $timelimit = 30,
    ) {
        parent::__construct();
        if (!\in_array($this->smtpSecure, ['', 'ssl', 'tls'])) {
            throw new \InvalidArgumentException('Invalid SMTP secure prefix. Must be "", "ssl" or "tls"');
        }
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1000;
    }

    /**
     * {@inheritdoc}
     */
    protected function process(EmailMessage $message): array
    {
        $response = new Response($this->getType());
        $recipients = $this->recipients($message);

        try {
            $client = $this->client();
        } catch (SmtpException $exception) {
            foreach ($recipients as $email) {
                $response->addResult($email, $exception->getMessage());
            }

            return $response->toArray();
        }

        try {
            $result = $client->send($this->build($message));

            // A server may refuse some recipients and accept others, and the
            // message still reaches the rest. Each address gets its own answer
            // rather than every address getting the same one.
            $response->setDeliveredTo(\count($result->accepted));

            foreach ($result->accepted as $email) {
                $response->addResult($email);
            }

            foreach ($result->rejected as $email => $reply) {
                $response->addResult($email, (string) $reply);
            }
        } catch (TransactionException $exception) {
            foreach ($recipients as $email) {
                $response->addResult($email, (string) $exception->reply);
            }
        } catch (SmtpException $exception) {
            foreach ($recipients as $email) {
                $response->addResult($email, $exception->getMessage());
            }
        } finally {
            if (!$this->keepAlive) {
                $client->close();
                $this->client = null;
            }
        }

        return $response->toArray();
    }

    /**
     * Close a connection held open between sends. Doing nothing is safe.
     */
    public function disconnect(): void
    {
        $this->client?->close();
        $this->client = null;
    }

    /**
     * The first host that answers, tried in the order they were given.
     */
    private function client(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        $timeouts = new Timeouts(
            connect: (float) $this->timeout,
            read: (float) $this->timelimit,
            write: (float) $this->timelimit,
        );

        $failures = [];

        foreach ($this->hosts() as [$host, $port, $encryption]) {
            $client = new Client(
                new Native($host, $port),
                gethostname() ?: 'localhost',
                $this->authenticators(),
                $encryption,
                $timeouts,
            );

            try {
                // Nothing is dialled until a session is needed, so asking what
                // the server offers is what settles whether this host answers.
                $client->capabilities();
            } catch (SmtpException $exception) {
                $failures[] = "{$host}:{$port} ({$exception->getMessage()})";

                continue;
            }

            if ($this->keepAlive) {
                $this->client = $client;
            }

            return $client;
        }

        throw new \Utopia\SMTP\Exception\ConnectionException(
            'No SMTP host answered: ' . implode('; ', $failures),
        );
    }

    /**
     * The host string, which may name several servers with their own port and
     * encryption, as a list of somewhere to try.
     *
     * @return list<array{string, int, Encryption}>
     */
    private function hosts(): array
    {
        $hosts = [];

        foreach (explode(';', $this->host) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            $encryption = $this->encryption();

            if (preg_match('#^(ssl|tls)://#i', $entry, $matches) === 1) {
                $encryption = strtolower($matches[1]) === 'ssl' ? Encryption::Implicit : Encryption::StartTls;
                $entry = substr($entry, \strlen($matches[0]));
            }

            $port = $this->port;

            if (preg_match('/^(.*):(\d+)$/', $entry, $matches) === 1) {
                $entry = $matches[1];
                $port = (int) $matches[2];
            }

            $hosts[] = [$entry, $port, $encryption];
        }

        return $hosts === [] ? [[$this->host, $this->port, $this->encryption()]] : $hosts;
    }

    /**
     * The prefix says what to do, and without one the AutoTLS flag decides
     * whether an offered upgrade is taken.
     */
    private function encryption(): Encryption
    {
        return match ($this->smtpSecure) {
            'ssl' => Encryption::Implicit,
            'tls' => Encryption::StartTls,
            default => $this->smtpAutoTLS ? Encryption::Opportunistic : Encryption::None,
        };
    }

    /**
     * @return list<Plain|Login>
     */
    private function authenticators(): array
    {
        if ($this->username === '' || $this->password === '') {
            return [];
        }

        return [new Plain($this->username, $this->password), new Login($this->username, $this->password)];
    }

    /**
     * Every address the envelope will carry, which is what a per-recipient
     * result is keyed by.
     *
     * @return list<string>
     */
    private function recipients(EmailMessage $message): array
    {
        $recipients = [];

        foreach ([...$message->getTo(), ...($message->getCC() ?? []), ...($message->getBCC() ?? [])] as $recipient) {
            $recipients[$recipient['email']] = true;
        }

        return array_keys($recipients);
    }

    private function build(EmailMessage $message): SmtpMessage
    {
        $headers = [];

        if ($this->xMailer !== '') {
            $headers['X-Mailer'] = $this->xMailer;
        }

        $text = $message->getContent();

        if ($message->isHtml()) {
            // Stripping tags leaves the contents of a style block behind, so
            // those go first.
            $text = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $message->getContent()) ?? '';
            $text = trim(strip_tags($text));
        }

        return new SmtpMessage(
            from: new Address($message->getFromEmail(), $message->getFromName()),
            to: $this->addresses($message->getTo()),
            subject: $message->getSubject(),
            text: $text,
            html: $message->isHtml() ? $message->getContent() : null,
            cc: $this->addresses($message->getCC() ?? []),
            bcc: $this->addresses($message->getBCC() ?? []),
            replyTo: $message->getReplyToEmail() === ''
                ? []
                : [new Address($message->getReplyToEmail(), $message->getReplyToName())],
            attachments: $this->attachments($message),
            headers: $headers,
        );
    }

    /**
     * @param  array<array<string, string>>  $recipients
     * @return list<Address>
     */
    private function addresses(array $recipients): array
    {
        return array_values(array_map(
            static fn(array $recipient): Address => new Address($recipient['email'], $recipient['name'] ?? ''),
            $recipients,
        ));
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(EmailMessage $message): array
    {
        $attachments = $message->getAttachments() ?? [];

        if ($attachments === []) {
            return [];
        }

        $size = 0;

        foreach ($attachments as $attachment) {
            $size += $this->size($attachment);
        }

        if ($size > self::MAX_ATTACHMENT_BYTES) {
            throw new \Exception('Attachments size exceeds the maximum allowed size of 25MB');
        }

        return array_values(array_map(
            static fn(EmailAttachment $attachment): Attachment => $attachment->getContent() === null
                // Read while the message is written, so a large file is never
                // held in memory twice.
                ? Attachment::fromPath($attachment->getPath(), $attachment->getName(), $attachment->getType())
                : Attachment::fromString($attachment->getContent(), $attachment->getName(), $attachment->getType()),
            $attachments,
        ));
    }

    private function size(EmailAttachment $attachment): int
    {
        if ($attachment->getContent() !== null) {
            return \strlen($attachment->getContent());
        }

        $size = filesize($attachment->getPath());

        if ($size === false) {
            throw new \Exception('Failed to read attachment file: ' . $attachment->getPath());
        }

        return $size;
    }
}
