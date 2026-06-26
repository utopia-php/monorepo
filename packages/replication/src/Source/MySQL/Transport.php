<?php

declare(strict_types=1);

namespace Utopia\Replication\Source\MySQL;

/**
 * A carrier of raw binlog event records, decoupled from how those bytes arrive.
 *
 * The two transports differ only in framing: a live replication socket
 * ({@see Connection}) delivers events wrapped in MySQL protocol packets, while a
 * binlog file ({@see File}) holds them back-to-back behind a magic header. Both
 * normalise to the same unit — a record starting at the 19-byte event header and
 * including the CRC trailer when present — so a single {@see Decoder} can consume
 * either.
 */
interface Transport
{
    /**
     * Prepare to stream, resuming from $position when the transport supports it
     * (a GTID set for the live socket; ignored by the file reader).
     */
    public function open(?string $position = null): void;

    /**
     * Raw event records: 19-byte event header onward, CRC trailer included when
     * {@see checksum()} is true. In a coroutine runtime this yields while
     * waiting on the underlying transport.
     *
     * @return \Generator<string>
     */
    public function events(): \Generator;

    /**
     * Whether the yielded events carry a 4-byte CRC32 trailer (so the decoder
     * knows to strip it). Known only after {@see open()}.
     */
    public function checksum(): bool;

    /**
     * The executed-GTID-set this source resumed from, used to seed the decoder's
     * checkpoint. Empty string when the transport has no notion of one.
     */
    public function position(): string;

    public function close(): void;
}
