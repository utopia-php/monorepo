<?php

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Contains
 *
 * Validate that a string contains at least one of the predefined substrings.
 */
class Contains extends Validator
{
    /**
     * @var array
     */
    protected array $patterns;

    /**
     * @var bool
     */
    protected bool $strict;

    /**
     * Constructor
     *
     * Sets a list of substrings to search for and strict mode.
     *
     * @param  array  $patterns
     * @param  bool  $strict enable case-sensitive matching
     */
    public function __construct(array $patterns, bool $strict = false)
    {
        if (empty($patterns)) {
            throw new \InvalidArgumentException('Patterns array cannot be empty');
        }

        $this->patterns = $patterns;
        $this->strict = $strict;
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
        $message = 'Value must contain one of ('.\implode(', ', $this->patterns).')';

        if ($this->strict) {
            $message .= ' (case-sensitive)';
        } else {
            $message .= ' (case-insensitive)';
        }

        return $message;
    }

    /**
     * Is valid
     *
     * Validation will pass when $value contains at least one of the patterns.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isValid($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        if (!$this->strict) {
            $value = \mb_strtolower($value, 'UTF-8');
        }

        foreach ($this->patterns as $pattern) {
            $pattern = $this->strict ? $pattern : \mb_strtolower($pattern, 'UTF-8');

            if (\str_contains($value, $pattern)) {
                return true;
            }
        }

        return false;
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
        return self::TYPE_STRING;
    }
}
