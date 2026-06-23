<?php

declare(strict_types=1);

namespace Utopia\NATS\Protocol;

use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Transport\Transport;

final class Parser
{
    private string $buffer = '';

    public function __construct(
        private readonly Transport $transport,
    ) {
    }

    /**
     * Read and parse the next server operation.
     *
     * @return array{0: ServerOp, 1: mixed} Tuple of [operation, parsed data]
     */
    public function next(?float $timeout = null): array
    {
        $line = $this->readLine($timeout);
        $line = rtrim($line, "\r\n");

        if ($line === '') {
            throw new ProtocolException('Empty protocol line received');
        }

        // +OK
        if ($line === '+OK') {
            return [ServerOp::Ok, null];
        }

        // PING
        if ($line === 'PING') {
            return [ServerOp::Ping, null];
        }

        // PONG
        if ($line === 'PONG') {
            return [ServerOp::Pong, null];
        }

        // -ERR 'message'
        if (str_starts_with($line, '-ERR')) {
            $message = trim(substr($line, 4), " \t'\"");
            return [ServerOp::Err, $message];
        }

        // INFO {json}
        if (str_starts_with($line, 'INFO ')) {
            $json = substr($line, 5);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return [ServerOp::Info, $data];
        }

        // MSG <subject> <sid> [reply-to] <#bytes>
        if (str_starts_with($line, 'MSG ')) {
            return $this->parseMsg(substr($line, 4));
        }

        // HMSG <subject> <sid> [reply-to] <#header-bytes> <#total-bytes>
        if (str_starts_with($line, 'HMSG ')) {
            return $this->parseHmsg(substr($line, 5));
        }

        throw new ProtocolException("Unknown protocol operation: {$line}");
    }

    /**
     * Parse MSG: subject sid [reply-to] #bytes
     *
     * @return array{0: ServerOp, 1: array{subject: string, sid: string, replyTo: ?string, payload: string}}
     */
    private function parseMsg(string $args): array
    {
        $parts = preg_split('/\s+/', trim($args));

        if ($parts === false || count($parts) < 3 || count($parts) > 4) {
            throw new ProtocolException("Invalid MSG line: MSG {$args}");
        }

        if (count($parts) === 3) {
            [$subject, $sid, $byteCount] = $parts;
            $replyTo = null;
        } else {
            [$subject, $sid, $replyTo, $byteCount] = $parts;
        }

        $bytes = (int) $byteCount;
        $payload = $this->readExactly($bytes);
        // Consume trailing \r\n
        $this->readExactly(2);

        return [ServerOp::Msg, [
            'subject' => $subject,
            'sid' => $sid,
            'replyTo' => $replyTo,
            'payload' => $payload,
            'headers' => null,
        ]];
    }

    /**
     * Parse HMSG: subject sid [reply-to] #header-bytes #total-bytes
     *
     * @return array{0: ServerOp, 1: array{subject: string, sid: string, replyTo: ?string, payload: string, headers: string}}
     */
    private function parseHmsg(string $args): array
    {
        $parts = preg_split('/\s+/', trim($args));

        if ($parts === false || count($parts) < 4 || count($parts) > 5) {
            throw new ProtocolException("Invalid HMSG line: HMSG {$args}");
        }

        if (count($parts) === 4) {
            [$subject, $sid, $headerBytes, $totalBytes] = $parts;
            $replyTo = null;
        } else {
            [$subject, $sid, $replyTo, $headerBytes, $totalBytes] = $parts;
        }

        $hdrLen = (int) $headerBytes;
        $totalLen = (int) $totalBytes;
        $payloadLen = $totalLen - $hdrLen;

        if ($payloadLen < 0) {
            throw new ProtocolException("Invalid HMSG byte counts: header={$hdrLen}, total={$totalLen}");
        }

        $headerBlock = $this->readExactly($hdrLen);
        $payload = $payloadLen > 0 ? $this->readExactly($payloadLen) : '';
        // Consume trailing \r\n
        $this->readExactly(2);

        return [ServerOp::HMsg, [
            'subject' => $subject,
            'sid' => $sid,
            'replyTo' => $replyTo,
            'payload' => $payload,
            'headers' => $headerBlock,
        ]];
    }

    private function readLine(?float $timeout = null): string
    {
        // Check buffer for a complete line first
        $pos = strpos($this->buffer, "\n");
        if ($pos !== false) {
            $line = substr($this->buffer, 0, $pos + 1);
            $this->buffer = substr($this->buffer, $pos + 1);
            return $line;
        }

        // Read from transport until we get a line
        while (true) {
            $data = $this->transport->read(65536, $timeout);
            $this->buffer .= $data;

            $pos = strpos($this->buffer, "\n");
            if ($pos !== false) {
                $line = substr($this->buffer, 0, $pos + 1);
                $this->buffer = substr($this->buffer, $pos + 1);
                return $line;
            }
        }
    }

    private function readExactly(int $bytes): string
    {
        while (strlen($this->buffer) < $bytes) {
            $data = $this->transport->read(max(65536, $bytes - strlen($this->buffer)));
            if ($data === '') {
                throw new ProtocolException('Unexpected end of data while reading payload');
            }
            $this->buffer .= $data;
        }

        $result = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, $bytes);
        return $result;
    }
}
