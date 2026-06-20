<?php

namespace Utopia\Replication\Source\MySQL;

use Utopia\Replication\Exception;

/**
 * Reads raw binlog events from a binlog *file* rather than a live socket — the
 * same byte stream `mysqlbinlog --raw` archives, or a segment pulled from object
 * storage. No server, no replication privileges: just the bytes.
 *
 * A binlog file is a 4-byte magic header followed by events back-to-back, each
 * self-framed by the `event_size` field in its header (unlike the live protocol,
 * which frames events in MySQL packets). The checksum algorithm is declared by
 * the leading FORMAT_DESCRIPTION_EVENT, so — unlike the socket source — it needs
 * no `@@global.binlog_checksum` query to know whether a CRC trailer is present.
 *
 * Pair it with a {@see Decoder} to turn the events into changes:
 *
 *   $source = new File($bytes);   // string, or an iterable of chunks
 *   $source->open();
 *   $decoder = new Decoder(new EventParser($resolver), new GtidSet(), 'appwrite', $source->checksum());
 *   foreach ($source->events() as $event) {
 *       if ($change = $decoder->decode($event)) { ... }
 *   }
 *
 * Column names need binlog_row_metadata=FULL (carried in the binlog) or an
 * EventParser resolver; offline there is no server to fall back to.
 */
final class File implements Transport
{
    /** Binlog file magic: 0xFE then "bin". */
    private const string MAGIC = "\xfe\x62\x69\x6e";

    private bool $checksum = false;

    /** @var \Iterator<int, string> */
    private \Iterator $chunks;
    private string $buffer = '';
    private bool $exhausted = false;

    /** FORMAT_DESCRIPTION_EVENT, read during open() to learn the checksum, yielded first. */
    private ?string $pending = null;

    /**
     * @param string|iterable<string> $source Full binlog bytes, or a stream of
     *                                        byte chunks (e.g. an object-storage
     *                                        download) reassembled on the fly.
     *                                        A string or array source is
     *                                        re-openable; a one-shot iterable
     *                                        (e.g. a Generator) is single-pass and
     *                                        cannot be re-opened once drained.
     */
    public function __construct(private readonly string|iterable $source) {}

    public function open(?string $position = null): void
    {
        // Reset per-open state so the same instance can be re-opened (e.g. a retry).
        $this->buffer = '';
        $this->exhausted = false;
        $this->pending = null;
        $this->checksum = false;

        $chunks = \is_string($this->source) ? [$this->source] : $this->source;
        $this->chunks = (function () use ($chunks): \Generator {
            foreach ($chunks as $chunk) {
                yield $chunk;
            }
        })();

        if ($this->take(4) !== self::MAGIC) {
            throw new Exception('Not a MySQL binlog file: bad magic header');
        }

        // The first event is the FORMAT_DESCRIPTION_EVENT. On checksum-aware servers
        // (MySQL >= 5.6.1, which the package targets) it always carries a trailing
        // [algorithm byte][4-byte CRC slot], so the algorithm sits at len-5 — the
        // same offset MySQL's own Log_event_footer::get_checksum_alg reads. 1 = CRC32.
        $this->pending = $this->readEvent();
        if ($this->pending !== null
            && \ord($this->pending[4]) === Constants::FORMAT_DESCRIPTION_EVENT
            && \strlen($this->pending) >= Constants::EVENT_HEADER_SIZE + 5
        ) {
            $this->checksum = \ord($this->pending[\strlen($this->pending) - 5]) === 1;
        }
    }

    /**
     * @return \Generator<string>
     */
    public function events(): \Generator
    {
        if ($this->pending !== null) {
            yield $this->pending;
            $this->pending = null;
        }

        while (($event = $this->readEvent()) !== null) {
            yield $event;
        }
    }

    public function checksum(): bool
    {
        return $this->checksum;
    }

    public function position(): string
    {
        return ''; // a file has no resume token; the decoder accumulates from GTID events
    }

    public function close(): void
    {
        // Nothing to release; the caller owns any underlying stream.
    }

    /**
     * Frame the next event by its self-declared size, or null at a clean end of
     * stream. A partial header/body means the file was truncated mid-event.
     */
    private function readEvent(): ?string
    {
        $header = $this->take(Constants::EVENT_HEADER_SIZE);
        if ($header === '') {
            return null;
        }
        if (\strlen($header) < Constants::EVENT_HEADER_SIZE) {
            throw new Exception('Truncated binlog: incomplete event header');
        }

        // event_size (header bytes 9-12, little-endian) covers header + body + CRC.
        $eventSize = \ord($header[9]) | (\ord($header[10]) << 8) | (\ord($header[11]) << 16) | (\ord($header[12]) << 24);
        if ($eventSize < Constants::EVENT_HEADER_SIZE) {
            throw new Exception("Corrupt binlog: event_size {$eventSize} is smaller than the event header");
        }
        $remaining = $eventSize - Constants::EVENT_HEADER_SIZE;

        $body = $this->take($remaining);
        if (\strlen($body) < $remaining) {
            throw new Exception('Truncated binlog: incomplete event body');
        }

        return $header . $body;
    }

    /**
     * Pull exactly $bytes from the chunk stream. Returns fewer only at the very
     * end (empty string for a clean boundary, a short string for truncation).
     */
    private function take(int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }

        while (\strlen($this->buffer) < $bytes && !$this->exhausted) {
            if ($this->chunks->valid()) {
                $this->buffer .= $this->chunks->current();
                $this->chunks->next();
            } else {
                $this->exhausted = true;
            }
        }

        $out = substr($this->buffer, 0, $bytes);
        $this->buffer = substr($this->buffer, \strlen($out));

        return $out;
    }
}
