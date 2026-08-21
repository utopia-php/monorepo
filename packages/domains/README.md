# Utopia Domains

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/domains`](https://github.com/utopia-php/monorepo/tree/main/packages/domains) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/domains.svg)
[![Discord](https://img.shields.io/discord/564160730845151244)](https://appwrite.io/discord)

Utopia Domains is a lite library for parsing domain names and talking to domain registrars. This library is maintained by the [Appwrite team](https://appwrite.io).

Although this library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project, it can be used standalone with any other PHP project or framework.

## Getting started

Install using Composer:
```bash
composer require utopia-php/domains
```

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Domains\Domain;

// demo.example.co.uk

$domain = new Domain('demo.example.co.uk');

$domain->get(); // demo.example.co.uk
$domain->getTLD(); // uk
$domain->getSuffix(); // co.uk
$domain->getRegisterable(); // example.co.uk
$domain->getName(); // example
$domain->getSub(); // demo
$domain->isKnown(); // true
$domain->isICANN(); // true
$domain->isPrivate(); // false
$domain->isTest(); // false

// demo.localhost

$domain = new Domain('demo.localhost');

$domain->get(); // demo.localhost
$domain->getTLD(); // localhost
$domain->getSuffix(); // ''
$domain->getRegisterable(); // ''
$domain->getName(); // demo
$domain->getSub(); // ''
$domain->isKnown(); // false
$domain->isICANN(); // false
$domain->isPrivate(); // false
$domain->isTest(); // true

```

The parser reads a PHP dataset generated from [publicsuffix.org](https://publicsuffix.org/), mapping each public suffix rule to the list section it came from. Refresh it by running the import script:

```bash
php ./data/import.php
```

## Library API

* `get()` — the full domain name.
* `getTLD()` — the top-level domain only.
* `getSuffix()` — the public suffix only, for example `co.uk`, `ac.be`, `org.il`, `com`, `org`.
* `getRegisterable()` — the registrable domain: the public suffix plus one label.
* `getName()` — the registrable domain's name only. `blog.example.com` returns `example`, `demo.co.uk` returns `demo`.
* `getSub()` — the full subdomain path. `blog.example.com` returns `blog`, `subdomain.demo.co.uk` returns `subdomain.demo`.
* `isKnown()` — true if the public suffix is known.
* `isICANN()` — true if the public suffix comes from the ICANN section of the public suffix list.
* `isPrivate()` — true if the public suffix comes from the private section of the public suffix list.
* `isTest()` — true if the domain's top-level domain is `localhost` or `test`.

> To parse an ordinary web URL, take its host first: `$host = parse_url($url, PHP_URL_HOST); $domain = new Utopia\Domains\Domain($host);`


## Using the registrar API

The library supports multiple domain registrar adapters:
- **OpenSRS** - OpenSRS domain registrar
- **NameCom** - Name.com domain registrar

### Using the OpenSRS adapter
```php
<?php

use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Adapter\OpenSRS;

$opensrs = new OpenSRS(
  'apikey',
  'username',
  'password',
  [
    'ns1.nameserver.com',
    'ns2.nameserver.com',
  ],
  'https://horizon.opensrs.net:55443' // or 'https://rr-n1-tor.opensrs.net:55443' for production
);

$reg = new Registrar($opensrs);
```

### Using the Name.com adapter
```php
<?php

use Utopia\Domains\Registrar;
use Utopia\Domains\Registrar\Contact;
use Utopia\Domains\Registrar\Adapter\NameCom;

$namecom = new NameCom(
  'username',
  'api-token',
  [
    'ns1.name.com',
    'ns2.name.com',
  ],
  'https://api.name.com' // or 'https://api.dev.name.com' for testing
);

$reg = new Registrar($namecom);
```

### Using the registrar
Once you have initialized an adapter, you can use the Registrar API:

```php
$reg = new Registrar($adapter); // $adapter can be OpenSRS or NameCom

$contact = new Contact(
  'firstname',
  'lastname',
  'phone',
  'email',
  'address1',
  'address2',
  'address3',
  'city',
  'state',
  'country',
  'postalcode',
  'org',
  'owner',
);

$domain = 'yourname.com';

$available = $reg->available($domain);
$purchase = $reg->purchase($domain, [$contact]);
$purchase = $reg->purchase($domain, [$contact], 1);
$suggest = $reg->suggest(['yourname', 'yourname1.com'], ['com', 'net', 'org'], 10, 10000, 100);
$domainDetails = $reg->getDomain($domain);
$renew = $reg->renew($domain, 1);
$transfer = $reg->transfer($domain, [$contact]);
$transfer = $reg->transfer($domain, 'authcode', [$contact]);

```

### Update auto-renew
```php
use Utopia\Domains\Registrar\UpdateDetails;

$details = new UpdateDetails(autoRenew: true);
$reg->updateDomain($domain, $details);
```

## Library registrar API

* `available(string $domain): bool` — is the domain free to register?
* `purchase(string $domain, array|Contact $contacts, int $periodYears = 1, array $nameservers = []): Registration` — register a domain.
* `suggest(array $query, array $tlds = [], ?int $limit = null, ?int $priceMax = null, ?int $priceMin = null): array` — search for domain names.
* `getDomain(string $domain): Domain` — the domain's details.
* `updateDomain(string $domain, UpdateDetails $details): bool` — update details such as auto-renew.
* `renew(string $domain, int $periodYears): Renewal` — renew a domain.
* `transfer(string $domain, string $authCode, array|Contact $contacts, int $periodYears = 1, array $nameservers = []): Registration` — transfer a domain in.
* `getAuthCode(string $domain): string` — the domain's authorization code.
* `checkTransferStatus(string $domain, bool $checkStatus = true, bool $getRequestAddress = false): TransferStatus` — where a transfer stands.


## System requirements

Utopia Domains requires PHP 8.4 or later. We recommend using the latest PHP version whenever possible.

## Tests

The unit tier covers the parser, the validators and the `Mock` registrar, and needs nothing running:

```bash
composer test
```

The end-to-end tier drives the OpenSRS and Name.com sandboxes, so it needs sandbox credentials in the environment — without them every test skips:

```bash
OPENSRS_KEY=... OPENSRS_USERNAME=... NAMECOM_USERNAME=... NAMECOM_TOKEN=... composer test:e2e
```

## Authors

**Eldad Fux**

+ [https://twitter.com/eldadfux](https://twitter.com/eldadfux)
+ [https://github.com/eldadfux](https://github.com/eldadfux)

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)
