<?php

declare(strict_types=1);

namespace Utopia\Tests\Registrar;

use PHPUnit\Framework\TestCase;
use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Adapter\NameCom;

final class NameComBatchTest extends TestCase
{
    public function testAvailabilityRequestsAreBatchedAndMappedByDomain(): void
    {
        $adapter = new class ('username', 'token') extends NameCom {
            public array $requests = [];

            protected function send(string $method, string $path, ?array $data = null): array
            {
                $this->requests[] = [
                    'method' => $method,
                    'path' => $path,
                    'data' => $data,
                ];

                $results = [];
                foreach (array_reverse($data['domainNames'] ?? []) as $domain) {
                    $results[] = [
                        'domainName' => $domain,
                        'purchasable' => $domain !== 'domain-25.com',
                    ];
                }

                return ['results' => $results];
            }
        };
        $registrar = new Registrar($adapter);
        $domains = array_map(
            fn(int $index): string => "domain-{$index}.com",
            range(1, 51),
        );
        $domains[] = 'domain-1.com';

        $result = $registrar->available($domains);

        $this->assertCount(51, $result);
        $this->assertTrue($result['domain-1.com']);
        $this->assertFalse($result['domain-25.com']);
        $this->assertCount(2, $adapter->requests);
        $this->assertSame(50, \count($adapter->requests[0]['data']['domainNames']));
        $this->assertSame(['domain-51.com'], $adapter->requests[1]['data']['domainNames']);

        foreach ($adapter->requests as $request) {
            $this->assertSame('POST', $request['method']);
            $this->assertSame('/core/v1/domains:checkAvailability', $request['path']);
        }
    }

    public function testSingleDomainUsesBatchEndpoint(): void
    {
        $adapter = new class ('username', 'token') extends NameCom {
            public int $requests = 0;

            protected function send(string $method, string $path, ?array $data = null): array
            {
                $this->requests++;

                return [
                    'results' => [[
                        'domainName' => $data['domainNames'][0],
                        'purchasable' => true,
                    ]],
                ];
            }
        };
        $registrar = new Registrar($adapter);

        $this->assertSame(['example.com' => true], $registrar->available(['example.com']));
        $this->assertSame(1, $adapter->requests);
    }

    public function testEmptyBatchDoesNotSendRequest(): void
    {
        $adapter = new class ('username', 'token') extends NameCom {
            protected function send(string $method, string $path, ?array $data = null): array
            {
                throw new \LogicException('No request should be sent for an empty batch.');
            }
        };
        $registrar = new Registrar($adapter);

        $this->assertSame([], $registrar->available([]));
    }
}
