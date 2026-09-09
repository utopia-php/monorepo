<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\AuthorizationDetails;

final class AuthorizationDetailsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string, string, string, ?string, bool}>
     */
    public static function grants(): iterable
    {
        $project = [['type' => 'project', 'identifiers' => ['p1'], 'actions' => ['read']]];

        yield 'value listed in field' => [$project, 'project', 'p1', 'identifiers', null, true];
        yield 'value absent from field' => [$project, 'project', 'p2', 'identifiers', null, false];
        yield 'type must match' => [$project, 'organization', 'p1', 'identifiers', null, false];
        yield 'field must match' => [$project, 'project', 'p1', 'actions', null, false];
        yield 'other field matches' => [$project, 'project', 'read', 'actions', null, true];

        $wildcard = [['type' => 'project', 'identifiers' => ['*']]];
        yield 'wildcard matches any value when given' => [$wildcard, 'project', 'anything', 'identifiers', '*', true];
        yield 'wildcard ignored when not given' => [$wildcard, 'project', 'anything', 'identifiers', null, false];

        yield 'empty type' => [$project, '', 'p1', 'identifiers', null, false];
        yield 'empty value' => [$project, 'project', '', 'identifiers', null, false];
        yield 'empty field' => [$project, 'project', 'p1', '', null, false];

        yield 'null input' => [null, 'project', 'p1', 'identifiers', null, false];
        yield 'scalar input' => ['nonsense', 'project', 'p1', 'identifiers', null, false];
        yield 'malformed entries ignored, valid entry honored' => [
            ['scalar', ['type' => 'project', 'identifiers' => 'not-a-list'], ['type' => 'project'], ['type' => 'project', 'identifiers' => ['p1']]],
            'project', 'p1', 'identifiers', null, true,
        ];

        // A numeric value in the field must not match the string lookup.
        yield 'match is type strict' => [[['type' => 'project', 'identifiers' => [1]]], 'project', '1', 'identifiers', null, false];
    }

    #[DataProvider('grants')]
    public function testGrants(mixed $raw, string $type, string $value, string $field, ?string $wildcard, bool $expected): void
    {
        $this->assertSame($expected, (new AuthorizationDetails($raw))->grants($type, $value, $field, $wildcard));
    }
}
