<?php

namespace Utopia\Cdn\Certificates\Provider;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Domain;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Manages Fastly domains together with their TLS subscriptions.
 *
 * Fastly's domain-management API owns versionless domains. Domains on a classic
 * service version have to be removed by cloning and activating that version
 * before their TLS subscription can be deleted.
 */
class Fastly implements Provider
{
    private readonly ClientInterface $client;

    private readonly FastlyTls $tls;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $serviceId,
        string $certificateAuthority = 'certainly',
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://api.fastly.com',
        private readonly int $deploymentPollAttempts = 10,
        private readonly int $deploymentPollIntervalMilliseconds = 5000,
    ) {
        if ($this->deploymentPollAttempts < 1) {
            throw new \InvalidArgumentException('Deployment poll attempts must be at least one.');
        }

        if ($this->deploymentPollIntervalMilliseconds < 0) {
            throw new \InvalidArgumentException('Deployment poll interval cannot be negative.');
        }

        $this->client = $client ?? new Client(new CurlAdapter());
        $this->tls = new FastlyTls(
            apiToken: $this->apiToken,
            tlsConfigurationId: '',
            certificateAuthority: $certificateAuthority,
            client: $this->client,
            apiBase: $this->apiBase,
        );
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $domain = Domain::validate($domain);
        $domainInfo = $this->findDomain($domain);

        if ($domainInfo !== null) {
            $existingServiceId = $domainInfo['service_id'] ?? null;

            if ($existingServiceId !== null && (!\is_string($existingServiceId) || $existingServiceId === '')) {
                throw new \RuntimeException('Fastly domain response contained an invalid service ID.');
            }

            $routingId = $domainInfo['routing_configuration_id'] ?? null;
            if ($routingId !== null) {
                if (!\is_string($routingId) || $routingId === '') {
                    throw new \RuntimeException('Fastly domain response contained an invalid routing configuration ID.');
                }

                return null;
            }

            if ($existingServiceId === null) {
                if ($this->findClassicService($domain) !== null) {
                    return null;
                }

                $domainId = $domainInfo['id'] ?? null;
                if (!\is_string($domainId) || $domainId === '') {
                    throw new \RuntimeException('Fastly domain response was missing its ID.');
                }

                // An unlinked versionless domain needs its service association
                // before Fastly can select the TLS configuration for issuance.
                $result = $this->request(
                    'PATCH',
                    '/domain-management/v1/domains/' . rawurlencode($domainId),
                    ['service_id' => $this->serviceId],
                );
                $this->assertSuccess('link Fastly domain', $result);

                return $this->tls->issueCertificate($certName, $domain, $domainType);
            }

            if ($existingServiceId !== $this->serviceId) {
                $domainId = $domainInfo['id'] ?? null;
                if (!\is_string($domainId) || $domainId === '') {
                    throw new \RuntimeException('Fastly domain response was missing its ID.');
                }

                // Finish the certificate lifecycle while ownership is still
                // unchanged. A TLS failure must not move a live hostname.
                $renewDate = $this->tls->issueCertificate($certName, $domain, $domainType);
                $status = $this->tls->getCertificateStatus($domain, $domainType);

                if (!\in_array($status, [Status::ISSUED, Status::RENEWING], true)) {
                    return $renewDate;
                }

                $result = $this->request(
                    'PATCH',
                    '/domain-management/v1/domains/' . rawurlencode($domainId),
                    ['service_id' => $this->serviceId],
                );
                $this->assertSuccess('reassign Fastly domain', $result);

                return $renewDate;
            }
        }

        if ($domainInfo === null) {
            $result = $this->request('POST', '/domain-management/v1/domains', [
                'fqdn' => $domain,
                'service_id' => $this->serviceId,
            ]);
            $this->assertSuccess('add Fastly domain', $result, [201]);
        }

        return $this->tls->issueCertificate($certName, $domain, $domainType);
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return false;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        return $this->tls->getCertificateStatus(Domain::validate($domain), $domainType);
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        $domain = Domain::validate($domain);
        $domainInfo = $this->findDomain($domain);

        if ($domainInfo === null) {
            return true;
        }

        $serviceId = $domainInfo['service_id'] ?? null;
        if ($serviceId !== null && (!\is_string($serviceId) || $serviceId === '')) {
            throw new \RuntimeException('Fastly domain response contained an invalid service ID.');
        }

        $routingId = $domainInfo['routing_configuration_id'] ?? null;
        if ($routingId !== null) {
            if (!\is_string($routingId) || $routingId === '') {
                throw new \RuntimeException('Fastly domain response contained an invalid routing configuration ID.');
            }

            return false;
        }

        if ($serviceId === null) {
            return $this->findClassicService($domain) === null;
        }

        if ($serviceId !== $this->serviceId) {
            return true;
        }

        return $this->tls->isRenewRequired($domain, $domainType);
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $domain = Domain::validate($domain);
        $domainInfo = $this->findDomain($domain);

        $serviceId = $domainInfo['service_id'] ?? null;
        if ($serviceId !== null && (!\is_string($serviceId) || $serviceId === '')) {
            throw new \RuntimeException('Fastly domain response contained an invalid service ID.');
        }

        $routingId = $domainInfo['routing_configuration_id'] ?? null;
        if ($routingId !== null) {
            if (!\is_string($routingId) || $routingId === '') {
                throw new \RuntimeException('Fastly domain response contained an invalid routing configuration ID.');
            }

            return;
        }

        if ($serviceId === null) {
            $classicServiceId = $this->findClassicService($domain);
            if ($classicServiceId !== null) {
                if ($classicServiceId === $this->serviceId) {
                    $this->deleteClassicDomain($domain, $domainType);
                }

                return;
            }
        } elseif ($serviceId !== $this->serviceId) {
            return;
        }

        if ($domainInfo === null) {
            $this->tls->deleteCertificate($domain, $domainType);
            return;
        }

        // Remove the provider record before its TLS subscription. Retrying a
        // partial cleanup uses the missing-domain branch to finish TLS removal.
        $this->deleteVersionlessDomain($domainInfo, $domainType);
    }

    /** @return array<string, mixed>|null */
    private function findDomain(string $domain): ?array
    {
        $query = http_build_query(['fqdn' => $domain, 'fqdn_match' => 'exact', 'limit' => 100]);
        $result = $this->request('GET', '/domain-management/v1/domains?' . $query, associative: false);
        $this->assertSuccess('fetch Fastly domains', $result);

        if (!$result['response'] instanceof \stdClass) {
            throw new \RuntimeException('Fastly domains response was not a valid JSON object.');
        }

        $domains = $result['response']->data ?? null;
        if (!\is_array($domains)) {
            throw new \RuntimeException('Fastly domains response was missing its data list.');
        }

        $match = null;
        foreach ($domains as $candidate) {
            if (!$candidate instanceof \stdClass || !\is_string($candidate->fqdn ?? null) || $candidate->fqdn === '') {
                throw new \RuntimeException('Fastly domains response contained a malformed domain.');
            }

            if ($candidate->fqdn === $domain) {
                if ($match !== null) {
                    throw new \RuntimeException('Fastly domains response contained repeated exact matches.');
                }

                $match = (array) $candidate;
            }
        }

        if ($match !== null) {
            return $match;
        }

        // Do not infer absence from a truncated fuzzy result if an older API
        // ignores fqdn_match. Missing-domain cleanup can otherwise delete TLS.
        $meta = $result['response']->meta ?? null;
        if ($meta !== null && !$meta instanceof \stdClass) {
            throw new \RuntimeException('Fastly domains response contained malformed pagination metadata.');
        }
        $cursor = $meta->next_cursor ?? null;
        if (($cursor !== null && $cursor !== '') || \count($domains) >= 100) {
            throw new \RuntimeException('Fastly domain lookup did not establish a complete exact result.');
        }

        return null;
    }

    /** @param array<string, mixed> $domainInfo */
    private function deleteVersionlessDomain(array $domainInfo, ?string $domainType = null): void
    {
        $domainId = $domainInfo['id'] ?? null;
        $domain = $domainInfo['fqdn'] ?? null;

        if (!\is_string($domainId) || $domainId === '' || !\is_string($domain) || $domain === '') {
            throw new \RuntimeException('Fastly domain response was missing its ID or FQDN.');
        }

        $result = $this->request('DELETE', '/domain-management/v1/domains/' . rawurlencode($domainId));
        $this->assertSuccess('delete Fastly domain', $result, [204]);

        $this->tls->deleteCertificate($domain, $domainType);
    }

    private function findClassicService(string $domain): ?string
    {
        // A null service_id also occurs on orphaned versionless domains. Check
        // classic ownership across services before changing an unlinked domain.
        if ($this->hasClassicDomain($this->serviceId, $domain)) {
            return $this->serviceId;
        }

        // Service inventory is filtered by both token and user permissions. A
        // partial inventory cannot establish that no classic route owns a host.
        $token = $this->request('GET', '/tokens/self');
        $this->assertSuccess('fetch Fastly token permissions', $token);
        if (($token['response']['services'] ?? null) !== []) {
            throw new \RuntimeException('Managing an unlinked Fastly domain requires access to all services.');
        }

        $user = $this->request('GET', '/current_user');
        $this->assertSuccess('fetch Fastly user permissions', $user);
        if (($user['response']['limit_services'] ?? null) !== false) {
            throw new \RuntimeException('Managing an unlinked Fastly domain requires an unrestricted service inventory.');
        }

        $seen = [];
        $foundService = false;
        for ($page = 1; ; $page++) {
            $query = http_build_query(['page' => $page, 'per_page' => 20]);
            $result = $this->request('GET', '/service?' . $query);
            $this->assertSuccess('fetch Fastly services', $result);

            $services = $result['response'];
            if (!\is_array($services) || !array_is_list($services)) {
                throw new \RuntimeException('Fastly services response was not a valid list.');
            }

            foreach ($services as $service) {
                $serviceId = \is_array($service) ? ($service['id'] ?? null) : null;
                if (!\is_string($serviceId) || $serviceId === '' || isset($seen[$serviceId])) {
                    throw new \RuntimeException('Fastly services response contained a missing or repeated service ID.');
                }
                $seen[$serviceId] = true;

                if ($serviceId === $this->serviceId) {
                    $foundService = true;
                    continue;
                }

                if ($this->hasClassicDomain($serviceId, $domain)) {
                    return $serviceId;
                }
            }

            if (\count($services) < 20) {
                break;
            }
        }

        if (!$foundService) {
            throw new \RuntimeException('Fastly services response did not include the configured service.');
        }

        return null;
    }

    private function hasClassicDomain(string $serviceId, string $domain): bool
    {
        $result = $this->request('GET', '/service/' . rawurlencode($serviceId) . '/details');
        $this->assertSuccess('fetch Fastly service details', $result);

        if (!\is_array($result['response']) || !\array_key_exists('active_version', $result['response'])) {
            throw new \RuntimeException('Fastly service details response was missing its active version.');
        }

        $activeVersion = $result['response']['active_version'];
        if ($activeVersion === null) {
            return false;
        }

        $domains = \is_array($activeVersion) ? ($activeVersion['domains'] ?? null) : null;
        if (!\is_array($domains) || !array_is_list($domains)) {
            throw new \RuntimeException('Fastly service details response was missing its active domain list.');
        }

        foreach ($domains as $candidate) {
            if (!\is_array($candidate) || !\is_string($candidate['name'] ?? null) || $candidate['name'] === '') {
                throw new \RuntimeException('Fastly service details response contained a malformed domain.');
            }

            $name = strtolower($candidate['name']);
            // Classic wildcards can route this hostname even without an exact
            // domain record. Preserve that route before linking a versionless one.
            $pattern = '/^' . str_replace('\\*', '.*', preg_quote($name, '/')) . '$/D';
            if (preg_match($pattern, $domain) === 1) {
                return true;
            }
        }

        return false;
    }

    private function deleteClassicDomain(string $domain, ?string $domainType): void
    {
        $result = $this->request('GET', '/service/' . rawurlencode($this->serviceId) . '/details');
        $this->assertSuccess('fetch Fastly service details', $result);

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly service details response was not valid JSON.');
        }

        $activeVersion = $result['response']['active_version'] ?? null;
        if (!\is_array($activeVersion)) {
            throw new \RuntimeException('Fastly service details response was missing its active version.');
        }

        $domains = $activeVersion['domains'] ?? [];
        $containsDomain = \is_array($domains) && array_any(
            $domains,
            static fn(mixed $candidate): bool => \is_array($candidate) && ($candidate['name'] ?? null) === $domain,
        );

        if (!$containsDomain) {
            // Classic domain records do not identify their service. An
            // account-wide FQDN match that is absent from this service may
            // belong to another service, whose TLS must remain untouched.
            return;
        }

        $currentVersion = $activeVersion['number'] ?? null;
        if (!\is_int($currentVersion)) {
            throw new \RuntimeException('Fastly active service version was missing its number.');
        }

        $servicePath = '/service/' . rawurlencode($this->serviceId) . '/version/';
        $result = $this->request('PUT', $servicePath . $currentVersion . '/clone');
        $this->assertSuccess('clone Fastly service version', $result);

        if (!\is_array($result['response']) || !\is_int($result['response']['number'] ?? null)) {
            throw new \RuntimeException('Fastly cloned service version was missing its number.');
        }
        $newVersion = $result['response']['number'];

        $result = $this->request('DELETE', $servicePath . $newVersion . '/domain/' . rawurlencode($domain));
        $this->assertSuccess('remove classic Fastly domain', $result, [200]);

        $result = $this->request('PUT', $servicePath . $newVersion . '/activate');
        $this->assertSuccess('activate Fastly service version', $result);

        $this->waitForDeployment($newVersion);
        $this->tls->deleteCertificate($domain, $domainType);
    }

    private function waitForDeployment(int $version): void
    {
        $path = '/service/' . rawurlencode($this->serviceId) . '/version/' . $version;

        for ($attempt = 1; $attempt <= $this->deploymentPollAttempts; $attempt++) {
            $result = $this->request('GET', $path);
            $this->assertSuccess('fetch Fastly service version', $result);

            if (\is_array($result['response']) && ($result['response']['active'] ?? false) === true) {
                return;
            }

            if ($attempt < $this->deploymentPollAttempts && $this->deploymentPollIntervalMilliseconds > 0) {
                usleep($this->deploymentPollIntervalMilliseconds * 1000);
            }
        }

        throw new \RuntimeException('Fastly service version was not deployed after ' . $this->deploymentPollAttempts . ' attempts.');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array{statusCode:int,response:mixed,error:string|null}
     */
    private function request(string $method, string $path, ?array $body = null, bool $associative = true): array
    {
        $factory = new RequestFactory();
        $request = $body === null
            ? $factory->createRequest($method, $this->apiBase . $path)
            : $factory->json($method, $this->apiBase . $path, $body);
        $request = $request
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Fastly Certificates Provider')
            ->withHeader('Fastly-Key', $this->apiToken)
            ->withHeader(Header::ACCEPT, 'application/json');

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            return ['statusCode' => 0, 'response' => null, 'error' => $error->getMessage()];
        }

        $contents = (string) $response->getBody();

        try {
            $decoded = json_decode($contents, $associative, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = $contents;
        }

        return ['statusCode' => $response->getStatusCode(), 'response' => $decoded, 'error' => null];
    }

    /**
     * @param array{statusCode:int,response:mixed,error:string|null} $result
     * @param array<int, int>|null $expectedStatuses
     */
    private function assertSuccess(string $operation, array $result, ?array $expectedStatuses = null): void
    {
        $success = $expectedStatuses === null
            ? $result['statusCode'] >= 200 && $result['statusCode'] < 300
            : \in_array($result['statusCode'], $expectedStatuses, true);

        if ($success) {
            return;
        }

        $message = $result['error'];
        $response = $result['response'];
        if ($response instanceof \stdClass) {
            $response = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        }
        if (\is_array($response)) {
            $message ??= $response['errors'][0]['detail']
                ?? $response['errors'][0]['title']
                ?? $response['msg']
                ?? null;
        }

        throw new \RuntimeException('Failed to ' . $operation . ' with status ' . $result['statusCode'] . ': ' . ($message ?? 'Unknown Fastly error'));
    }
}
