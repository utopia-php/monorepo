# Validators

Password and identity input rules used at API boundaries. They implement
`Utopia\Validator` from [utopia-php/validators](https://github.com/utopia-php/validators).

## Password length

```php
<?php

use Utopia\Auth\Validator\Password;

$validator = new Password();
$validator->isValid('secret12'); // true
$validator->isValid('short');    // false
```

`PasswordStrength` adds a minimum length and optional uppercase, lowercase,
number, and symbol rules. `PasswordDictionary` rejects values from a supplied
common-password map. `PasswordHistory` rejects values that verify against
previous `Utopia\Auth\Hash` outputs. `PersonalData` rejects passwords that
contain the user's id, email, name, or phone.

## Email allowlist

```php
<?php

use Utopia\Auth\Validator\EmailWhitelist;

$validator = new EmailWhitelist([
    'owner@example.com',
    '*@appwrite.io',
]);

$validator->isValid('owner@example.com'); // true
$validator->isValid('dev@appwrite.io');   // true
$validator->isValid('user@example.net');  // false
```

Only exact addresses and a single `*@domain` form are accepted as list
entries.

## Mock phone and OTP pairs

```php
<?php

use Utopia\Auth\Validator\MockNumber;

$validator = new MockNumber();
$validator->isValid([
    'phone' => '+14155552680',
    'otp' => '123456',
]);
```

Phone numbers must be E.164.
