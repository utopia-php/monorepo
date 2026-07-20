# Utopia Storage

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/storage`](https://github.com/utopia-php/monorepo/tree/main/packages/storage) — please open issues and pull requests there.

![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/storage.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://appwrite.io/discord)

Utopia Storage is a simple and lightweight library for managing application storage across multiple adapters. This library is designed to be easy to learn and use, with a consistent API regardless of the storage provider. This library is maintained by the [Appwrite team](https://appwrite.io).

This library is part of the [Utopia Framework](https://github.com/utopia-php/framework) project.

## Getting started

Install using Composer:
```bash
composer require utopia-php/storage
```

### Basic usage

Devices are immutable value objects: construct one with its configuration and use it anywhere, including across coroutines.

```php
<?php

require_once '../vendor/autoload.php';

use Utopia\Storage\Device\Local;

$device = new Local('/path/to/storage');

// Upload a file
$device->upload('/local/path/to/file.png', 'destination/path/file.png');

// Check if file exists
$exists = $device->exists('destination/path/file.png');

// Read file contents
$contents = $device->read('destination/path/file.png');

// Delete a file
$device->delete('destination/path/file.png');
```

## Available adapters

### Local storage

Use the local filesystem for storing files.

```php
use Utopia\Storage\Device\Local;

$device = new Local('/path/to/storage');
```

### AWS S3

Store files in Amazon S3 or compatible services.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\S3;

$device = new S3(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME.s3.us-east-1.amazonaws.com', // Host
    'us-east-1', // Region
    Acl::Private // Access control (default: private)
);
```

The provider-specific adapters below build the host for you from a bucket and region. Every S3-family adapter also accepts optional named constructor arguments:

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\AWS;
use Utopia\Storage\Device\S3;

$device = new AWS(
    'root',
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    AWS::US_EAST_1,
    Acl::Private,
    httpVersion: S3::HTTP_VERSION_2, // cURL HTTP version (default: cURL decides)
    retryAttempts: 3, // Retries on transient errors such as SlowDown (default: 3)
    retryDelay: 500, // Delay between retries in milliseconds (default: 500)
    telemetry: $telemetryAdapter, // utopia-php/telemetry adapter (default: none)
);

// Available ACL options
// Acl::Private, Acl::PublicRead, Acl::PublicReadWrite, Acl::AuthenticatedRead
```

### DigitalOcean Spaces

Store files in DigitalOcean Spaces.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\DOSpaces;

$device = new DOSpaces(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    DOSpaces::NYC3, // Region (default: nyc3)
    Acl::Private // Access control (default: private)
);

// Available regions
// DOSpaces::NYC3, DOSpaces::SGP1, DOSpaces::FRA1, DOSpaces::SFO2, DOSpaces::SFO3, DOSpaces::AMS3
```

### Backblaze B2

Store files in Backblaze B2 Cloud Storage.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\Backblaze;

$device = new Backblaze(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    Backblaze::US_WEST_004, // Region (default: us-west-004)
    Acl::Private // Access control (default: private)
);

// Available regions (clusters)
// Backblaze::US_WEST_000, Backblaze::US_WEST_001, Backblaze::US_WEST_002,
// Backblaze::US_WEST_004, Backblaze::EU_CENTRAL_003
```

### Linode Object Storage

Store files in Linode Object Storage.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\Linode;

$device = new Linode(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    Linode::EU_CENTRAL_1, // Region (default: eu-central-1)
    Acl::Private // Access control (default: private)
);

// Available regions
// Linode::EU_CENTRAL_1, Linode::US_SOUTHEAST_1, Linode::US_EAST_1, Linode::AP_SOUTH_1
```

### Wasabi cloud storage

Store files in Wasabi Cloud Storage.

```php
use Utopia\Storage\Acl;
use Utopia\Storage\Device\Wasabi;

$device = new Wasabi(
    'root', // Root path in bucket
    'YOUR_ACCESS_KEY',
    'YOUR_SECRET_KEY',
    'YOUR_BUCKET_NAME',
    Wasabi::EU_CENTRAL_1, // Region (default: eu-central-1)
    Acl::Private // Access control (default: private)
);

// Available regions
// Wasabi::US_EAST_1, Wasabi::US_EAST_2, Wasabi::US_WEST_1, Wasabi::US_CENTRAL_1,
// Wasabi::EU_CENTRAL_1, Wasabi::EU_CENTRAL_2, Wasabi::EU_WEST_1, Wasabi::EU_WEST_2,
// Wasabi::AP_NORTHEAST_1, Wasabi::AP_NORTHEAST_2
```

## Common operations

All storage adapters provide a consistent API for working with files:

```php
// Upload a file
$device->upload('/path/to/local/file.jpg', 'remote/path/file.jpg');

// Check if file exists
$exists = $device->exists('remote/path/file.jpg');

// Get file size
$size = $device->getFileSize('remote/path/file.jpg');

// Get file MIME type
$mime = $device->getFileMimeType('remote/path/file.jpg');

// Get file MD5 hash
$hash = $device->getFileHash('remote/path/file.jpg');

// Read file contents
$contents = $device->read('remote/path/file.jpg');

// Read partial file contents
$chunk = $device->read('remote/path/file.jpg', 0, 1024); // Read first 1KB

// Multipart/chunked uploads
$device->upload('/local/file.mp4', 'remote/video.mp4', 1, 3); // Part 1 of 3

// Create directory
$device->createDirectory('remote/new-directory');

// List files in directory
$files = $device->getFiles('remote/directory');

// Delete file
$device->delete('remote/path/file.jpg');

// Delete directory
$device->deletePath('remote/directory');

// Transfer files between storage devices
$sourceDevice->transfer('source/path.jpg', 'target/path.jpg', $targetDevice);

// Transfer with a custom chunk size (default: 20 MB)
$sourceDevice->transfer('source/path.jpg', 'target/path.jpg', $targetDevice, 10000000);
```

## Telemetry

Wrap any device with the `Telemetry` decorator to record a `storage.operation` histogram for every call through a [utopia-php/telemetry](https://github.com/utopia-php/telemetry) adapter:

```php
use Utopia\Storage\Device\Local;
use Utopia\Storage\Device\Telemetry;

$device = new Telemetry($telemetryAdapter, new Local('/path/to/storage'));
```

## Upgrading from 2.x

Version 3.0 makes every device immutable and safe to share across coroutines, and removes all global state:

- The static device registry is gone: replace `Storage::setDevice('files', $device)` and `Storage::getDevice('files')` with your own wiring (a container, or passing the device instance directly). `Storage` now only holds the `Storage::human()` helper.
- Setters are gone in favour of constructor arguments: `setTelemetry()`, `setHttpVersion()`, and the static `S3::setRetryAttempts()`/`S3::setRetryDelay()` became the `telemetry`, `httpVersion`, `retryAttempts`, and `retryDelay` named constructor arguments on `S3` and its subclasses.
- `setTransferChunkSize()`/`getTransferChunkSize()` became a per-call argument: `transfer($path, $destination, $device, $chunkSize)`.
- String constants became enums: the `Storage::DEVICE_*` constants are now the `Utopia\Storage\DeviceType` enum (`getType()` returns it), and the `S3::ACL_*` constants are now the `Utopia\Storage\Acl` enum.
- The S3 adapter no longer stores request headers on the instance, so one device can serve concurrent requests (for example Swoole coroutines) without data races.

## Adding new adapters

For information on adding new storage adapters, see the [Adding New Storage Adapter](https://github.com/utopia-php/storage/blob/master/docs/adding-new-storage-adapter.md) guide.

## System requirements

Utopia Storage requires PHP 8.5 or later. We recommend using the latest PHP version whenever possible.

## Contributing

For security issues, please email [security@appwrite.io](mailto:security@appwrite.io) instead of posting a public issue in GitHub.

All code contributions - including those of people having commit access - must go through a pull request and be approved by a core developer before being merged. This is to ensure a proper review of all the code.

We welcome you to contribute to the Utopia Storage library. For details on how to do this, please refer to our [Contributing Guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md).

## License

This library is available under the MIT License.

## Copyright

```
Copyright (c) 2019-2025 Appwrite Team <team@appwrite.io>
```
