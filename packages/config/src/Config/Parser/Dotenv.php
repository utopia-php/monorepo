<?php

namespace Utopia\Config\Parser;

use ReflectionAttribute;
use Utopia\Config\Attribute\Key;
use Utopia\Config\Exception\Parse;
use Utopia\Config\Parser;

class Dotenv extends Parser
{
    /**
     * @var array<string> $truthyValues
     */
    protected array $truthyValues = ['1', 'true', 'yes', 'on', 'enabled'];

    /**
     * @var array<string> $falsyValues
     */
    protected array $falsyValues = ['0', 'false', 'no', 'off', 'disabled'];

    /**
     * @return string|bool|null
     */
    protected function convertValue(string $value, string $type): mixed
    {
        if ($type === 'bool') {
            if (\in_array(strtolower($value), $this->truthyValues)) {
                return true;
            }
            if (\in_array(strtolower($value), $this->falsyValues)) {
                return false;
            }
        }

        return $value;
    }

    /**
     * Resolve the raw right-hand side of a dotenv line into its value.
     *
     * A quoted value is returned verbatim between its quotes, so a `#` inside
     * keeps its place instead of being mistaken for a comment. An unquoted
     * value has any inline comment (from the first `#`) stripped.
     */
    protected function parseValue(string $raw): string
    {
        $raw = trim($raw);

        if ($raw !== '') {
            $quote = $raw[0];
            if ($quote === '"' || $quote === "'") {
                $end = strpos($raw, $quote, 1);
                if ($end !== false) {
                    return substr($raw, 1, $end - 1);
                }
            }
        }

        $hash = strpos($raw, '#');
        if ($hash !== false) {
            $raw = substr($raw, 0, $hash);
        }

        return trim($raw);
    }

    /**
     * @param \ReflectionClass<covariant object>|null $reflection
     * @return array<string, mixed>
     */
    public function parse(mixed $contents, ?\ReflectionClass $reflection = null): array
    {
        if (!\is_string($contents)) {
            throw new Parse('Contents must be a string.');
        }

        if (empty($contents)) {
            return [];
        }

        $config = [];

        $lines = explode("\n", $contents);
        foreach ($lines as $line) {
            $line = trim($line);

            // Blank line or whole-line comment
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Split into KEY=value
            $parts = explode('=', $line, 2);

            // A line without '=' is malformed; fail fast rather than load it
            // as a key with an empty value.
            if (\count($parts) < 2) {
                throw new Parse('Config file is not a valid dotenv file.');
            }

            $name = trim($parts[0]);
            $value = $this->parseValue($parts[1]);

            // Missing name likely means bad syntax
            if (empty($name)) {
                throw new Parse('Config file is not a valid dotenv file.');
            }

            // Smart type-casting
            if ($reflection !== null) {
                $reflectionProperty = null;
                foreach ($reflection->getProperties() as $property) {
                    foreach ($property->getAttributes(Key::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                        $key = $attribute->newInstance();
                        if ($key->name === $name) {
                            $reflectionProperty = $property;
                            break 2;
                        }
                    }
                }
                if ($reflectionProperty !== null) {
                    $reflectionType = $reflectionProperty->getType();
                    if ($reflectionType !== null && method_exists($reflectionType, 'getName')) {
                        $value = $this->convertValue($value, $reflectionType->getName());
                    }
                }
            }

            if (\is_string($value) && strtolower($value) === 'null') {
                $value = null;
            }

            $config[$name] = $value;
        }

        return $config;
    }
}
