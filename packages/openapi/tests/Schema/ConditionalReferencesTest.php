<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Model\CompositeSchema;
use Utopia\OpenAPI\Model\Composition;
use Utopia\OpenAPI\Model\ReferenceSchema;
use Utopia\OpenAPI\Parser\Schema\Dialect;
use Utopia\OpenAPI\Parser\Schema\Reader;
use Utopia\OpenAPI\Version;

final class ConditionalReferencesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function branch(string $name, array $conditions): array
    {
        return ['allOf' => [
            ['$ref' => '#/components/schemas/' . $name],
            [
                'type' => 'object',
                'required' => array_keys($conditions),
                'properties' => array_map(static fn(mixed $value): array => ['enum' => [$value]], $conditions),
            ],
        ]];
    }

    public function testCompoundReferencesPreserveIdentityConditionsAndOverlaps(): void
    {
        foreach (['oneOf', 'anyOf'] as $composition) {
            $reader = new Reader(Dialect::for(Version::V3_0));
            $schema = $reader->read([$composition => [
                self::branch('Text', ['kind' => 'text']),
                self::branch('Email', ['kind' => 'text', 'format' => 'email']),
                self::branch('Url', ['kind' => 'text', 'format' => 'url']),
            ]], '#/result');

            self::assertInstanceOf(CompositeSchema::class, $schema);
            self::assertSame([
                '#/components/schemas/Text' => ['kind' => 'text'],
                '#/components/schemas/Email' => ['kind' => 'text', 'format' => 'email'],
                '#/components/schemas/Url' => ['kind' => 'text', 'format' => 'url'],
            ], $schema->conditionalReferences());
            self::assertNull($schema->discriminator);
            self::assertCount(3, $schema->schemas);
            self::assertInstanceOf(CompositeSchema::class, $schema->schemas[0]);
            self::assertSame(Composition::ALL_OF, $schema->schemas[0]->composition);
            self::assertInstanceOf(ReferenceSchema::class, $schema->schemas[0]->schemas[0]);
        }
    }

    public function testNestedAllOfPreservesScalarTypesAndDoesNotInterpretExtensions(): void
    {
        $branch = self::branch('Entry', ['enabled' => false, 'version' => 2, 'ratio' => 1.5, 'kind' => '2']);
        $branch['allOf'] = [['allOf' => $branch['allOf']]];
        $schema = new Reader(Dialect::for(Version::V3_0))->read([
            'anyOf' => [$branch],
            'discriminator' => [
                'propertyName' => 'legacy',
                'x-mapping' => ['wrong' => ['legacy' => 'wrong']],
            ],
        ], '#/result');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertSame([
            '#/components/schemas/Entry' => ['enabled' => false, 'version' => 2, 'ratio' => 1.5, 'kind' => '2'],
        ], $schema->conditionalReferences());
        self::assertSame(['wrong' => ['legacy' => 'wrong']], $schema->discriminator?->extensions['x-mapping']);
    }

    public function testThreeOneConstConditions(): void
    {
        $branch = self::branch('Entry', ['enabled' => true]);
        $branch['allOf'][1]['properties']['enabled'] = ['type' => 'boolean', 'const' => true];
        $reader = new Reader(Dialect::for(Version::V3_1));
        $schema = $reader->read(['anyOf' => [$branch]], '#/result');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertSame(['#/components/schemas/Entry' => ['enabled' => true]], $schema->conditionalReferences());
    }

    #[DataProvider('unsupportedSchemas')]
    public function testUnsupportedUnionsDoNotReturnPartialCases(array $raw): void
    {
        $schema = new Reader(Dialect::for(Version::V3_0))->read($raw, '#/result');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertSame([], $schema->conditionalReferences());
    }

    public static function unsupportedSchemas(): iterable
    {
        $valid = self::branch('Entry', ['kind' => 'entry']);
        yield 'allOf is not a union' => [$valid];
        yield 'plain refs have no conditions' => [['oneOf' => [['$ref' => '#/components/schemas/Entry']]]];
        yield 'mixed supported and unsupported members' => [['anyOf' => [$valid, ['type' => 'object']]]];
        yield 'repeated model identity' => [['anyOf' => [$valid, self::branch('Entry', ['kind' => 'other'])]]];
        yield 'nullable union' => [['anyOf' => [$valid], 'nullable' => true]];
        yield 'negated union' => [['anyOf' => [$valid], 'not' => ['type' => 'object']]];
        yield 'constrained union' => [['anyOf' => [$valid], 'enum' => [['kind' => 'entry']]]];
        yield 'missing reference' => [['anyOf' => [$valid['allOf'][1]]]];
        $branch = $valid;
        $branch['allOf'][] = ['$ref' => '#/components/schemas/Other'];
        yield 'multiple identities' => [['anyOf' => [$branch]]];
        $branch = $valid;
        $branch['allOf'][] = self::branch('Other', ['kind' => 'other'])['allOf'][1];
        yield 'conflicting conditions' => [['anyOf' => [$branch]]];
        $branch = $valid;
        $branch['allOf'][1]['required'] = [];
        yield 'optional condition' => [['anyOf' => [$branch]]];
        $branch = $valid;
        $branch['allOf'][1]['required'][] = 'missing';
        yield 'required property without enum' => [['anyOf' => [$branch]]];
        $branch = $valid;
        $branch['allOf'][1]['additionalProperties'] = false;
        yield 'closed constraint object' => [['anyOf' => [$branch]]];
        $branch = $valid;
        $branch['allOf'][1]['properties']['kind']['nullable'] = true;
        yield 'nullable condition' => [['anyOf' => [$branch]]];
        foreach ([[], ['entry', 'other'], [null], [[]]] as $index => $enum) {
            $branch = $valid;
            $branch['allOf'][1]['properties']['kind']['enum'] = $enum;
            yield 'non scalar singleton ' . $index => [['anyOf' => [$branch]]];
        }
        $branch = ['anyOf' => $valid['allOf']];
        yield 'nested anyOf is not a conjunction' => [['anyOf' => [$branch]]];
        yield 'empty conjunction' => [['anyOf' => [['allOf' => []]]]];
    }
}
