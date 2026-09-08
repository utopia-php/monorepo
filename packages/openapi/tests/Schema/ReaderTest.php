<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Model\AnySchema;
use Utopia\OpenAPI\Model\ArraySchema;
use Utopia\OpenAPI\Model\CompositeSchema;
use Utopia\OpenAPI\Model\Composition;
use Utopia\OpenAPI\Model\IntegerSchema;
use Utopia\OpenAPI\Model\NeverSchema;
use Utopia\OpenAPI\Model\ObjectSchema;
use Utopia\OpenAPI\Model\ReferenceSchema;
use Utopia\OpenAPI\Model\StringSchema;
use Utopia\OpenAPI\Parser\Schema\Dialect;
use Utopia\OpenAPI\Parser\Schema\Reader;
use Utopia\OpenAPI\Version;

final class ReaderTest extends TestCase
{
    private function reader(Version $version): Reader
    {
        return new Reader(Dialect::for($version));
    }

    public function testBooleanSchemasAreReadOnlyUnderTheThreeOneDialect(): void
    {
        $reader = $this->reader(Version::V3_1);

        self::assertInstanceOf(AnySchema::class, $reader->read(true, '#/x'));
        self::assertInstanceOf(NeverSchema::class, $reader->read(false, '#/x'));

        $this->expectException(InvalidSpecification::class);
        $this->reader(Version::V3_0)->read(true, '#/x');
    }

    public function testTypeArraysAreReadOnlyUnderTheThreeOneDialect(): void
    {
        $reader = $this->reader(Version::V3_1);

        $nullable = $reader->read(['type' => ['string', 'null']], '#/x');
        self::assertInstanceOf(StringSchema::class, $nullable);
        self::assertTrue($nullable->nullable);

        $union = $reader->read(['type' => ['string', 'integer', 'null']], '#/x');
        self::assertInstanceOf(CompositeSchema::class, $union);
        self::assertSame(Composition::ANY_OF, $union->composition);
        self::assertCount(2, $union->schemas);
        self::assertTrue($union->nullable);

        $this->expectException(InvalidSpecification::class);
        $this->reader(Version::V3_0)->read(['type' => ['string', 'null']], '#/x');
    }

    public function testConstBecomesASingleValueEnumOnlyUnderTheThreeOneDialect(): void
    {
        self::assertSame(['pets'], $this->reader(Version::V3_1)->read(['type' => 'string', 'const' => 'pets'], '#/x')->enum);
        self::assertSame([], $this->reader(Version::V3_0)->read(['type' => 'string', 'const' => 'pets'], '#/x')->enum);
    }

    public function testAnExplicitEnumWinsOverConst(): void
    {
        self::assertSame(['a', 'b'], $this->reader(Version::V3_1)->read(['type' => 'string', 'const' => 'c', 'enum' => ['a', 'b']], '#/x')->enum);
    }

    public function testNullabilityIsReadFromTheNullableKeyword(): void
    {
        self::assertTrue($this->reader(Version::V3_0)->read(['type' => 'string', 'nullable' => true], '#/x')->nullable);
        self::assertFalse($this->reader(Version::V3_0)->read(['type' => 'string'], '#/x')->nullable);
    }

    public function testXNullableRemainsAnUninterpretedExtension(): void
    {
        $schema = $this->reader(Version::V2)->read(['type' => 'string', 'x-nullable' => true], '#/x');

        self::assertFalse($schema->nullable);
        self::assertSame(['x-nullable' => true], $schema->extensions);
    }

    public function testReferencesAreLeftUnexpandedSoRecursiveGraphsTerminate(): void
    {
        $schema = $this->reader(Version::V3_1)->read(['$ref' => '#/components/schemas/Pet'], '#/x');

        self::assertInstanceOf(ReferenceSchema::class, $schema);
        self::assertSame('#/components/schemas/Pet', $schema->reference);
    }

    public function testCompositionAndNot(): void
    {
        $reader = $this->reader(Version::V3_0);

        foreach ([Composition::ONE_OF, Composition::ANY_OF, Composition::ALL_OF] as $composition) {
            $schema = $reader->read([$composition->value => [['type' => 'string'], ['type' => 'integer']]], '#/x');
            self::assertInstanceOf(CompositeSchema::class, $schema);
            self::assertSame($composition, $schema->composition);
            self::assertCount(2, $schema->schemas);
        }

        $negated = $reader->read(['not' => ['type' => 'string']], '#/x');
        self::assertInstanceOf(CompositeSchema::class, $negated);
        self::assertNull($negated->composition);
        self::assertInstanceOf(StringSchema::class, $negated->not);
    }

    /**
     * @return array<string, mixed>
     */
    private function annotatedWebhookEvent(): array
    {
        return [
            'title' => 'WebhookEvent',
            'oneOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['const' => 'user.updated', 'title' => 'UserUpdated'],
            ],
        ];
    }

    public function testClosedAnnotatedOneOfConstTitlesBecomeAStringEnum(): void
    {
        $schema = $this->reader(Version::V3_1)->read($this->annotatedWebhookEvent(), '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        $enum = $schema->stringEnum();
        self::assertInstanceOf(StringSchema::class, $enum);
        self::assertSame(['user.created', 'user.updated'], $enum->enum);
        self::assertSame(['UserCreated', 'UserUpdated'], $enum->enumKeys);
        self::assertSame('WebhookEvent', $enum->enumName);
        self::assertFalse($enum->open);
        self::assertNull($schema->openStringEnumBranch());
        self::assertCount(2, $schema->schemas);
    }

    public function testClosedAnnotatedAnyOfConstTitlesBecomeAStringEnum(): void
    {
        $schema = $this->reader(Version::V3_1)->read([
            'title' => 'WebhookEvent',
            'anyOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['const' => 'user.updated', 'title' => 'UserUpdated'],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        $enum = $schema->stringEnum();
        self::assertInstanceOf(StringSchema::class, $enum);
        self::assertSame(['user.created', 'user.updated'], $enum->enum);
        self::assertSame(['UserCreated', 'UserUpdated'], $enum->enumKeys);
        self::assertSame('WebhookEvent', $enum->enumName);
        self::assertFalse($enum->open);
        self::assertNull($schema->openStringEnumBranch());
    }

    public function testPlainStringEnumTitleDoesNotFillEnumNameOrKeys(): void
    {
        $schema = $this->reader(Version::V3_0)->read([
            'title' => 'WebhookEvent',
            'type' => 'string',
            'enum' => ['user.created', 'user.updated'],
        ], '#/x');

        self::assertInstanceOf(StringSchema::class, $schema);
        self::assertSame('WebhookEvent', $schema->title);
        self::assertNull($schema->enumName);
        self::assertSame([], $schema->enumKeys);
        self::assertFalse($schema->open);
    }

    public function testMissingBranchTitlesLeaveEnumKeysEmpty(): void
    {
        $schema = $this->reader(Version::V3_1)->read([
            'title' => 'WebhookEvent',
            'oneOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['const' => 'user.updated'],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        $enum = $schema->stringEnum();
        self::assertInstanceOf(StringSchema::class, $enum);
        self::assertSame(['user.created', 'user.updated'], $enum->enum);
        self::assertSame([], $enum->enumKeys);
        self::assertSame('WebhookEvent', $enum->enumName);
    }

    public function testOpenAnnotatedNestedOneOfPreservesKeys(): void
    {
        $schema = $this->reader(Version::V3_1)->read([
            'anyOf' => [
                $this->annotatedWebhookEvent(),
                ['type' => 'string'],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        $enum = $schema->openStringEnumBranch();
        self::assertInstanceOf(StringSchema::class, $enum);
        self::assertSame($enum, $schema->stringEnum());
        self::assertTrue($enum->open);
        self::assertSame(['user.created', 'user.updated'], $enum->enum);
        self::assertSame(['UserCreated', 'UserUpdated'], $enum->enumKeys);
        self::assertSame('WebhookEvent', $enum->enumName);
        self::assertInstanceOf(CompositeSchema::class, $schema->schemas[0]);
        self::assertInstanceOf(StringSchema::class, $schema->schemas[1]);
        self::assertFalse($schema->schemas[1]->open);
    }

    public function testOpenFlattenedConstsPlusUnconstrainedStringPreserveKeys(): void
    {
        $reader = $this->reader(Version::V3_1);
        $consts = [
            ['const' => 'user.created', 'title' => 'UserCreated'],
            ['const' => 'user.updated', 'title' => 'UserUpdated'],
        ];
        $open = ['type' => 'string'];

        foreach ([[...$consts, $open], [$open, ...$consts]] as $branches) {
            $schema = $reader->read(['title' => 'WebhookEvent', 'anyOf' => $branches], '#/x');

            self::assertInstanceOf(CompositeSchema::class, $schema);
            $enum = $schema->openStringEnumBranch();
            self::assertInstanceOf(StringSchema::class, $enum);
            self::assertTrue($enum->open);
            self::assertSame(['user.created', 'user.updated'], $enum->enum);
            self::assertSame(['UserCreated', 'UserUpdated'], $enum->enumKeys);
            self::assertSame('WebhookEvent', $enum->enumName);
        }
    }

    public function testOneOfConstsOnlyIsNotOpen(): void
    {
        $schema = $this->reader(Version::V3_1)->read($this->annotatedWebhookEvent(), '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertFalse($schema->stringEnum()?->open);
        self::assertNull($schema->openStringEnumBranch());
    }

    public function testObjectConstMixIsRejected(): void
    {
        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('#/components/schemas/Event');

        $this->reader(Version::V3_1)->read([
            'oneOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            ],
        ], '#/components/schemas/Event');
    }

    public function testNumericConstMixIsRejected(): void
    {
        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('#/x');

        $this->reader(Version::V3_1)->read([
            'oneOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['const' => 1, 'title' => 'One'],
            ],
        ], '#/x');
    }

    public function testMultiValueEnumMixedWithConstIsRejected(): void
    {
        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('#/x');

        $this->reader(Version::V3_1)->read([
            'anyOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['type' => 'string', 'enum' => ['a', 'b']],
            ],
        ], '#/x');
    }

    public function testTwoMultiValueEnumBranchesAreNotAnAnnotatedEnum(): void
    {
        $schema = $this->reader(Version::V3_0)->read([
            'anyOf' => [
                ['type' => 'string', 'enum' => ['a', 'b']],
                ['type' => 'string', 'enum' => ['c', 'd']],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertNull($schema->stringEnum());
        self::assertNull($schema->openStringEnumBranch());
    }

    public function testLegacyOpenMultiValueEnumStillSetsOpen(): void
    {
        $reader = $this->reader(Version::V3_0);
        $enum = [
            'type' => 'string',
            'enum' => ['network.requests', 'network.inbound'],
        ];
        $open = ['type' => 'string'];

        foreach ([[$enum, $open], [$open, $enum]] as $branches) {
            $schema = $reader->read(['anyOf' => $branches], '#/x');

            self::assertInstanceOf(CompositeSchema::class, $schema);
            $enumBranch = $schema->openStringEnumBranch();
            self::assertInstanceOf(StringSchema::class, $enumBranch);
            self::assertSame(['network.requests', 'network.inbound'], $enumBranch->enum);
            self::assertNull($enumBranch->enumName);
            self::assertSame([], $enumBranch->enumKeys);
            self::assertTrue($enumBranch->open);
            $openBranch = $schema->schemas[$enumBranch === $schema->schemas[0] ? 1 : 0];
            self::assertInstanceOf(StringSchema::class, $openBranch);
            self::assertFalse($openBranch->open);
        }
    }

    public function testOneElementEnumsPlusUnconstrainedStringFlattenAsOpenAnnotated(): void
    {
        $schema = $this->reader(Version::V3_0)->read([
            'anyOf' => [
                ['type' => 'string', 'enum' => ['first'], 'title' => 'First'],
                ['type' => 'string', 'enum' => ['second'], 'title' => 'Second'],
                ['type' => 'string'],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        $enum = $schema->openStringEnumBranch();
        self::assertInstanceOf(StringSchema::class, $enum);
        self::assertTrue($enum->open);
        self::assertSame(['first', 'second'], $enum->enum);
        self::assertSame(['First', 'Second'], $enum->enumKeys);
    }

    public function testConstOnlyStringStaysAClosedSingleValueEnum(): void
    {
        $schema = $this->reader(Version::V3_1)->read(['type' => 'string', 'const' => 'pets'], '#/x');

        self::assertInstanceOf(StringSchema::class, $schema);
        self::assertSame(['pets'], $schema->enum);
        self::assertFalse($schema->open);
        self::assertNull($schema->enumName);
        self::assertSame([], $schema->enumKeys);
    }

    public function testAnnotatedConstBranchesMayOmitType(): void
    {
        $schema = $this->reader(Version::V3_1)->read([
            'title' => 'WebhookEvent',
            'oneOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['const' => 'user.updated', 'title' => 'UserUpdated'],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertInstanceOf(AnySchema::class, $schema->schemas[0]);
        $enum = $schema->stringEnum();
        self::assertInstanceOf(StringSchema::class, $enum);
        self::assertSame(['user.created', 'user.updated'], $enum->enum);
        self::assertSame(['UserCreated', 'UserUpdated'], $enum->enumKeys);
    }

    public function testOpenStringEnumRequiresAnyOf(): void
    {
        $reader = $this->reader(Version::V3_0);
        $branches = [
            ['type' => 'string', 'enum' => ['a', 'b']],
            ['type' => 'string'],
        ];

        foreach ([Composition::ONE_OF, Composition::ALL_OF] as $composition) {
            $schema = $reader->read([$composition->value => $branches], '#/x');

            self::assertInstanceOf(CompositeSchema::class, $schema);
            self::assertNull($schema->openStringEnumBranch());
        }
    }

    public function testOpenStringEnumRequiresOneEnumAndAnOpenStringBranch(): void
    {
        $reader = $this->reader(Version::V3_0);
        $invalidUnions = [
            [['type' => 'string', 'enum' => ['a', 'b']]],
            [['type' => 'string'], ['type' => 'string']],
            [
                ['type' => 'integer', 'enum' => [1]],
                ['type' => 'string'],
            ],
        ];

        foreach ($invalidUnions as $branches) {
            $schema = $reader->read(['anyOf' => $branches], '#/x');

            self::assertInstanceOf(CompositeSchema::class, $schema);
            self::assertNull($schema->openStringEnumBranch());
        }
    }

    public function testOpenStringEnumRequiresAnUnrestrictedStringBranch(): void
    {
        $reader = $this->reader(Version::V3_0);
        $enum = ['type' => 'string', 'enum' => ['a', 'b']];
        $constraints = [
            ['minLength' => 1],
            ['maxLength' => 10],
            ['pattern' => '^known$'],
            ['format' => 'uuid'],
        ];

        foreach ($constraints as $constraint) {
            $schema = $reader->read(['anyOf' => [$enum, ['type' => 'string', ...$constraint]]], '#/x');

            self::assertInstanceOf(CompositeSchema::class, $schema);
            self::assertNull($schema->openStringEnumBranch());
        }

        foreach ([['enum' => ['known']], ['not' => ['type' => 'string', 'enum' => ['blocked']]]] as $constraint) {
            $schema = $reader->read(['anyOf' => [$enum, ['type' => 'string']], ...$constraint], '#/x');

            self::assertInstanceOf(CompositeSchema::class, $schema);
            self::assertNull($schema->openStringEnumBranch());
        }
    }

    public function testReferenceMixedWithConstIsNotAnAnnotatedEnum(): void
    {
        $schema = $this->reader(Version::V3_1)->read([
            'oneOf' => [
                ['const' => 'user.created', 'title' => 'UserCreated'],
                ['$ref' => '#/components/schemas/Pet'],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertNull($schema->stringEnum());
    }

    public function testDiscriminatorIsReadFromBothTheStringAndObjectForms(): void
    {
        $reader = $this->reader(Version::V3_0);

        $fromString = $reader->read(['oneOf' => [], 'discriminator' => 'kind'], '#/x');
        self::assertInstanceOf(CompositeSchema::class, $fromString);
        self::assertNotNull($fromString->discriminator);
        self::assertSame('kind', $fromString->discriminator->propertyName);
        self::assertSame([], $fromString->discriminator->mapping);

        $fromObject = $reader->read([
            'oneOf' => [],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']],
        ], '#/x');
        self::assertInstanceOf(CompositeSchema::class, $fromObject);
        self::assertSame(['cat' => '#/components/schemas/Cat'], $fromObject->discriminator?->mapping);
    }

    public function testDiscriminatorCapturesExtensions(): void
    {
        $reader = $this->reader(Version::V3_0);

        $schema = $reader->read([
            'oneOf' => [],
            'discriminator' => [
                'propertyName' => 'type',
                'mapping' => ['string' => '#/components/schemas/Text'],
                'x-mapping' => [
                    '#/components/schemas/Email' => ['type' => 'string', 'format' => 'email'],
                    '#/components/schemas/Text' => ['type' => 'string'],
                ],
                'x-propertyNames' => ['type', 'format'],
            ],
        ], '#/x');

        self::assertInstanceOf(CompositeSchema::class, $schema);
        self::assertSame([
            'x-mapping' => [
                '#/components/schemas/Email' => ['type' => 'string', 'format' => 'email'],
                '#/components/schemas/Text' => ['type' => 'string'],
            ],
            'x-propertyNames' => ['type', 'format'],
        ], $schema->discriminator?->extensions);

        self::assertSame('type', $schema->discriminator->propertyName);
        self::assertSame(['string' => '#/components/schemas/Text'], $schema->discriminator->mapping);
    }

    public function testDiscriminatorExtensionsDefaultToEmpty(): void
    {
        $reader = $this->reader(Version::V3_0);

        $fromString = $reader->read(['oneOf' => [], 'discriminator' => 'kind'], '#/x');
        self::assertInstanceOf(CompositeSchema::class, $fromString);
        self::assertSame([], $fromString->discriminator?->extensions);

        $fromObject = $reader->read([
            'oneOf' => [],
            'discriminator' => ['propertyName' => 'kind'],
        ], '#/x');
        self::assertInstanceOf(CompositeSchema::class, $fromObject);
        self::assertSame([], $fromObject->discriminator?->extensions);
    }

    public function testObjectAndArrayTypesAreImpliedFromTheirKeywords(): void
    {
        $reader = $this->reader(Version::V3_0);

        self::assertInstanceOf(ObjectSchema::class, $reader->read(['properties' => ['a' => ['type' => 'string']]], '#/x'));
        self::assertInstanceOf(ObjectSchema::class, $reader->read(['additionalProperties' => false], '#/x'));
        self::assertInstanceOf(ArraySchema::class, $reader->read(['items' => ['type' => 'string']], '#/x'));
        self::assertInstanceOf(AnySchema::class, $reader->read([], '#/x'));
    }

    public function testArrayWithoutItemsAcceptsAnything(): void
    {
        $schema = $this->reader(Version::V3_0)->read(['type' => 'array'], '#/x');

        self::assertInstanceOf(ArraySchema::class, $schema);
        self::assertInstanceOf(AnySchema::class, $schema->items);
    }

    public function testAdditionalPropertiesReadsAsBooleanOrSchema(): void
    {
        $reader = $this->reader(Version::V3_0);

        $unspecified = $reader->read(['type' => 'object'], '#/x');
        self::assertInstanceOf(ObjectSchema::class, $unspecified);
        self::assertNull($unspecified->additionalProperties);

        $open = $reader->read(['type' => 'object', 'additionalProperties' => true], '#/x');
        self::assertInstanceOf(ObjectSchema::class, $open);
        self::assertTrue($open->additionalProperties);

        $closed = $reader->read(['type' => 'object', 'additionalProperties' => false], '#/x');
        self::assertInstanceOf(ObjectSchema::class, $closed);
        self::assertFalse($closed->additionalProperties);

        $typed = $reader->read(['type' => 'object', 'additionalProperties' => ['type' => 'string']], '#/x');
        self::assertInstanceOf(ObjectSchema::class, $typed);
        self::assertInstanceOf(StringSchema::class, $typed->additionalProperties);
    }

    /**
     * The 'file' type is 2.0-only in the specification but is accepted under every
     * dialect here. Pinning current behaviour: gating it is a separate change.
     */
    public function testFileTypeReadsAsBinaryStringUnderEveryDialect(): void
    {
        foreach ([Version::V2, Version::V3_0, Version::V3_1] as $version) {
            $schema = $this->reader($version)->read(['type' => 'file'], '#/x');
            self::assertInstanceOf(StringSchema::class, $schema);
            self::assertSame('binary', $schema->format);
        }
    }

    /**
     * Numeric exclusive bounds are draft-2020 shaped, but are accepted under every
     * dialect here. Pinning current behaviour: gating it is a separate change.
     */
    public function testNumericExclusiveBoundsCollapseIntoBoundPlusFlag(): void
    {
        foreach ([Version::V2, Version::V3_0, Version::V3_1] as $version) {
            $schema = $this->reader($version)->read(['type' => 'integer', 'exclusiveMinimum' => 5], '#/x');
            self::assertInstanceOf(IntegerSchema::class, $schema);
            self::assertSame(5, $schema->minimum);
            self::assertTrue($schema->exclusiveMinimum);
        }

        $boolean = $this->reader(Version::V3_0)->read(['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true], '#/x');
        self::assertInstanceOf(IntegerSchema::class, $boolean);
        self::assertSame(5, $boolean->minimum);
        self::assertTrue($boolean->exclusiveMinimum);
    }

    public function testParameterFieldsAreLiftedIntoASchemaAndNonSchemaKeysDropped(): void
    {
        $schema = $this->reader(Version::V2)->readParameterFields([
            'name' => 'limit',
            'in' => 'query',
            'required' => true,
            'type' => 'integer',
            'minimum' => 1,
            'x-nullable' => true,
        ], '#/x');

        self::assertInstanceOf(IntegerSchema::class, $schema);
        self::assertSame(1, $schema->minimum);
        self::assertFalse($schema->nullable);
        self::assertSame([], $schema->extensions);
    }

    public function testExtensionsAreCarriedOntoTheSchema(): void
    {
        self::assertSame(
            ['x-appwrite' => ['method' => 'get']],
            $this->reader(Version::V3_1)->read(['type' => 'string', 'x-appwrite' => ['method' => 'get']], '#/x')->extensions,
        );
    }

    public function testUnsupportedTypeNamesTheLocation(): void
    {
        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('#/components/schemas/Pet');

        $this->reader(Version::V3_1)->read(['type' => 'widget'], '#/components/schemas/Pet');
    }

    public function testNestedFailuresNameTheirOwnLocation(): void
    {
        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('#/x/properties/inner/items');

        $this->reader(Version::V3_0)->read([
            'type' => 'object',
            'properties' => ['inner' => ['type' => 'array', 'items' => ['type' => 'widget']]],
        ], '#/x');
    }
}
