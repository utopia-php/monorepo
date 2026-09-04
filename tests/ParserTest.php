<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Exception\CircularReference;
use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Exception\ParseException;
use Utopia\OpenAPI\Model\CompositeSchema;
use Utopia\OpenAPI\Model\HttpMethod;
use Utopia\OpenAPI\Model\ObjectSchema;
use Utopia\OpenAPI\Model\ReferenceSchema;
use Utopia\OpenAPI\Model\StringSchema;
use Utopia\OpenAPI\Parser;
use Utopia\OpenAPI\Reference\LocalResolver;
use Utopia\OpenAPI\Version;

final class ParserTest extends TestCase
{
    public function test_parses_open_api31_into_canonical_model(): void
    {
        $document = [
            'openapi' => '3.1.1',
            'info' => ['title' => 'Pets', 'version' => '1.0.0', 'x-owner' => 'team'],
            'servers' => [['url' => 'https://api.example.com/{version}', 'variables' => ['version' => ['default' => 'v1']]]],
            'tags' => [['name' => 'pets']],
            'security' => [['Project' => [], 'Session' => []], ['Project' => [], 'JWT' => []]],
            'paths' => [
                '/pets/{id}' => [
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                    'get' => [
                        'operationId' => 'getPet',
                        'tags' => ['pets'],
                        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'override', 'schema' => ['type' => 'string']]],
                        'responses' => ['200' => ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Pet']]]]],
                    ],
                    'delete' => ['security' => [], 'responses' => ['204' => ['description' => 'Deleted']]],
                ],
            ],
            'components' => [
                'schemas' => [
                    'Pet' => [
                        'type' => 'object',
                        'required' => ['name'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'parent' => ['$ref' => '#/components/schemas/Pet'],
                            'nickname' => ['type' => ['string', 'null']],
                        ],
                        'additionalProperties' => false,
                    ],
                    'Identifier' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                ],
                'securitySchemes' => [
                    'Project' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Project'],
                    'Session' => ['type' => 'http', 'scheme' => 'bearer'],
                    'JWT' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
            ],
        ];

        $spec = Parser::parse($document);

        self::assertSame(Version::V3_1, $spec->version);
        self::assertSame('3.1.1', $spec->sourceVersion);
        self::assertSame('team', $spec->info->extensions['x-owner']);
        self::assertSame('v1', $spec->servers[0]->variables['version']->default);
        self::assertCount(2, $spec->security);
        self::assertSame(['Project', 'Session'], array_keys($spec->security[0]->schemes));

        $get = $spec->paths['/pets/{id}']->operation(HttpMethod::GET);
        self::assertNotNull($get);
        self::assertSame('getPet', $get->id);
        self::assertCount(1, $get->parameters);
        self::assertSame('override', $get->parameters[0]->description);
        self::assertCount(2, $get->security);
        self::assertSame($get, $spec->operationsByTag('pets')[0]);
        self::assertSame([], $spec->paths['/pets/{id}']->operation(HttpMethod::DELETE)?->security);

        self::assertInstanceOf(ObjectSchema::class, $spec->schemas['Pet']);
        self::assertFalse($spec->schemas['Pet']->additionalProperties);
        self::assertInstanceOf(ReferenceSchema::class, $spec->schemas['Pet']->properties['parent']);
        self::assertTrue($spec->schemas['Pet']->properties['nickname']->nullable);
        self::assertInstanceOf(CompositeSchema::class, $spec->schemas['Identifier']);
        self::assertInstanceOf(ReferenceSchema::class, $get->responses['200']->content['application/json']->schema);
    }

    public function test_parses_open_api30_nullability_and_request_body(): void
    {
        $spec = Parser::parse([
            'openapi' => '3.0.3',
            'info' => ['title' => 'Test', 'version' => '1'],
            'paths' => ['/items' => ['post' => [
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'string', 'nullable' => true]]]],
                'responses' => ['201' => ['description' => 'Created']],
            ]]],
        ]);

        self::assertSame(Version::V3_0, $spec->version);
        $body = $spec->paths['/items']->operation(HttpMethod::POST)?->requestBody;
        self::assertNotNull($body);
        self::assertTrue($body->required);
        self::assertTrue($body->content['application/json']->schema?->nullable);
    }

    public function test_parses_open_api2_directly(): void
    {
        $spec = Parser::parse([
            'swagger' => '2.0',
            'info' => ['title' => 'Legacy', 'version' => '2'],
            'host' => 'api.example.com',
            'basePath' => '/v1',
            'schemes' => ['https'],
            'consumes' => ['application/json'],
            'produces' => ['application/json'],
            'securityDefinitions' => ['Basic' => ['type' => 'basic']],
            'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
            'paths' => ['/pets' => ['post' => [
                'parameters' => [['name' => 'pet', 'in' => 'body', 'required' => true, 'schema' => ['$ref' => '#/definitions/Pet']]],
                'responses' => ['200' => ['description' => 'OK', 'schema' => ['$ref' => '#/definitions/Pet']]],
            ]]],
        ]);

        self::assertSame(Version::V2, $spec->version);
        self::assertSame('https://api.example.com/v1', $spec->servers[0]->url);
        self::assertSame('basic', $spec->securitySchemes['Basic']->scheme);
        $operation = $spec->paths['/pets']->operation(HttpMethod::POST);
        self::assertTrue($operation?->requestBody?->required);
        self::assertInstanceOf(ReferenceSchema::class, $operation?->requestBody?->content['application/json']->schema);
        $responseSchema = $operation?->responses['200']->content['application/json']->schema;
        self::assertInstanceOf(ReferenceSchema::class, $responseSchema);
        self::assertSame($spec->schemas['Pet'], $spec->resolveSchema($responseSchema));
    }

    public function test_parses_open_api2_form_data_as_request_body(): void
    {
        $spec = Parser::parse([
            'swagger' => '2.0',
            'info' => ['title' => 'Upload', 'version' => '1'],
            'paths' => ['/upload' => ['post' => [
                'consumes' => ['multipart/form-data'],
                'parameters' => [
                    ['name' => 'file', 'in' => 'formData', 'required' => true, 'type' => 'file'],
                    ['name' => 'label', 'in' => 'formData', 'type' => 'string'],
                ],
                'responses' => ['204' => ['description' => 'Done']],
            ]]],
        ]);

        $body = $spec->paths['/upload']->operation(HttpMethod::POST)?->requestBody;
        self::assertInstanceOf(ObjectSchema::class, $body?->content['multipart/form-data']->schema);
        self::assertInstanceOf(StringSchema::class, $body?->content['multipart/form-data']->schema->properties['file']);
        self::assertSame('binary', $body?->content['multipart/form-data']->schema->properties['file']->format);
    }

    public function test_resolves_schema_references_explicitly(): void
    {
        $spec = Parser::parse([
            'openapi' => '3.1.0',
            'info' => ['title' => 'References', 'version' => '1'],
            'paths' => [],
            'components' => ['schemas' => [
                'Binary' => ['type' => 'string', 'format' => 'binary'],
                'Alias' => ['$ref' => '#/components/schemas/Binary'],
                'Chained' => ['$ref' => '#/components/schemas/Alias'],
                'Encoded Name' => ['type' => 'string'],
                'Encoded' => ['$ref' => '#/components/schemas/Encoded%20Name'],
                'Missing' => ['$ref' => '#/components/schemas/Unknown'],
                'External' => ['$ref' => 'schemas.json#/Binary'],
                'EncodedExternal' => ['$ref' => '%23/components/schemas/Binary'],
                'CycleA' => ['$ref' => '#/components/schemas/CycleB'],
                'CycleB' => ['$ref' => '#/components/schemas/CycleA'],
            ]],
        ]);

        self::assertSame($spec->schemas['Binary'], $spec->resolveSchema($spec->schemas['Binary']));
        self::assertSame($spec->schemas['Binary'], $spec->resolveSchema($spec->schemas['Alias']));
        self::assertSame($spec->schemas['Binary'], $spec->resolveSchema($spec->schemas['Chained']));
        self::assertSame($spec->schemas['Encoded Name'], $spec->resolveSchema($spec->schemas['Encoded']));
        self::assertSame($spec->schemas['Missing'], $spec->resolveSchema($spec->schemas['Missing']));
        self::assertSame($spec->schemas['External'], $spec->resolveSchema($spec->schemas['External']));
        self::assertSame($spec->schemas['EncodedExternal'], $spec->resolveSchema($spec->schemas['EncodedExternal']));
        self::assertSame($spec->schemas['CycleA'], $spec->resolveSchema($spec->schemas['CycleA']));
    }

    public function test_resolves_escaped_local_json_pointer_and_detects_reference_cycles(): void
    {
        $resolver = new LocalResolver([
            'components' => ['parameters' => ['a/b~c' => ['name' => 'id']]],
            'a' => ['$ref' => '#/b'],
            'b' => ['$ref' => '#/a'],
        ]);
        self::assertSame(['name' => 'id'], $resolver->resolveObject('#/components/parameters/a~1b~0c'));

        $this->expectException(CircularReference::class);
        $resolver->resolveObject('#/a');
    }

    public function test_empty_json_objects_are_not_confused_with_lists(): void
    {
        $spec = Parser::parse('{"openapi":"3.1.0","info":{"title":"Empty","version":"1"},"paths":{},"components":{"schemas":{"Anything":{}}}}');

        self::assertSame([], $spec->paths);
        self::assertArrayHasKey('Anything', $spec->schemas);
    }

    public function test_controlled_errors(): void
    {
        try {
            Parser::parse('{');
            self::fail('Expected invalid JSON to fail');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Invalid JSON', $exception->getMessage());
        }

        $this->expectException(InvalidSpecification::class);
        Parser::parse(['openapi' => '3.0.0', 'info' => ['title' => 'x', 'version' => '1'], 'paths' => []], Version::V3_1);
    }
}
