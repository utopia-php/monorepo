<?php

declare(strict_types=1);

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider\Fastly;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

final class FastlyTest extends TestCase
{
    public function testIssueLinksOrphanBeforeCreatingTlsSubscription(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            $this->json('{"id":"domain_1","fqdn":"example.com","service_id":"service_1"}'),
            $this->json('{"data":[]}'),
            $this->json('{"data":{"id":"sub_1","attributes":{"state":"pending"}}}', 201),
        ]);

        $this->assertNull(new Fastly('token', 'service_1', client: $client)->issueCertificate('cert', 'example.com', null));

        $this->assertCount(8, $client->calls);
        $this->assertSame('https://api.fastly.com/service/service_1/details', $client->calls[1]['url']);
        $this->assertSame('PATCH', $client->calls[5]['method']);
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[5]['url']);
        $this->assertSame(['service_id' => 'service_1'], $client->calls[5]['body']);
        $this->assertSame('POST', $client->calls[7]['method']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions', $client->calls[7]['url']);
        $this->assertSame('example.com', $client->calls[7]['body']['data']['relationships']['tls_domains']['data'][0]['id']);
    }

    public function testRenewIsRequiredForOrphan(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
        ]);

        $this->assertTrue(new Fastly('token', 'service_1', client: $client)->isRenewRequired('example.com', null));
        $this->assertCount(5, $client->calls);
        $this->assertSame('https://api.fastly.com/service/service_1/details', $client->calls[1]['url']);
    }

    public function testIssueLeavesClassicDomainUntouched(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"EXAMPLE.COM"}]}}'),
        ]);

        $this->assertNull(new Fastly('token', 'service_1', client: $client)->issueCertificate('cert', 'example.com', null));
        $this->assertSame(['GET', 'GET'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/service/service_1/details', $client->calls[1]['url']);
    }

    #[DataProvider('operations')]
    public function testClassicDomainOnAnotherServiceRemainsUntouched(bool $renew): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"},{"id":"other_service"}]'),
            $this->json('{"active_version":{"number":7,"domains":[{"name":"example.com"}]}}'),
        ]);
        $provider = new Fastly('token', 'service_1', client: $client);

        if ($renew) {
            $this->assertFalse($provider->isRenewRequired('example.com', null));
        } else {
            $this->assertNull($provider->issueCertificate('cert', 'example.com', null));
        }

        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'GET'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/service/other_service/details', $client->calls[5]['url']);
    }

    public function testClassicOwnershipCheckContinuesToNextServicesPage(): void
    {
        $services = [['id' => 'service_1']];
        $responses = [
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
        ];
        for ($index = 1; $index < 20; $index++) {
            $services[] = ['id' => 'inactive_' . $index];
        }
        $responses[] = $this->json(json_encode($services, JSON_THROW_ON_ERROR));
        for ($index = 1; $index < 20; $index++) {
            $responses[] = $this->json('{"active_version":null}');
        }
        $responses[] = $this->json('[{"id":"other_service"}]');
        $responses[] = $this->json('{"active_version":{"number":7,"domains":[{"name":"example.com"}]}}');
        $client = new TestClient($responses);

        $this->assertNull(new Fastly('token', 'service_1', client: $client)->issueCertificate('cert', 'example.com', null));
        $this->assertCount(26, $client->calls);
        $this->assertSame('https://api.fastly.com/service?page=2&per_page=20', $client->calls[24]['url']);
        $this->assertSame(['GET'], array_values(array_unique(array_column($client->calls, 'method'))));
    }

    public function testFailedOrphanAssociationDoesNotRequestTls(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            $this->json('{"msg":"service unavailable"}', 503),
        ]);

        try {
            new Fastly('token', 'service_1', client: $client)->issueCertificate('cert', 'example.com', null);
            $this->fail('Expected orphan association to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('link Fastly domain', $error->getMessage());
        }

        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'PATCH'], array_column($client->calls, 'method'));
        $this->assertSame(['service_id' => 'service_1'], $client->calls[5]['body']);
    }

    public function testIssueResumesAfterTlsFailsOnLinkedOrphan(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            $this->json('{"id":"domain_1","fqdn":"example.com","service_id":"service_1"}'),
            $this->json('{"msg":"TLS unavailable"}', 503),
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"service_1"}]}'),
            $this->json('{"data":[]}'),
            $this->json('{"data":{"id":"sub_1","attributes":{"state":"pending"}}}', 201),
        ]);
        $provider = new Fastly('token', 'service_1', client: $client);

        try {
            $provider->issueCertificate('cert', 'example.com', null);
            $this->fail('Expected TLS issuance to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('fetch Fastly TLS subscriptions', $error->getMessage());
        }

        $this->assertNull($provider->issueCertificate('cert', 'example.com', null));
        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'PATCH', 'GET', 'GET', 'GET', 'POST'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/tls/subscriptions', $client->calls[9]['url']);
    }

    #[DataProvider('unreadableOwnership')]
    public function testUnreadableClassicOwnershipStopsBeforeMutation(bool $renew, string $body, int $status): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json($body, $status),
        ]);
        $provider = new Fastly('token', 'service_1', client: $client);

        try {
            if ($renew) {
                $provider->isRenewRequired('example.com', null);
            } else {
                $provider->issueCertificate('cert', 'example.com', null);
            }
            $this->fail('Expected unreadable ownership to stop issuance.');
        } catch (\RuntimeException) {
            $this->assertSame(['GET', 'GET'], array_column($client->calls, 'method'));
        }
    }

    #[DataProvider('unreadableServices')]
    public function testUnreadableServicesStopBeforeMutation(string $body, int $status): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json($body, $status),
        ]);

        try {
            new Fastly('token', 'service_1', client: $client)->issueCertificate('cert', 'example.com', null);
            $this->fail('Expected unreadable service inventory to stop issuance.');
        } catch (\RuntimeException) {
            $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET'], array_column($client->calls, 'method'));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function operations(): iterable
    {
        yield 'issue' => [false];
        yield 'renew' => [true];
    }

    /** @return iterable<string, array{bool, string, int}> */
    public static function unreadableOwnership(): iterable
    {
        foreach (['issue' => false, 'renew' => true] as $operation => $renew) {
            yield $operation . ' denied' => [$renew, '{"msg":"forbidden"}', 403];
            yield $operation . ' unavailable' => [$renew, '{"msg":"unavailable"}', 503];
            yield $operation . ' invalid JSON' => [$renew, 'not JSON', 200];
            yield $operation . ' missing version' => [$renew, '{}', 200];
            yield $operation . ' missing domains' => [$renew, '{"active_version":{"number":3}}', 200];
            yield $operation . ' invalid domains' => [$renew, '{"active_version":{"domains":[{}]}}', 200];
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function unreadableServices(): iterable
    {
        yield 'denied' => ['{"msg":"forbidden"}', 403];
        yield 'invalid list' => ['{"data":[]}', 200];
        yield 'missing service ID' => ['[{}]', 200];
        yield 'repeated service ID' => ['[{"id":"service_1"},{"id":"service_1"}]', 200];
        yield 'configured service omitted' => ['[]', 200];
    }

    #[DataProvider('operations')]
    public function testRoutingConfigurationRemainsUntouched(bool $renew): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null,"routing_configuration_id":"routing_1"}]}'),
        ]);
        $provider = new Fastly('token', 'service_1', client: $client);

        if ($renew) {
            $this->assertFalse($provider->isRenewRequired('example.com', null));
        } else {
            $this->assertNull($provider->issueCertificate('cert', 'example.com', null));
        }

        $this->assertSame(['GET'], array_column($client->calls, 'method'));
    }

    #[DataProvider('malformedAssociations')]
    public function testMalformedAssociationStopsBeforeMutation(bool $renew, string $attributes): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com",' . $attributes . '}]}'),
        ]);
        $provider = new Fastly('token', 'service_1', client: $client);

        try {
            if ($renew) {
                $provider->isRenewRequired('example.com', null);
            } else {
                $provider->issueCertificate('cert', 'example.com', null);
            }
            $this->fail('Expected malformed domain association to stop issuance.');
        } catch (\RuntimeException) {
            $this->assertSame(['GET'], array_column($client->calls, 'method'));
        }
    }

    /** @return iterable<string, array{bool, string}> */
    public static function malformedAssociations(): iterable
    {
        foreach (['issue' => false, 'renew' => true] as $operation => $renew) {
            foreach (['service_id', 'routing_configuration_id'] as $attribute) {
                foreach (['false', '123', '[]', '""'] as $value) {
                    yield $operation . ' ' . $attribute . ' ' . $value => [$renew, '"' . $attribute . '":' . $value];
                }
            }
        }
    }

    #[DataProvider('operations')]
    public function testClassicWildcardOnAnotherServiceRemainsUntouched(bool $renew): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"api.eu.example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"},{"id":"other_service"}]'),
            $this->json('{"active_version":{"number":7,"domains":[{"name":"*.example.com"}]}}'),
        ]);
        $provider = new Fastly('token', 'service_1', client: $client);

        if ($renew) {
            $this->assertFalse($provider->isRenewRequired('api.eu.example.com', null));
        } else {
            $this->assertNull($provider->issueCertificate('cert', 'api.eu.example.com', null));
        }

        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'GET'], array_column($client->calls, 'method'));
    }

    public function testClassicWildcardDoesNotMatchAnotherSuffix(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"notexample.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"},{"id":"other_service"}]'),
            $this->json('{"active_version":{"number":7,"domains":[{"name":"*.example.com"}]}}'),
        ]);

        $this->assertTrue(new Fastly('token', 'service_1', client: $client)->isRenewRequired('notexample.com', null));
        $this->assertCount(6, $client->calls);
    }

    #[DataProvider('restrictedCredentials')]
    public function testRestrictedCredentialsStopBeforeInventory(bool $renew, string $token, ?string $user): void
    {
        $responses = [
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json($token),
        ];
        if ($user !== null) {
            $responses[] = $this->json($user);
        }
        $client = new TestClient($responses);
        $provider = new Fastly('token', 'service_1', client: $client);

        try {
            if ($renew) {
                $provider->isRenewRequired('example.com', null);
            } else {
                $provider->issueCertificate('cert', 'example.com', null);
            }
            $this->fail('Expected incomplete service visibility to stop issuance.');
        } catch (\RuntimeException) {
            $this->assertCount(\count($responses), $client->calls);
            $this->assertSame(['GET'], array_values(array_unique(array_column($client->calls, 'method'))));
            $this->assertSame(
                $user === null ? 'https://api.fastly.com/tokens/self' : 'https://api.fastly.com/current_user',
                array_last($client->calls)['url'],
            );
        }
    }

    /** @return iterable<string, array{bool, string, ?string}> */
    public static function restrictedCredentials(): iterable
    {
        foreach (['issue' => false, 'renew' => true] as $operation => $renew) {
            yield $operation . ' scoped token' => [$renew, '{"services":["service_1"]}', null];
            yield $operation . ' unknown token scope' => [$renew, '{}', null];
            yield $operation . ' malformed token scope' => [$renew, '{"services":false}', null];
            yield $operation . ' restricted user' => [$renew, '{"services":[]}', '{"limit_services":true}'];
            yield $operation . ' unknown user scope' => [$renew, '{"services":[]}', '{}'];
            yield $operation . ' malformed user scope' => [$renew, '{"services":[]}', '{"limit_services":0}'];
        }
    }

    public function testIssueCreatesDomainAndTlsSubscription(): void
    {
        $client = new TestClient([
            $this->json('{"data":[]}'),
            $this->json('{}', 201),
            $this->json('{"data":[]}'),
            $this->json('{"data":{"id":"sub_1","attributes":{"state":"pending"}}}', 201),
        ]);

        $provider = new Fastly('token', 'service_1', client: $client);
        $this->assertNull($provider->issueCertificate('cert', 'example.com', null));

        $this->assertSame('POST', $client->calls[1]['method']);
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains', $client->calls[1]['url']);
        $this->assertSame(['fqdn' => 'example.com', 'service_id' => 'service_1'], $client->calls[1]['body']);
        $this->assertSame('POST', $client->calls[3]['method']);
        $this->assertSame('example.com', $client->calls[3]['body']['data']['relationships']['tls_domains']['data'][0]['id']);
    }

    public function testIssueReassignsVersionlessDomainFromAnotherService(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"old_service"}]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            $this->json('{"id":"domain_1","fqdn":"example.com","service_id":"new_service"}'),
        ]);

        new Fastly('token', 'new_service', client: $client)->issueCertificate('cert', 'example.com', null);

        $this->assertCount(4, $client->calls);
        $this->assertStringStartsWith('https://api.fastly.com/tls/subscriptions?', $client->calls[1]['url']);
        $this->assertStringStartsWith('https://api.fastly.com/tls/subscriptions?', $client->calls[2]['url']);
        $this->assertSame('PATCH', $client->calls[3]['method']);
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[3]['url']);
        $this->assertSame(['service_id' => 'new_service'], $client->calls[3]['body']);
    }

    public function testPendingTlsDoesNotReassignVersionlessDomain(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"old_service"}]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"pending"}}]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"pending"}}]}'),
        ]);

        $this->assertNull(new Fastly('token', 'new_service', client: $client)->issueCertificate('cert', 'example.com', null));

        $this->assertCount(3, $client->calls);
        $this->assertSame('GET', $client->calls[2]['method']);
        $this->assertStringStartsWith('https://api.fastly.com/tls/subscriptions?', $client->calls[2]['url']);
    }

    public function testFailedVersionlessReassignmentLeavesDomainAndTlsUntouched(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"old_service"}]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            $this->json('{"msg":"service unavailable"}', 503),
        ]);

        try {
            new Fastly('token', 'new_service', client: $client)->issueCertificate('cert', 'example.com', null);
            $this->fail('Expected domain reassignment to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('reassign Fastly domain', $error->getMessage());
        }

        $this->assertCount(4, $client->calls);
        $this->assertStringStartsWith('https://api.fastly.com/tls/subscriptions?', $client->calls[1]['url']);
        $this->assertStringStartsWith('https://api.fastly.com/tls/subscriptions?', $client->calls[2]['url']);
        $this->assertSame('PATCH', $client->calls[3]['method']);
    }

    public function testTlsFailureStopsBeforeVersionlessDomainReassignment(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"old_service"}]}'),
            $this->json('{"msg":"TLS unavailable"}', 503),
        ]);

        try {
            new Fastly('token', 'new_service', client: $client)->issueCertificate('cert', 'example.com', null);
            $this->fail('Expected TLS issuance to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('fetch Fastly TLS subscriptions', $error->getMessage());
        }

        $this->assertCount(2, $client->calls);
        $this->assertStringStartsWith('https://api.fastly.com/tls/subscriptions?', $client->calls[1]['url']);
    }

    public function testRenewIsRequiredWhenDomainBelongsToAnotherService(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"old_service"}]}'),
        ]);

        $renewRequired = new Fastly('token', 'new_service', client: $client)
            ->isRenewRequired('example.com', null);

        $this->assertTrue($renewRequired);
        $this->assertCount(1, $client->calls);
    }

    public function testRenewIsNotRequiredForClassicDomain(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com"}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"example.com"}]}}'),
        ]);

        $renewRequired = new Fastly('token', 'service_1', client: $client)
            ->isRenewRequired('example.com', null);

        $this->assertFalse($renewRequired);
        $this->assertCount(2, $client->calls);
    }

    public function testRenewDelegatesToTlsWhenDomainBelongsToService(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"service_1"}]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
        ]);

        $renewRequired = new Fastly('token', 'service_1', client: $client)
            ->isRenewRequired('example.com', null);

        $this->assertFalse($renewRequired);
        $this->assertCount(2, $client->calls);
    }

    public function testDeleteRemovesOrphanDomainBeforeTlsSubscription(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            new Response(204),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            new Response(204),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');

        $this->assertCount(8, $client->calls);
        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'DELETE', 'GET', 'DELETE'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[5]['url']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[7]['url']);
    }

    #[TestWith([409])]
    #[TestWith([503])]
    public function testFailedOrphanDeletionLeavesTlsUntouched(int $status): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            $this->json('{"msg":"domain deletion failed"}', $status),
        ]);

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected domain deletion to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('delete Fastly domain', $error->getMessage());
        }

        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'DELETE'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[5]['url']);
    }

    public function testDeleteRetriesTlsAfterOrphanDomainWasRemoved(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            new Response(204),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            $this->json('{"msg":"TLS deletion unavailable"}', 503),
            $this->json('{"data":[]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            new Response(204),
        ]);
        $provider = new Fastly('token', 'service_1', client: $client);

        try {
            $provider->deleteCertificate('example.com');
            $this->fail('Expected TLS deletion to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('delete Fastly TLS subscription', $error->getMessage());
        }

        $provider->deleteCertificate('example.com');

        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'DELETE', 'GET', 'DELETE', 'GET', 'GET', 'GET', 'GET', 'GET', 'GET', 'DELETE'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[5]['url']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[7]['url']);
        $this->assertSame($client->calls[7]['url'], $client->calls[14]['url']);
    }

    #[TestWith([null])]
    #[TestWith(['service_1'])]
    public function testDeleteLeavesRoutingConfigurationUntouched(?string $serviceId): void
    {
        $client = new TestClient([
            $this->json(json_encode(['data' => [[
                'id' => 'domain_1',
                'fqdn' => 'example.com',
                'service_id' => $serviceId,
                'routing_configuration_id' => 'routing_1',
            ]]], JSON_THROW_ON_ERROR)),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');

        $this->assertSame(['GET'], array_column($client->calls, 'method'));
    }

    #[DataProvider('invalidDeletionAssociations')]
    public function testDeleteRejectsMalformedAssociation(string $attributes): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com",' . $attributes . '}]}'),
        ]);

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected malformed domain association to stop deletion.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('invalid', $error->getMessage());
        }

        $this->assertSame(['GET'], array_column($client->calls, 'method'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDeletionAssociations(): iterable
    {
        foreach (['service_id', 'routing_configuration_id'] as $attribute) {
            foreach (['false', '123', '[]', '""'] as $value) {
                yield $attribute . ' ' . $value => ['"' . $attribute . '":' . $value];
            }
        }
    }

    /** @param list<array{string, int}> $responses */
    #[DataProvider('deletionOwnershipFailures')]
    public function testUnknownOwnershipStopsBeforeDeletion(array $responses): void
    {
        $client = new TestClient(array_map(
            fn(array $response): Response => $this->json($response[0], $response[1]),
            $responses,
        ));

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected incomplete domain ownership to stop deletion.');
        } catch (\RuntimeException) {
            $this->assertCount(\count($responses), $client->calls);
            $this->assertSame(['GET'], array_values(array_unique(array_column($client->calls, 'method'))));
        }
    }

    /** @return iterable<string, array{list<array{string, int}>}> */
    public static function deletionOwnershipFailures(): iterable
    {
        $domain = ['{"data":[{"id":"domain_1","fqdn":"example.com","service_id":null}]}', 200];
        $details = ['{"active_version":{"number":3,"domains":[]}}', 200];
        $token = ['{"services":[]}', 200];
        $user = ['{"limit_services":false}', 200];

        yield 'domain lookup denied' => [[['{"msg":"forbidden"}', 403]]];
        yield 'own service unavailable' => [[$domain, ['{"msg":"unavailable"}', 503]]];
        yield 'malformed domains' => [[$domain, ['{"active_version":{"domains":[{}]}}', 200]]];
        yield 'restricted token' => [[$domain, $details, ['{"services":["service_1"]}', 200]]];
        yield 'restricted user' => [[$domain, $details, $token, ['{"limit_services":true}', 200]]];
        yield 'missing service inventory' => [[$domain, $details, $token, $user, ['[]', 200]]];
        yield 'foreign service unreadable' => [[
            $domain,
            $details,
            $token,
            $user,
            ['[{"id":"service_1"},{"id":"other_service"}]', 200],
            ['{"msg":"forbidden"}', 403],
        ]];
    }

    public function testDeletePreservesClassicWildcardOnOwnService(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"api.example.com","service_id":null}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"*.example.com"}]}}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"*.example.com"}]}}'),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('api.example.com');

        $this->assertSame(['GET', 'GET', 'GET'], array_column($client->calls, 'method'));
    }

    #[DataProvider('malformedDomainLookups')]
    public function testMalformedDomainLookupCannotTriggerTlsDeletion(string $body): void
    {
        $client = new TestClient([$this->json($body)]);

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected an unreadable domain lookup to stop deletion.');
        } catch (\RuntimeException) {
            $this->assertSame(['GET'], array_column($client->calls, 'method'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function malformedDomainLookups(): iterable
    {
        yield 'invalid JSON' => ['not JSON'];
        yield 'missing object' => ['[]'];
        yield 'missing data' => ['{}'];
        yield 'data object' => ['{"data":{}}'];
        yield 'malformed candidate' => ['{"data":[{}]}'];
        yield 'missing candidate' => ['{"data":[null]}'];
        yield 'invalid fqdn' => ['{"data":[{"fqdn":false}]}'];
        yield 'empty fqdn' => ['{"data":[{"fqdn":""}]}'];
        yield 'malformed after exact match' => ['{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"service_1"},{}]}'];
        yield 'duplicate exact match' => ['{"data":[{"fqdn":"example.com"},{"fqdn":"example.com"}]}'];
        yield 'malformed metadata' => ['{"data":[],"meta":[]}'];
        yield 'unreadable cursor' => ['{"data":[],"meta":{"next_cursor":false}}'];
        yield 'more results' => ['{"data":[{"fqdn":"other.example.com"}],"meta":{"next_cursor":"next"}}'];
    }

    public function testFullNonmatchingDomainPageCannotTriggerTlsDeletion(): void
    {
        $domains = [];
        for ($index = 0; $index < 100; $index++) {
            $domains[] = ['fqdn' => 'other' . $index . '.example.com'];
        }
        $client = new TestClient([$this->json(json_encode(['data' => $domains], JSON_THROW_ON_ERROR))]);

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected an incomplete lookup to stop deletion.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('complete exact result', $error->getMessage());
        }

        $this->assertSame(['GET'], array_column($client->calls, 'method'));
        $this->assertStringContainsString('fqdn=example.com&fqdn_match=exact', $client->calls[0]['url']);
    }

    #[TestWith(['{"msg":"Token lacks domain permissions"}'])]
    #[TestWith(['{"errors":[{"detail":"Token lacks domain permissions"}]}'])]
    #[TestWith(['{"errors":[{"title":"Token lacks domain permissions"}]}'])]
    public function testDomainLookupFailureRetainsProviderError(string $body): void
    {
        $client = new TestClient([$this->json($body, 403)]);

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected domain lookup to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Token lacks domain permissions', $error->getMessage());
        }

        $this->assertSame(['GET'], array_column($client->calls, 'method'));
    }

    public function testDeleteRemovesVersionlessSubscriptionAndDomain(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"service_1"}]}'),
            new Response(204),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            new Response(204),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');

        $this->assertSame('DELETE', $client->calls[1]['method']);
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[1]['url']);
        $this->assertSame('DELETE', $client->calls[3]['method']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[3]['url']);
    }

    public function testFailedVersionlessDomainDeletionLeavesTlsSubscriptionUntouched(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"service_1"}]}'),
            $this->json('{"msg":"domain is still active"}', 409),
        ]);

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected domain deletion to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('delete Fastly domain', $error->getMessage());
        }

        $this->assertCount(2, $client->calls);
        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[1]['url']);
    }

    public function testVersionlessDomainOwnedByAnotherServiceIsNotDeleted(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"other_service"}]}'),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');

        $this->assertCount(1, $client->calls);
        $this->assertStringContainsString('/domain-management/v1/domains?', $client->calls[0]['url']);
    }

    public function testDeleteActivatesClassicServiceWithoutDomainBeforeRemovingTls(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com"}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"example.com"}]}}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"example.com"}]}}'),
            $this->json('{"number":4}'),
            $this->json('{}'),
            $this->json('{}'),
            $this->json('{"active":false}'),
            $this->json('{"active":true}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            new Response(204),
        ]);

        $provider = new Fastly(
            'token',
            'service_1',
            client: $client,
            deploymentPollAttempts: 2,
            deploymentPollIntervalMilliseconds: 0,
        );
        $provider->deleteCertificate('example.com');

        $this->assertSame('PUT', $client->calls[3]['method']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/3/clone', $client->calls[3]['url']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/4/domain/example.com', $client->calls[4]['url']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/4/activate', $client->calls[5]['url']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[9]['url']);
    }

    public function testClassicDomainConflictStopsBeforeActivationAndTlsDeletion(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com"}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"example.com"}]}}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"example.com"}]}}'),
            $this->json('{"number":4}'),
            $this->json('{"msg":"version is locked"}', 409),
        ]);

        try {
            new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');
            $this->fail('Expected classic domain deletion to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('remove classic Fastly domain', $error->getMessage());
        }

        $this->assertCount(5, $client->calls);
        $this->assertSame('DELETE', $client->calls[4]['method']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/4/domain/example.com', $client->calls[4]['url']);
    }

    #[TestWith(['api.example.com'])]
    #[TestWith(['*.example.com'])]
    public function testClassicDomainOwnedByAnotherServiceLeavesTlsUntouched(string $classicDomain): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"api.example.com"}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"other.example.com"}]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"},{"id":"other_service"}]'),
            $this->json('{"active_version":{"number":7,"domains":[{"name":"' . $classicDomain . '"}]}}'),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('api.example.com');

        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'GET'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/service/other_service/details', $client->calls[5]['url']);
    }

    public function testDeleteStillRemovesOrphanedTlsSubscription(): void
    {
        $client = new TestClient([
            $this->json('{"data":[]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"}]'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"},"relationships":{"tls_domains":{"data":[{"type":"tls_domain","id":"example.com"}]}}}]}'),
            new Response(204),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('example.com');

        $this->assertCount(7, $client->calls);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[6]['url']);
    }

    #[TestWith(['api.example.com'])]
    #[TestWith(['*.example.com'])]
    public function testMissingDomainPreservesForeignClassicTls(string $classicDomain): void
    {
        $client = new TestClient([
            $this->json('{"data":[]}'),
            $this->json('{"active_version":{"number":3,"domains":[]}}'),
            $this->json('{"services":[]}'),
            $this->json('{"limit_services":false}'),
            $this->json('[{"id":"service_1"},{"id":"other_service"}]'),
            $this->json('{"active_version":{"number":7,"domains":[{"name":"' . $classicDomain . '"}]}}'),
        ]);

        new Fastly('token', 'service_1', client: $client)->deleteCertificate('api.example.com');

        $this->assertCount(6, $client->calls);
        $this->assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'GET'], array_column($client->calls, 'method'));
        $this->assertSame('https://api.fastly.com/service/other_service/details', $client->calls[5]['url']);
    }

    private function json(string $body, int $status = 200): Response
    {
        return new Response($status, body: new Stream($body));
    }
}
