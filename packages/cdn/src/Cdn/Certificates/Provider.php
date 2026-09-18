<?php

declare(strict_types=1);

namespace Utopia\Cdn\Certificates;

interface Provider
{
    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string;

    public function isInstantGeneration(string $domain, ?string $domainType): bool;

    /** @throws \Utopia\Cdn\Exception\Certificate When the provider reports a failure or requires DNS changes. */
    public function getCertificateStatus(string $domain, ?string $domainType): string;

    public function isRenewRequired(string $domain, ?string $domainType): bool;

    public function deleteCertificate(string $domain, ?string $domainType = null): void;
}
