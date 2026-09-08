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

        if (($domainInfo['routing_configuration_id'] ?? null) !== null) {
            return null;
        }

        $existingServiceId = $domainInfo['service_id'] ?? null;
        if ($existingServiceId === null && $this->findClassicService($domain) !== null) {
            return null;
        }

        if ($domainInfo !== null && $existingServiceId !== $this->serviceId) {
            $domainId = $domainInfo['id'] ?? null;
            if (!\is_string($domainId) || $domainId === '') {
                throw new \RuntimeException('Fastly domain response was missing its ID.');
            }

            if ($existingServiceId === null) {
                // Restore the orphan's service association before continuing issuance.
                $result = $this->request(
                    'PATCH',
                    '/domain-management/v1/domains/' . rawurlencode($domainId),
                    ['service_id' => $this->serviceId],
                );
                $this->assertSuccess('link Fastly domain', $result);

                return $this->tls->issueCertificate($certName, $domain, $domainType);
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

        $serviceId = $domainInfo['service_id'] ?? null;
        if (($domainInfo['routing_configuration_id'] ?? null) !== null) {
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
        if (($domainInfo['routing_configuration_id'] ?? null) !== null) {
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
        $result = $this->request('GET', '/domain-management/v1/domains?' . $query);
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
            foreach (['service_id' => 'service ID', 'routing_configuration_id' => 'routing configuration ID'] as $field => $label) {
                $value = $match[$field] ?? null;
                if ($value !== null && (!\is_string($value) || $value === '')) {
                    throw new \RuntimeException('Fastly domain response contained an invalid ' . $label . '.');
                }
            }

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
        // Classic routes can exist without an exact versionless domain record.
        // Check their ownership before creating or linking a versionless domain.
        if ($this->hasClassicDomain($this->serviceId, $domain)) {
            return $this->serviceId;
        }

        // Service inventory is filtered by both token and user permissions. A
        // partial inventory cannot establish that no classic route owns a host.
        $token = $this->request('GET', '/tokens/self');
        $this->assertSuccess('fetch Fastly token permissions', $token);
        if (!$token['response'] instanceof \stdClass || ($token['response']->services ?? null) !== []) {
            throw new \RuntimeException('Managing an unlinked Fastly domain requires access to all services.');
        }

        $user = $this->request('GET', '/current_user');
        $this->assertSuccess('fetch Fastly user permissions', $user);
        if (!$user['response'] instanceof \stdClass || ($user['response']->limit_services ?? null) !== false) {
            throw new \RuntimeException('Managing an unlinked Fastly domain requires an unrestricted service inventory.');
        }

        $seen = [];
        $foundService = false;
        for ($page = 1; ; $page++) {
            $query = http_build_query(['page' => $page, 'per_page' => 20]);
            $result = $this->request('GET', '/service?' . $query);
            $this->assertSuccess('fetch Fastly services', $result);

            $services = $result['response'];
            if (!\is_array($services)) {
                throw new \RuntimeException('Fastly services response was not a valid list.');
            }

            foreach ($services as $service) {
                $serviceId = $service instanceof \stdClass ? ($service->id ?? null) : null;
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

        if (!$result['response'] instanceof \stdClass || !property_exists($result['response'], 'active_version')) {
            throw new \RuntimeException('Fastly service details response was missing its active version.');
        }

        $activeVersion = $result['response']->active_version;
        if ($activeVersion === null) {
            return false;
        }

        $domains = $activeVersion instanceof \stdClass ? ($activeVersion->domains ?? null) : null;
        if (!\is_array($domains)) {
            throw new \RuntimeException('Fastly service details response was missing its active domain list.');
        }

        foreach ($domains as $candidate) {
            if (!$candidate instanceof \stdClass || !\is_string($candidate->name ?? null) || $candidate->name === '') {
                throw new \RuntimeException('Fastly service details response contained a malformed domain.');
            }

            $name = strtolower($candidate->name);
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

        if (!$result['response'] instanceof \stdClass) {
            throw new \RuntimeException('Fastly service details response was not a valid JSON object.');
        }

        $activeVersion = $result['response']->active_version ?? null;
        if (!$activeVersion instanceof \stdClass) {
            throw new \RuntimeException('Fastly service details response was missing its active version.');
        }

        $domains = $activeVersion->domains ?? [];
        $containsDomain = \is_array($domains) && array_any(
            $domains,
            static fn(mixed $candidate): bool => $candidate instanceof \stdClass && ($candidate->name ?? null) === $domain,
        );

        if (!$containsDomain) {
            // Classic domain records do not identify their service. An
            // account-wide FQDN match that is absent from this service may
            // belong to another service, whose TLS must remain untouched.
            return;
        }

        $currentVersion = $activeVersion->number ?? null;
        if (!\is_int($currentVersion)) {
            throw new \RuntimeException('Fastly active service version was missing its number.');
        }

        $servicePath = '/service/' . rawurlencode($this->serviceId) . '/version/';
        $result = $this->request('PUT', $servicePath . $currentVersion . '/clone');
        $this->assertSuccess('clone Fastly service version', $result);

        if (!$result['response'] instanceof \stdClass || !\is_int($result['response']->number ?? null)) {
            throw new \RuntimeException('Fastly cloned service version was missing its number.');
        }
        $newVersion = $result['response']->number;

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

            if ($result['response'] instanceof \stdClass && ($result['response']->active ?? false) === true) {
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
    private function request(string $method, string $path, ?array $body = null): array
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
            $decoded = json_decode($contents, flags: JSON_THROW_ON_ERROR);
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
            $errors = (array) ($response->errors ?? []);
            $message ??= $errors[0]->detail
                ?? $errors[0]->title
                ?? $response->msg
                ?? null;
        }

        throw new \RuntimeException('Failed to ' . $operation . ' with status ' . $result['statusCode'] . ': ' . ($message ?? 'Unknown Fastly error'));
    }
}
