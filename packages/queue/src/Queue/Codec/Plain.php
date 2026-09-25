<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use ArrayObject;
use RuntimeException;
use stdClass;

/**
 * Arrays and scalars, all the way down.
 *
 * A codec that can carry an object has to apply this, because the shape a handler
 * receives is the package's contract and not a property of the format: {@see Json}
 * flattens an object on the way out and returns an array on the way back, and a
 * consumer written against that breaks on a codec that preserves the class -- with no
 * compile-time warning and no failing test, since a test over the JSON codec passes
 * either way.
 *
 * Not a codec itself, so it can be tested on a build that has no binary extension to
 * wrap, and so the next format that preserves an object inherits it rather than
 * reimplementing it.
 *
 * An ArrayObject becomes its storage and a stdClass becomes an array, at any depth.
 * Any other class is left alone: quietly reshaping a type nobody considered is how this
 * class of defect is made, not how it is fixed.
 */
final class Plain
{
    /**
     * json_encode's own limit, measured the same way -- it carries 512 levels of array
     * and refuses the 513th -- so a payload this accepts is one JSON would have
     * accepted too.
     */
    private const int MAX_DEPTH = 512;

    /**
     * @throws RuntimeException on a payload that nests deeper than a message can
     */
    public static function of(mixed $value): mixed
    {
        $changed = false;

        return self::walk($value, $changed, 0);
    }

    /**
     * The walk rebuilds only the branches that hold an object, and reports through
     * $changed whether it did. In steady state nothing does -- everything was written
     * flat -- and an untouched array is handed back rather than copied level by level,
     * which is what makes this affordable on every message on every delivery.
     *
     * The cast, not getArrayCopy(): a subclass may define its own copy that walks
     * nested objects for you -- Utopia\Database\Document does -- and the descent then
     * happens inside that, where the depth below cannot see it. Casting hands back the
     * storage one level deep and leaves the walking here.
     *
     * Depth is also what refuses a payload that refers to itself, whether the loop runs
     * through an object or through a native array holding a reference to itself. Both
     * are shapes a binary format will carry and json_encode refuses. Only descending
     * into an array counts, so an object costs what the array it stands for costs.
     *
     * @throws RuntimeException on a payload that nests deeper than a message can
     */
    private static function walk(mixed $value, bool &$changed, int $depth): mixed
    {
        if ($value instanceof ArrayObject || $value instanceof stdClass) {
            $changed = true;
            $ignored = false;

            return self::walk((array) $value, $ignored, $depth);
        }

        if (!\is_array($value)) {
            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new RuntimeException('Queue payload nests deeper than ' . self::MAX_DEPTH . ' levels, or refers to itself.');
        }

        $plain = $value;

        foreach ($value as $key => $item) {
            if (!\is_array($item) && !$item instanceof ArrayObject && !$item instanceof stdClass) {
                continue;
            }

            $itemChanged = false;
            $item = self::walk($item, $itemChanged, $depth + 1);

            if (!$itemChanged) {
                continue;
            }

            $changed = true;
            $plain[$key] = $item;
        }

        return $plain;
    }
}
