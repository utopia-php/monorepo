<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Parser\Value;

final class ValueTest extends TestCase
{
    public function test_empty_arrays_read_as_both_object_and_list(): void
    {
        self::assertSame([], Value::object([], '#/x'));
        self::assertSame([], Value::list([], '#/x'));
    }

    public function test_object_rejects_lists_and_scalars(): void
    {
        self::assertSame(['a' => 1], Value::object(['a' => 1], '#/x'));

        foreach ([[1, 2], 'text', 7, true, null] as $notAnObject) {
            try {
                Value::object($notAnObject, '#/paths');
                self::fail('Expected a non-object to be rejected');
            } catch (InvalidSpecification $exception) {
                self::assertSame('Expected an object at #/paths', $exception->getMessage());
            }
        }
    }

    public function test_object_accepts_std_class(): void
    {
        $value = new \stdClass;
        $value->a = 1;

        self::assertSame(['a' => 1], Value::object($value, '#/x'));
    }

    public function test_list_rejects_maps_and_scalars(): void
    {
        self::assertSame([1, 2], Value::list([1, 2], '#/x'));

        foreach ([['a' => 1], 'text', 7, null] as $notAList) {
            try {
                Value::list($notAList, '#/tags');
                self::fail('Expected a non-list to be rejected');
            } catch (InvalidSpecification $exception) {
                self::assertSame('Expected a list at #/tags', $exception->getMessage());
            }
        }
    }

    public function test_string_list_rejects_non_string_items(): void
    {
        self::assertSame(['first', 'second'], Value::stringList(['first', 'second'], '#/tags'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected string at #/tags/1');
        Value::stringList(['first', 2], '#/tags');
    }

    public function test_required_string_names_the_missing_key(): void
    {
        self::assertSame('Pets', Value::requiredString(['title' => 'Pets'], 'title', '#/info'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected string #/info/title');
        Value::requiredString([], 'title', '#/info');
    }

    public function test_required_string_rejects_non_strings(): void
    {
        $this->expectException(InvalidSpecification::class);
        Value::requiredString(['title' => 7], 'title', '#/info');
    }

    public function test_optional_string_treats_missing_and_null_alike(): void
    {
        self::assertSame('Pets', Value::optionalString(['title' => 'Pets'], 'title'));
        self::assertNull(Value::optionalString([], 'title'));
        self::assertNull(Value::optionalString(['title' => null], 'title'));
    }

    public function test_optional_string_still_rejects_wrong_types(): void
    {
        $this->expectException(InvalidSpecification::class);
        Value::optionalString(['title' => 7], 'title');
    }

    public function test_nullable_int_accepts_only_integers(): void
    {
        self::assertNull(Value::nullableInt(null, '#/x'));
        self::assertSame(3, Value::nullableInt(3, '#/x'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected integer at #/x/minLength');
        Value::nullableInt(3.5, '#/x/minLength');
    }

    public function test_nullable_number_accepts_integers_and_floats(): void
    {
        self::assertNull(Value::nullableNumber(null, '#/x'));
        self::assertSame(3, Value::nullableNumber(3, '#/x'));
        self::assertSame(3.5, Value::nullableNumber(3.5, '#/x'));

        $this->expectException(InvalidSpecification::class);
        $this->expectExceptionMessage('Expected number at #/x/minimum');
        Value::nullableNumber('3', '#/x/minimum');
    }

    public function test_extensions_keep_only_prefixed_keys_and_are_case_insensitive(): void
    {
        self::assertSame(
            ['x-owner' => 'team', 'X-Trace' => true],
            Value::extensions(['title' => 'Pets', 'x-owner' => 'team', 'X-Trace' => true, 'xenon' => 1]),
        );
    }
}
