<?php

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * URL
 *
 * Validate that an variable is a valid URL
 *
 * @package Appwrite\Network\Validator
 */
class URL extends Validator
{
    public function __construct(protected array $allowedSchemes = [], protected bool $allowEmpty = false, protected bool $allowFragments = true, protected bool $allowPrivateUseSchemes = false) {}

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        if ($this->allowedSchemes !== []) {
            $description = 'Value must be a valid URL with following schemes (' . implode(', ', $this->allowedSchemes) . ')';

            if (!$this->allowFragments) {
                $description .= ' and without a fragment component';
            }

            return $description;
        }

        if (!$this->allowFragments) {
            return 'Value must be a valid URL without a fragment component';
        }

        return 'Value must be a valid URL';
    }

    /**
     * Is valid
     *
     * Validation will pass when $value is valid URL.
     *
     * @param  mixed $value
     */
    public function isValid($value): bool
    {
        if ($this->allowEmpty && $value === '') {
            return true;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            // FILTER_VALIDATE_URL rejects authority-less private-use URI schemes
            // (e.g. "com.example.app:/oauth", RFC 8252 §7.1). Optionally accept those.
            if (!($this->allowPrivateUseSchemes && $this->isPrivateUseSchemeURI($value))) {
                return false;
            }
        }

        if ($this->allowedSchemes !== [] && !\in_array(parse_url($value, PHP_URL_SCHEME), $this->allowedSchemes)) {
            return false;
        }

        if (!$this->allowFragments && parse_url($value, PHP_URL_FRAGMENT) !== null) {
            return false;
        }

        return true;
    }

    /**
     * Is private-use URI scheme
     *
     * Returns true when $value is an authority-less private-use URI scheme
     * redirect URI as defined by RFC 8252 §7.1, e.g. "com.example.app:/oauth".
     *
     * @param  mixed $value
     */
    private function isPrivateUseSchemeURI($value): bool
    {
        if (!\is_string($value)) {
            return false;
        }

        $colonPos = \strpos($value, ':');
        if ($colonPos === false) {
            return false;
        }

        $scheme = \substr($value, 0, $colonPos);
        $remainder = \substr($value, $colonPos + 1);

        if (!$this->isPrivateUseScheme($scheme)) {
            return false;
        }

        // Reject the "scheme://…" authority form; that is handled by filter_var.
        if (\str_starts_with($remainder, '//')) {
            return false;
        }

        // Validate the authority-less remainder (a path, optionally with a query
        // and/or fragment) by reusing PHP's URL validation with a placeholder
        // authority, since filter_var alone rejects the authority-less form.
        $normalized = \str_starts_with($remainder, '/')
            ? 'https://localhost' . $remainder
            : 'https://localhost/' . $remainder;

        return filter_var($normalized, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Is private-use scheme
     *
     * Returns true when $scheme is a valid RFC 3986 scheme that is also a
     * reverse-DNS / private-use scheme (contains a dot), per RFC 8252 §7.1.
     * The dot requirement also excludes standard dotless schemes (http, ftp, …).
     * parse_url does not enforce the scheme grammar, so validate it explicitly.
     */
    private function isPrivateUseScheme(string $scheme): bool
    {
        // RFC 3986: scheme = ALPHA *( ALPHA / DIGIT / "+" / "-" / "." )
        if ($scheme === '' || !\ctype_alpha($scheme[0])) {
            return false;
        }

        if (!\str_contains($scheme, '.')) {
            return false;
        }

        foreach (\str_split($scheme) as $char) {
            if (!\ctype_alnum($char) && !\in_array($char, ['+', '-', '.'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}
