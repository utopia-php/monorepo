<?php

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * ArrayList
 *
 * Validate that an variable is a valid array value and each element passes given validation
 */
class Assoc extends Validator
{
    /**
     * Pass integer length to allow larger json objects
     */
    public function __construct(protected int $length = 65535) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        return 'Value must be a valid object.';
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return true;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_ARRAY;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid assoc array.
     *
     * @param  mixed  $value
     */
    public function isValid($value): bool
    {
        // utopia-http keeps empty JSON `{}` as stdClass so it is not collapsed to `[]`.
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (!\is_array($value)) {
            return false;
        }

        $jsonString = json_encode($value);
        $jsonStringSize = \strlen($jsonString);

        if ($jsonStringSize > $this->length) {
            return false;
        }

        // Empty arrays are also valid: when the body has no empty-object marker,
        // `{}` still decodes to `[]` and cannot be distinguished from an empty list.
        if ($value === []) {
            return true;
        }

        return !array_is_list($value);
    }
}
