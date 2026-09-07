<?php

declare(strict_types=1);

namespace Utopia\Cdn\Exception;

/** An explicit certificate failure or DNS action required by its provider. */
class Certificate extends \RuntimeException
{
    /** @param list<array{type:string,name:string,values:list<string>}> $dnsRecords */
    public function __construct(
        string $message,
        private readonly string $status,
        private readonly array $dnsRecords = [],
    ) {
        parent::__construct($message);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return list<array{type:string,name:string,values:list<string>}> */
    public function getDnsRecords(): array
    {
        return $this->dnsRecords;
    }
}
