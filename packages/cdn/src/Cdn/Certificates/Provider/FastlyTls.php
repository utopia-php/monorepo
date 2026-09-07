<?php

namespace Utopia\Cdn\Certificates\Provider;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Cdn\Certificates\Provider;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Client;
use Utopia\Client\Adapter\Curl\Client as CurlAdapter;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;

class FastlyTls implements Provider
{
    private readonly ClientInterface $client;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $tlsConfigurationId,
        private readonly string $certificateAuthority = 'certainly',
        ?ClientInterface $client = null,
        private readonly string $apiBase = 'https://api.fastly.com',
    ) {
        $this->client = $client ?? new Client(new CurlAdapter());
    }

    public function issueCertificate(string $certName, string $domain, ?string $domainType): ?string
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            $subscription = $this->createSubscription($domain);
        } elseif ($this->mapStatus($subscription['resource']['attributes']['state'] ?? '') === Status::FAILED) {
            $subscription = $this->retrySubscription($subscription['resource']['id']);
        }

        return $this->extractRenewDate($subscription);
    }

    public function isInstantGeneration(string $domain, ?string $domainType): bool
    {
        return false;
    }

    public function getCertificateStatus(string $domain, ?string $domainType): string
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return Status::UNKNOWN;
        }

        $status = $this->mapStatus($subscription['resource']['attributes']['state'] ?? '');
        if ($status === Status::ISSUED) {
            return $status;
        }

        $details = $this->getAuthorizationDetails($subscription, $domain);
        if ($status !== Status::FAILED && $details['blocked']) {
            $status = Status::BLOCKED;
        }

        if ($status === Status::BLOCKED || $status === Status::FAILED) {
            $message = $status === Status::BLOCKED ? 'Certificate issuance is blocked.' : 'Certificate issuance failed.';
            foreach ($details['instructions'] as $instruction) {
                $message .= ' ' . $instruction;
            }
            foreach ($details['records'] as $record) {
                $message .= ' Add a ' . $record['type'] . " record '" . $record['name'] . "' with value(s) '" . implode("', '", $record['values']) . "' in your DNS provider.";
            }

            throw new Certificate($message, $status, $details['records']);
        }

        return $status;
    }

    public function isRenewRequired(string $domain, ?string $domainType): bool
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return true;
        }

        return $this->mapStatus($subscription['resource']['attributes']['state'] ?? '') === Status::FAILED;
    }

    public function deleteCertificate(string $domain, ?string $domainType = null): void
    {
        $subscription = $this->findSubscription($domain);

        if ($subscription === null) {
            return;
        }

        if ($this->getSubscriptionDomains($subscription) !== [strtolower(rtrim($domain, '.'))]) {
            throw new \RuntimeException('Refusing to delete a Fastly TLS subscription without exclusive ownership of the requested domain.');
        }

        $result = $this->request(
            'DELETE',
            '/tls/subscriptions/' . $subscription['resource']['id'] . '?force=true',
        );

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to delete Fastly TLS subscription', $result));
        }
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}|null
     */
    private function findSubscription(string $domain): ?array
    {
        $query = http_build_query([
            'filter[tls_domains.id]' => $domain,
            'include' => 'tls_certificates,tls_authorizations',
            'page[size]' => 1,
        ]);

        $result = $this->request('GET', '/tls/subscriptions?' . $query);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to fetch Fastly TLS subscriptions', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS subscriptions response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;
        if (!\is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('Fastly TLS subscriptions response was missing its data list.');
        }

        $resource = $data[0] ?? null;
        if ($resource === null) {
            return null;
        }

        if (!\is_array($resource) || !\is_string($resource['id'] ?? null) || $resource['id'] === '') {
            throw new \RuntimeException('Fastly TLS subscription resource was malformed.');
        }

        $included = $result['response']['included'] ?? [];
        if (!\is_array($included)) {
            throw new \RuntimeException('Fastly TLS subscriptions response contained malformed included resources.');
        }

        return ['resource' => $resource, 'included' => array_values(array_filter($included, is_array(...)))];
    }

    /**
     * @param array{resource:array<string, mixed>,included:array<int, array<string, mixed>>} $subscription
     * @return list<string>|null
     */
    private function getSubscriptionDomains(array $subscription): ?array
    {
        $references = $subscription['resource']['relationships']['tls_domains']['data'] ?? null;
        if (!\is_array($references) || !array_is_list($references)) {
            return null;
        }

        $domains = [];
        foreach ($references as $reference) {
            if (!\is_array($reference) || ($reference['type'] ?? null) !== 'tls_domain' || !\is_string($reference['id'] ?? null) || $reference['id'] === '') {
                return null;
            }
            $domains[] = strtolower(rtrim($reference['id'], '.'));
        }

        return $domains;
    }

    /**
     * @param array{resource:array<string, mixed>,included:array<int, array<string, mixed>>} $subscription
     * @return array{blocked:bool,instructions:list<string>,records:list<array{type:string,name:string,values:list<string>}>}
     */
    private function getAuthorizationDetails(array $subscription, string $domain): array
    {
        $domain = strtolower(rtrim($domain, '.'));
        $challengeDomain = str_starts_with($domain, '*.') ? substr($domain, 2) : $domain;
        $relationships = $subscription['resource']['relationships'] ?? [];
        $domains = $this->getSubscriptionDomains($subscription);
        if ($domains === null || !\in_array($domain, $domains, true)) {
            return ['blocked' => false, 'instructions' => [], 'records' => []];
        }
        $singleDomain = $domains === [$domain];
        $references = $relationships['tls_authorizations']['data'] ?? [];
        $ids = [];
        if (\is_array($references)) {
            foreach ($references as $reference) {
                if (\is_array($reference) && ($reference['type'] ?? null) === 'tls_authorization' && \is_string($reference['id'] ?? null)) {
                    $ids[] = $reference['id'];
                }
            }
        }

        $blocked = false;
        $instructions = [];
        $records = [];
        foreach ($subscription['included'] as $authorization) {
            if (($authorization['type'] ?? null) !== 'tls_authorization') {
                continue;
            }
            if (!\in_array($authorization['id'] ?? null, $ids, true)) {
                continue;
            }
            $attributes = $authorization['attributes'] ?? [];
            if (!\is_array($attributes)) {
                continue;
            }
            $challenges = $attributes['challenges'] ?? [];
            $authorizationRecords = [];
            if (\is_array($challenges)) {
                foreach ($challenges as $challenge) {
                    if (!\is_array($challenge)) {
                        continue;
                    }
                    if (!\is_string($challenge['record_name'] ?? null)) {
                        continue;
                    }
                    if (!\is_string($challenge['record_type'] ?? null)) {
                        continue;
                    }
                    if (!\is_array($challenge['values'] ?? null)) {
                        continue;
                    }
                    $name = strtolower(rtrim($challenge['record_name'], '.'));
                    if ($name !== $challengeDomain && $name !== '_acme-challenge.' . $challengeDomain) {
                        continue;
                    }
                    $type = strtoupper(trim($challenge['record_type']));
                    $values = array_values(array_unique(array_filter($challenge['values'], static fn(mixed $value): bool => \is_string($value) && trim($value) !== '')));
                    if ($type === '') {
                        continue;
                    }
                    if ($values === []) {
                        continue;
                    }
                    $record = ['type' => $type, 'name' => $name, 'values' => $values];
                    if (!\in_array($record, $authorizationRecords, true)) {
                        $authorizationRecords[] = $record;
                    }
                }
            }

            // A shared subscription can contain authorizations for other domains.
            if (!$singleDomain && $authorizationRecords === []) {
                continue;
            }
            $blocked = $blocked || ($attributes['state'] ?? null) === 'blocked';
            $warnings = $attributes['warnings'] ?? [];
            if (\is_array($warnings)) {
                foreach ($warnings as $warning) {
                    $instruction = \is_array($warning) ? ($warning['instructions'] ?? null) : null;
                    if (\is_string($instruction) && trim($instruction) !== '' && !\in_array($instruction, $instructions, true)) {
                        $instructions[] = $instruction;
                    }
                }
            }
            foreach ($authorizationRecords as $record) {
                if (!\in_array($record, $records, true)) {
                    $records[] = $record;
                }
            }
        }

        return ['blocked' => $blocked, 'instructions' => $instructions, 'records' => $records];
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}
     */
    private function createSubscription(string $domain): array
    {
        $relationships = [
            'tls_domains' => [
                'data' => [[
                    'type' => 'tls_domain',
                    'id' => $domain,
                ]],
            ],
        ];

        if ($this->tlsConfigurationId !== '') {
            $relationships['common_name'] = [
                'data' => [
                    'type' => 'tls_domain',
                    'id' => $domain,
                ],
            ];
            $relationships['tls_configuration'] = [
                'data' => [
                    'type' => 'tls_configuration',
                    'id' => $this->tlsConfigurationId,
                ],
            ];
        }

        $result = $this->request('POST', '/tls/subscriptions', [
            'data' => [
                'type' => 'tls_subscription',
                'attributes' => [
                    'certificate_authority' => $this->certificateAuthority,
                ],
                'relationships' => $relationships,
            ],
        ]);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to create Fastly TLS subscription', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS subscription response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;

        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS subscription response was missing data.');
        }

        $included = $result['response']['included'] ?? [];

        return ['resource' => $data, 'included' => \is_array($included) ? array_values(array_filter($included, is_array(...))) : []];
    }

    /**
     * @return array{resource:array<string, mixed>,included:array<int, array<string, mixed>>}
     */
    private function retrySubscription(string $subscriptionId): array
    {
        $result = $this->request('PATCH', '/tls/subscriptions/' . $subscriptionId, [
            'data' => [
                'id' => $subscriptionId,
                'type' => 'tls_subscription',
                'attributes' => [
                    'state' => 'retry',
                ],
            ],
        ]);

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->formatError('Failed to retry Fastly TLS subscription', $result));
        }

        if (!\is_array($result['response'])) {
            throw new \RuntimeException('Fastly TLS retry response was not valid JSON.');
        }

        $data = $result['response']['data'] ?? null;

        if (!\is_array($data)) {
            throw new \RuntimeException('Fastly TLS retry response was missing data.');
        }

        $included = $result['response']['included'] ?? [];

        return ['resource' => $data, 'included' => \is_array($included) ? array_values(array_filter($included, is_array(...))) : []];
    }

    /**
     * @param array{resource:array<string, mixed>,included:array<int, array<string, mixed>>} $subscription
     */
    private function extractRenewDate(array $subscription): ?string
    {
        $resource = $subscription['resource'];
        $state = $this->mapStatus($resource['attributes']['state'] ?? '');

        if ($state !== Status::ISSUED && $state !== Status::RENEWING) {
            return null;
        }

        $relationship = $resource['relationships']['tls_certificates']['data'] ?? [];
        $certificateIds = [];
        if (\is_array($relationship)) {
            foreach ($relationship as $reference) {
                if (\is_array($reference) && \is_string($reference['id'] ?? null)) {
                    $certificateIds[] = $reference['id'];
                }
            }
        }

        $dates = [];
        foreach ($subscription['included'] as $included) {
            if (($included['type'] ?? null) !== 'tls_certificate') {
                continue;
            }
            if (!\in_array($included['id'] ?? null, $certificateIds, true)) {
                continue;
            }
            $notAfter = $included['attributes']['not_after'] ?? null;
            if (\is_string($notAfter) && $notAfter !== '') {
                $dates[] = $notAfter;
            }
        }

        if ($dates === []) {
            return null;
        }

        usort($dates, static fn(string $left, string $right): int => strtotime($right) <=> strtotime($left));
        $date = new \DateTimeImmutable($dates[0]);

        return $date->modify('-30 days')->format('Y-m-d H:i:s.v');
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
            ->withHeader(Header::USER_AGENT, 'Utopia CDN Fastly TLS Provider')
            ->withHeader('Fastly-Key', $this->apiToken)
            ->withHeader(Header::ACCEPT, 'application/vnd.api+json')
            ->withHeader(Header::CONTENT_TYPE, 'application/vnd.api+json');

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $error) {
            return ['statusCode' => 0, 'response' => null, 'error' => $error->getMessage()];
        }

        $contents = (string) $response->getBody();

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = $contents;
        }

        return ['statusCode' => $response->getStatusCode(), 'response' => $decoded, 'error' => null];
    }

    /**
     * @param array{statusCode:int,response:mixed,error:string|null} $result
     */
    private function formatError(string $prefix, array $result): string
    {
        $message = $result['error'] ?? null;

        if (\is_array($result['response'])) {
            $message ??= $result['response']['errors'][0]['detail']
                ?? $result['response']['errors'][0]['title']
                ?? $result['response']['msg']
                ?? null;
        }

        $message ??= 'Unknown Fastly TLS error';

        return $prefix . ' with status ' . $result['statusCode'] . ': ' . $message;
    }

    private function mapStatus(string $state): string
    {
        return match (strtolower($state)) {
            'pending' => Status::PENDING,
            'processing' => Status::PROCESSING,
            'issued' => Status::ISSUED,
            'renewing' => Status::RENEWING,
            'failed' => Status::FAILED,
            'blocked' => Status::BLOCKED,
            default => Status::UNKNOWN,
        };
    }
}
