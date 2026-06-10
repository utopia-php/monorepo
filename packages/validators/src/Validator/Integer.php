<?php

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Integer
 *
 * Validate that an variable is an integer
 */
class Integer extends Validator
{
    /**
     * @var bool
     */
    protected bool $loose = false;

    /**
     * @var int
     */
    protected int $bits = 32;

    /**
     * @var bool
     */
    protected bool $unsigned = false;

    /**
     * Pass true to accept integer strings as valid integer values
     * This option is good for validating query string params.
     *
     * @param  bool  $loose
     * @param  int  $bits  Integer bit size (8, 16, 32, or 64)
     * @param  bool  $unsigned  Whether the integer is unsigned
     * @throws \InvalidArgumentException
     */
    public function __construct(bool $loose = false, int $bits = 32, bool $unsigned = false)
    {
        if (!\in_array($bits, [8, 16, 32, 64])) {
            throw new \InvalidArgumentException('Bits must be 8, 16, 32, or 64');
        }

        // 64-bit unsigned integers exceed PHP_INT_MAX and convert to floats with precision loss
        if ($bits === 64 && $unsigned) {
            throw new \InvalidArgumentException('64-bit unsigned integers are not supported due to PHP integer limitations');
        }

        $this->loose = $loose;
        $this->bits = $bits;
        $this->unsigned = $unsigned;
    }

    /**
     * Get Description
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        $signedness = $this->unsigned ? 'unsigned' : 'signed';

        // Calculate min and max values based on bit size and signed/unsigned
        if ($this->unsigned) {
            $min = 0;
            $max = (2 ** $this->bits) - 1;
        } else {
            $min = -(2 ** ($this->bits - 1));
            $max = (2 ** ($this->bits - 1)) - 1;
        }

        return \sprintf(
            'Value must be a valid %s %d-bit integer between %s and %s',
            $signedness,
            $this->bits,
            \number_format($min),
            \number_format($max)
        );
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_INTEGER;
    }

    /**
     * Get Bits
     *
     * Returns the bit size of the integer.
     *
     * @return int
     */
    public function getBits(): int
    {
        return $this->bits;
    }

    /**
     * Is Unsigned
     *
     * Returns whether the integer is unsigned.
     *
     * @return bool
     */
    public function isUnsigned(): bool
    {
        return $this->unsigned;
    }

    /**
     * Get Format
     *
     * Returns the OpenAPI/JSON Schema format string for this integer configuration.
     *
     * @return string
     */
    public function getFormat(): string
    {
        $prefix = $this->isUnsigned() ? 'uint' : 'int';
        return $prefix . $this->bits;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is integer and within the specified bit range.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isValid(mixed $value): bool
    {
        if ($this->loose) {
            if (!\is_numeric($value)) {
                return false;
            }
            $value = $value + 0;
        }
        if (!\is_int($value)) {
            return false;
        }

        // Calculate min and max values based on bit size and signed/unsigned
        if ($this->unsigned) {
            $min = 0;
            $max = (2 ** $this->bits) - 1;
        } else {
            $min = -(2 ** ($this->bits - 1));
            $max = (2 ** ($this->bits - 1)) - 1;
        }

        // Check if value is within range
        if ($value < $min || $value > $max) {
            return false;
        }

        return true;
    }
}
