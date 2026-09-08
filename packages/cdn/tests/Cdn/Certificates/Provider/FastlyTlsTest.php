<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider\FastlyTls;
use Utopia\Cdn\Certificates\Status;
use Utopia\Cdn\Exception\Certificate;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

final class FastlyTlsTest extends TestCase
{
    public function testBlockedAuthorizationForwardsDnsRecordsAndInstructions(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream(json_encode($this->blockedSubscription(), JSON_THROW_ON_ERROR))),
        ]);

        try {
            new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null);
            $this->fail('Expected an actionable certificate status.');
        } catch (Certificate $error) {
            $this->assertSame(Status::BLOCKED, $error->getStatus());
            $this->assertSame([
                ['type' => 'CNAME', 'name' => '_acme-challenge.example.com', 'values' => ['token.fastly-validations.com']],
            ], $error->getDnsRecords());
            $this->assertStringContainsString('DNS record is missing', $error->getMessage());
            $this->assertStringContainsString('_acme-challenge.example.com', $error->getMessage());
            $this->assertStringContainsString('token.fastly-validations.com', $error->getMessage());
        }

        $this->assertStringContainsString('include=tls_certificates%2Ctls_authorizations', $client->calls[0]['url']);
    }

    public function testFailedSubscriptionPreservesProviderInstructions(): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['attributes']['state'] = 'failed';
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null);
            $this->fail('Expected an explicit certificate failure.');
        } catch (Certificate $error) {
            $this->assertSame(Status::FAILED, $error->getStatus());
            $this->assertStringContainsString('DNS record is missing', $error->getMessage());
            $this->assertCount(1, $error->getDnsRecords());
        }
    }

    #[DataProvider('failureStates')]
    public function testFailureWithoutAuthorizationDetails(string $state, string $message): void
    {
        $body = ['data' => [['id' => 'sub_1', 'attributes' => ['state' => $state]]]];
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null);
            $this->fail('Expected a certificate failure without authorization metadata.');
        } catch (Certificate $error) {
            $this->assertSame($state, $error->getStatus());
            $this->assertSame([], $error->getDnsRecords());
            $this->assertSame($message, $error->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function failureStates(): iterable
    {
        yield 'blocked' => [Status::BLOCKED, 'Certificate issuance is blocked.'];
        yield 'failed' => [Status::FAILED, 'Certificate issuance failed.'];
    }

    public function testAuthorizationChallengesAreAlternativeValidationOptions(): void
    {
        $body = $this->blockedSubscription();
        $body['included'][0]['attributes']['challenges'][] = [
            'type' => 'managed-http-cname',
            'record_name' => 'example.com',
            'record_type' => 'CNAME',
            'values' => ['j.sni.global.fastly.net'],
        ];
        $body['included'][0]['attributes']['challenges'][] = [
            'type' => 'managed-http-a',
            'record_name' => 'example.com',
            'record_type' => 'A',
            'values' => ['151.101.0.0', '151.101.64.0'],
        ];
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null);
            $this->fail('Expected DNS verification options.');
        } catch (Certificate $error) {
            $this->assertSame([
                ['type' => 'CNAME', 'name' => '_acme-challenge.example.com', 'values' => ['token.fastly-validations.com']],
                ['type' => 'CNAME', 'name' => 'example.com', 'values' => ['j.sni.global.fastly.net']],
                ['type' => 'A', 'name' => 'example.com', 'values' => ['151.101.0.0', '151.101.64.0']],
            ], $error->getDnsRecords());
            $this->assertStringContainsString('Choose the validation method required by the provider instructions for each authorization', $error->getMessage());
            $this->assertStringContainsString('CNAME and A records at the same hostname are alternatives', $error->getMessage());
        }
    }

    public function testUnrelatedAuthorizationDoesNotBlockSubscription(): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['relationships']['tls_authorizations']['data'][0]['id'] = 'another_auth';
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        $this->assertSame(Status::PENDING, new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null));
    }

    public function testIssuedSubscriptionIgnoresStaleBlockedAuthorization(): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['attributes']['state'] = 'issued';
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        $this->assertSame(Status::ISSUED, new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null));
    }

    public function testWarningsAloneDoNotFailPendingSubscription(): void
    {
        $body = $this->blockedSubscription();
        $body['included'][0]['attributes']['state'] = 'pending';
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        $this->assertSame(Status::PENDING, new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null));
    }

    public function testBlockedAuthorizationWithoutChallengeStillForwardsInstructions(): void
    {
        $body = $this->blockedSubscription();
        $body['included'][0]['attributes']['challenges'] = [];
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null);
            $this->fail('Expected an actionable certificate status.');
        } catch (Certificate $error) {
            $this->assertSame(Status::BLOCKED, $error->getStatus());
            $this->assertSame([], $error->getDnsRecords());
            $this->assertStringContainsString('DNS record is missing', $error->getMessage());
        }
    }

    public function testSharedSubscriptionIgnoresAnotherDomainsBlockedAuthorization(): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['relationships']['tls_domains']['data'][] = ['type' => 'tls_domain', 'id' => 'other.com'];
        $body['included'][0]['attributes']['challenges'][0]['record_name'] = '_acme-challenge.other.com';
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        $this->assertSame(Status::PENDING, new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null));
    }

    public function testSharedSubscriptionForwardsOnlyRequestedDomainsChallenges(): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['relationships']['tls_domains']['data'][] = ['type' => 'tls_domain', 'id' => 'other.com'];
        $body['data'][0]['relationships']['tls_authorizations']['data'][] = ['type' => 'tls_authorization', 'id' => 'auth_2'];
        $body['included'][] = [
            'id' => 'auth_2',
            'type' => 'tls_authorization',
            'attributes' => [
                'state' => 'blocked',
                'warnings' => [['instructions' => 'Other domain warning']],
                'challenges' => [['type' => 'managed-dns', 'record_name' => '_acme-challenge.other.com', 'record_type' => 'CNAME', 'values' => ['other.fastly-validations.com']]],
            ],
        ];
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null);
            $this->fail('Expected an actionable certificate status.');
        } catch (Certificate $error) {
            $this->assertCount(1, $error->getDnsRecords());
            $this->assertStringNotContainsString('other', $error->getMessage());
        }
    }

    public function testAuthorizationForAnotherSubscriptionDomainIsIgnored(): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['relationships']['tls_domains']['data'][0]['id'] = 'other.com';
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        $this->assertSame(Status::PENDING, new FastlyTls('token', 'config', client: $client)->getCertificateStatus('example.com', null));
    }

    public function testWildcardChallengeRecordsAreNormalizedAndDeduplicated(): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['relationships']['tls_domains']['data'][0]['id'] = '*.example.com';
        $body['included'][0]['attributes']['challenges'][0]['record_name'] = '_ACME-CHALLENGE.EXAMPLE.COM.';
        $body['included'][0]['attributes']['challenges'][0]['record_type'] = 'cname';
        $body['included'][0]['attributes']['challenges'][0]['values'][] = 'token.fastly-validations.com';
        $body['included'][0]['attributes']['challenges'][0]['values'][] = '';
        $body['included'][0]['attributes']['challenges'][0]['values'][] = ['malformed'];
        $body['included'][0]['attributes']['challenges'][] = $body['included'][0]['attributes']['challenges'][0];
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->getCertificateStatus('*.example.com', null);
            $this->fail('Expected an actionable certificate status.');
        } catch (Certificate $error) {
            $this->assertSame([
                ['type' => 'CNAME', 'name' => '_acme-challenge.example.com', 'values' => ['token.fastly-validations.com']],
            ], $error->getDnsRecords());
        }
    }

    /** @return array<string, mixed> */
    private function blockedSubscription(): array
    {
        return [
            'data' => [[
                'id' => 'sub_1',
                'type' => 'tls_subscription',
                'attributes' => ['state' => 'pending'],
                'relationships' => [
                    'tls_domains' => ['data' => [['type' => 'tls_domain', 'id' => 'example.com']]],
                    'tls_authorizations' => ['data' => [['type' => 'tls_authorization', 'id' => 'auth_1']]],
                ],
            ]],
            'included' => [[
                'id' => 'auth_1',
                'type' => 'tls_authorization',
                'attributes' => [
                    'state' => 'blocked',
                    'warnings' => [['type' => 'dns', 'instructions' => 'DNS record is missing']],
                    'challenges' => [[
                        'type' => 'managed-dns',
                        'record_name' => '_acme-challenge.example.com',
                        'record_type' => 'CNAME',
                        'values' => ['token.fastly-validations.com'],
                    ]],
                ],
            ]],
        ];
    }

    public function testIssueCertificateCreatesSubscriptionWhenMissing(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[]}')),
            new Response(200, body: new Stream('{"data":{"id":"sub_123","attributes":{"state":"pending"}}}')),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $renewDate = $provider->issueCertificate('ignored', 'example.com', null);

        $this->assertNull($renewDate);
        $this->assertCount(2, $client->calls);
        $this->assertSame('GET', $client->calls[0]['method']);
        $this->assertStringContainsString('filter%5Btls_domains.id%5D=example.com', $client->calls[0]['url']);
        $this->assertSame('POST', $client->calls[1]['method']);
        $this->assertSame('tls-config-id', $client->calls[1]['body']['data']['relationships']['tls_configuration']['data']['id']);
    }

    public function testIssueCertificateCanUseFastlyDomainManagementWithoutAConfiguration(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[]}')),
            new Response(201, body: new Stream('{"data":{"id":"sub_123","attributes":{"state":"pending"}}}')),
        ]);

        new FastlyTls('token', '', 'certainly', $client)->issueCertificate('cert', 'example.com', null);

        $relationships = $client->calls[1]['body']['data']['relationships'];
        $this->assertSame('example.com', $relationships['tls_domains']['data'][0]['id']);
        $this->assertArrayNotHasKey('common_name', $relationships);
        $this->assertArrayNotHasKey('tls_configuration', $relationships);
    }

    public function testGetCertificateStatusMapsFastlyState(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}')),
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"issued"}}]}')),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);

        $this->assertSame(Status::ISSUED, $provider->getCertificateStatus('example.com', null));
        $this->assertFalse($provider->isRenewRequired('example.com', null));
    }

    public function testDeleteCertificateRemovesSubscription(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}')),
            new Response(204),
        ]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $provider->deleteCertificate('example.com');

        $this->assertCount(2, $client->calls);
        $this->assertSame('DELETE', $client->calls[1]['method']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_123?force=true', $client->calls[1]['url']);
    }

    /** @param list<string> $domains */
    #[DataProvider('otherDomains')]
    public function testDeletePreservesSubscriptionsForOtherDomains(array $domains): void
    {
        $body = $this->blockedSubscription();
        $body['data'][0]['relationships']['tls_domains']['data'] = array_map(
            static fn(string $domain): array => ['type' => 'tls_domain', 'id' => $domain],
            $domains,
        );
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected cleanup to preserve other domains.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('exclusive ownership', $error->getMessage());
        }
        $this->assertCount(1, $client->calls);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function otherDomains(): iterable
    {
        yield 'shared subscription' => [['example.com', 'other.com']];
        yield 'different domain' => [['other.com']];
    }

    public function testDeleteRequiresReadableSubscriptionDomains(): void
    {
        $body = $this->blockedSubscription();
        unset($body['data'][0]['relationships']['tls_domains']);
        $client = new TestClient([new Response(200, body: new Stream(json_encode($body, JSON_THROW_ON_ERROR)))]);

        try {
            new FastlyTls('token', 'config', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected unreadable subscription ownership to stop cleanup.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('exclusive ownership', $error->getMessage());
        }
        $this->assertCount(1, $client->calls);
    }

    public function testIssueCertificateReturnsRenewDateFromIncludedCertificate(): void
    {
        $client = new TestClient([new Response(200, body: new Stream(json_encode([
            'data' => [[
                'id' => 'sub_123',
                'attributes' => ['state' => 'issued'],
                'relationships' => ['tls_certificates' => ['data' => [['type' => 'tls_certificate', 'id' => 'cert_1']]]],
            ]],
            'included' => [[
                'type' => 'tls_certificate',
                'id' => 'cert_1',
                'attributes' => ['not_after' => '2027-02-01T00:00:00Z'],
            ]],
        ])))]);

        $provider = new FastlyTls('token', 'tls-config-id', 'certainly', $client);
        $this->assertSame('2027-01-02 00:00:00.000', $provider->issueCertificate('cert', 'example.com', null));
    }

    public function testRetriesFailedSubscription(): void
    {
        $client = new TestClient([
            new Response(200, body: new Stream('{"data":[{"id":"sub_123","attributes":{"state":"failed"}}]}')),
            new Response(200, body: new Stream('{"data":{"id":"sub_123","attributes":{"state":"processing"}}}')),
        ]);
        $provider = new FastlyTls('token', 'config', 'certainly', $client);
        $this->assertNull($provider->issueCertificate('cert', 'example.com', null));
        $this->assertSame('PATCH', $client->calls[1]['method']);
    }

    public function testRejectsMalformedSuccessfulResponse(): void
    {
        $provider = new FastlyTls('token', 'config', 'certainly', new TestClient([new Response(200, body: new Stream('not-json'))]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('valid JSON');
        $provider->getCertificateStatus('example.com', null);
    }
}
