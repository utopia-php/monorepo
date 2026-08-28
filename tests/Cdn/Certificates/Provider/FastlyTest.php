<?php

namespace Utopia\Tests\Cdn\Certificates\Provider;

use PHPUnit\Framework\TestCase;
use Utopia\Cdn\Certificates\Provider\Fastly;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;
use Utopia\Tests\Cdn\TestClient;

class FastlyTest extends TestCase
{
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
            new Response(204),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"}}]}'),
            new Response(204),
            $this->json('{}', 201),
            $this->json('{"data":[]}'),
            $this->json('{"data":{"id":"sub_2","attributes":{"state":"pending"}}}', 201),
        ]);

        (new Fastly('token', 'new_service', client: $client))->issueCertificate('cert', 'example.com', null);

        $this->assertSame('https://api.fastly.com/domain-management/v1/domains/domain_1', $client->calls[1]['url']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[3]['url']);
        $this->assertSame('new_service', $client->calls[4]['body']['service_id']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions', $client->calls[6]['url']);
    }

    public function testDeleteRemovesVersionlessSubscriptionAndDomain(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com","service_id":"service_1"}]}'),
            new Response(204),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"}}]}'),
            new Response(204),
        ]);

        (new Fastly('token', 'service_1', client: $client))->deleteCertificate('example.com');

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
            (new Fastly('token', 'service_1', client: $client))->deleteCertificate('example.com');
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

        (new Fastly('token', 'service_1', client: $client))->deleteCertificate('example.com');

        $this->assertCount(1, $client->calls);
        $this->assertStringContainsString('/domain-management/v1/domains?', $client->calls[0]['url']);
    }

    public function testDeleteActivatesClassicServiceWithoutDomainBeforeRemovingTls(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com"}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"example.com"}]}}'),
            $this->json('{"number":4}'),
            $this->json('{}'),
            $this->json('{}'),
            $this->json('{"active":false}'),
            $this->json('{"active":true}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"}}]}'),
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

        $this->assertSame('PUT', $client->calls[2]['method']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/3/clone', $client->calls[2]['url']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/4/domain/example.com', $client->calls[3]['url']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/4/activate', $client->calls[4]['url']);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[8]['url']);
    }

    public function testClassicDomainConflictStopsBeforeActivationAndTlsDeletion(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com"}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"example.com"}]}}'),
            $this->json('{"number":4}'),
            $this->json('{"msg":"version is locked"}', 409),
        ]);

        try {
            (new Fastly('token', 'service_1', client: $client))->deleteCertificate('example.com');
            $this->fail('Expected classic domain deletion to fail.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('remove classic Fastly domain', $error->getMessage());
        }

        $this->assertCount(4, $client->calls);
        $this->assertSame('DELETE', $client->calls[3]['method']);
        $this->assertSame('https://api.fastly.com/service/service_1/version/4/domain/example.com', $client->calls[3]['url']);
    }

    public function testClassicDomainOwnedByAnotherServiceLeavesTlsUntouched(): void
    {
        $client = new TestClient([
            $this->json('{"data":[{"id":"domain_1","fqdn":"example.com"}]}'),
            $this->json('{"active_version":{"number":3,"domains":[{"name":"other.example.com"}]}}'),
        ]);

        (new Fastly('token', 'service_1', client: $client))->deleteCertificate('example.com');

        $this->assertCount(2, $client->calls);
        $this->assertSame('https://api.fastly.com/service/service_1/details', $client->calls[1]['url']);
    }

    public function testDeleteStillRemovesOrphanedTlsSubscription(): void
    {
        $client = new TestClient([
            $this->json('{"data":[]}'),
            $this->json('{"data":[{"id":"sub_1","attributes":{"state":"issued"}}]}'),
            new Response(204),
        ]);

        (new Fastly('token', 'service_1', client: $client))->deleteCertificate('example.com');

        $this->assertCount(3, $client->calls);
        $this->assertSame('https://api.fastly.com/tls/subscriptions/sub_1?force=true', $client->calls[2]['url']);
    }

    private function json(string $body, int $status = 200): Response
    {
        return new Response($status, body: new Stream($body));
    }
}
