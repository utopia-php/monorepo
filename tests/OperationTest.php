<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Model\HttpMethod;
use Utopia\OpenAPI\Model\Operation;
use Utopia\OpenAPI\Model\SecurityRequirement;
use Utopia\OpenAPI\Parser;

final class OperationTest extends TestCase
{
    public static function securityAlternatives(): iterable
    {
        yield 'none' => [[], [], []];
        yield 'anonymous' => [[[]], [], []];
        yield 'single AND requirement' => [[['Project' => [], 'Session' => []]], ['Project', 'Session'], ['Project', 'Session']];
        yield 'optional credential' => [[['Project' => []], ['Project' => [], 'Session' => []]], ['Project', 'Session'], ['Project']];
        yield 'reversed alternatives' => [[['Session' => [], 'Project' => []], ['Project' => []]], ['Session', 'Project'], ['Project']];
        yield 'disjoint alternatives' => [[['Key' => []], ['Session' => []]], ['Key', 'Session'], []];
        yield 'anonymous last' => [[['Key' => []], []], ['Key'], []];
        yield 'anonymous first' => [[[], ['Key' => []]], ['Key'], []];
        yield 'duplicates' => [[['Project' => [], 'Key' => []], ['Project' => [], 'Key' => []]], ['Project', 'Key'], ['Project', 'Key']];
        yield 'three alternatives' => [[['Project' => [], 'Key' => []], ['Project' => [], 'Session' => []], ['Project' => [], 'JWT' => []]], ['Project', 'Key', 'Session', 'JWT'], ['Project']];
        yield 'distinct OAuth scopes' => [[['OAuth' => ['read']], ['OAuth' => ['write']]], ['OAuth'], ['OAuth']];
    }

    #[DataProvider('securityAlternatives')]
    public function test_security_scheme_names(array $alternatives, array $accepted, array $required): void
    {
        $security = array_map(static fn (array $schemes): SecurityRequirement => new SecurityRequirement($schemes), $alternatives);
        $operation = new Operation(id: 'test', method: HttpMethod::GET, path: '/test', security: $security);

        $this->assertSame($accepted, $operation->getAcceptedSecuritySchemeNames());
        $this->assertSame($required, $operation->getRequiredSecuritySchemeNames());
        $this->assertSame($security, $operation->security);
        $this->assertSame($alternatives, array_map(static fn (SecurityRequirement $requirement): array => $requirement->schemes, $operation->security));
    }

    public static function versions(): iterable
    {
        yield 'Swagger 2' => ['swagger', '2.0'];
        yield 'OpenAPI 3.0' => ['openapi', '3.0.3'];
        yield 'OpenAPI 3.1' => ['openapi', '3.1.0'];
    }

    #[DataProvider('versions')]
    public function test_names_follow_parsed_security_inheritance(string $versionKey, string $version): void
    {
        $specification = Parser::parse([
            $versionKey => $version,
            'info' => ['title' => 'Security', 'version' => '1.0.0'],
            'security' => [['Project' => []], ['Project' => [], 'Session' => []]],
            'paths' => ['/test' => [
                'get' => ['responses' => ['200' => ['description' => 'OK']]],
                'post' => ['security' => [], 'responses' => ['200' => ['description' => 'OK']]],
                'delete' => ['security' => [new \stdClass, ['Session' => []]], 'responses' => ['200' => ['description' => 'OK']]],
            ]],
        ]);
        $operations = $specification->paths['/test']->operations;

        $this->assertSame(['Project', 'Session'], $operations['get']->getAcceptedSecuritySchemeNames());
        $this->assertSame(['Project'], $operations['get']->getRequiredSecuritySchemeNames());
        $this->assertSame([], $operations['post']->getAcceptedSecuritySchemeNames());
        $this->assertSame([], $operations['post']->getRequiredSecuritySchemeNames());
        $this->assertSame(['Session'], $operations['delete']->getAcceptedSecuritySchemeNames());
        $this->assertSame([], $operations['delete']->getRequiredSecuritySchemeNames());
    }
}
