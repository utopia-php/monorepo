# Utopia Validators

Reusable validation building blocks for [Utopia](https://github.com/utopia-php) projects.  
This package exposes a consistent API for common HTTP-oriented validation concerns such as input sanitisation, URL checks, IP validation, hostname filtering, lists enforcement, and more.

## Installation

```bash
composer require utopia-php/validators
```

## Usage

```php
use Utopia\Validator\Text;
use Utopia\Validator\Range;

$username = new Text(20, min: 3);
$age = new Range(min: 13, max: 120);

if (! $username->isValid($input['username'])) {
    throw new InvalidArgumentException($username->getDescription());
}

if (! $age->isValid($input['age'])) {
    throw new InvalidArgumentException($age->getDescription());
}
```

Validators expose a predictable contract:

- `isValid(mixed $value): bool` – core validation rule
- `getDescription(): string` – human readable rule summary
- `getType(): string` – expected PHP type (string, integer, array, ...)
- `isArray(): bool` – hint whether the validator expects an array input

For advanced flows combine validators with `Multiple`, `AnyOf`, `AllOf`, `NoneOf`, or wrap checks with helpers such as `Nullable`.

## Available Validators

- `AllOf`, `AnyOf`, `NoneOf`, `Multiple` – composition helpers
- `ArrayList`, `Assoc`, `Nullable`, `WhiteList`, `Wildcard`
- `Boolean`, `Integer`, `FloatValidator`, `Numeric`, `Range`
- `Domain`, `Host`, `Hostname`, `IP`, `URL`
- `HexColor`, `JSON`, `Text`

## Development

Run the static analysis and test suites from the project root:

```bash
composer check
composer test
```

This project is released under the [MIT License](LICENSE.md).
