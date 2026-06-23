# nats.php

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/nats`](https://github.com/utopia-php/monorepo/tree/main/packages/nats) — please open issues and pull requests there.

A modern PHP client for [NATS](https://nats.io) messaging system with JetStream and Key-Value store support.

## Requirements

- PHP 8.1+
- `ext-json`
- `ext-sodium` (optional, for NKey/JWT authentication)

## Installation

```bash
composer require utopia-php/nats
```

## Quick Start

```php
use Utopia\NATS\Connection;

$conn = Connection::connect('nats://127.0.0.1:4222');

// Publish
$conn->publish('greet.world', 'Hello, World!');

// Subscribe
$conn->subscribe('greet.*', function ($msg) {
    echo "Received: {$msg->data}\n";
});

// Process messages
$conn->wait();
```

## Core NATS

### Connecting

```php
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;

// Simple
$conn = Connection::connect('nats://127.0.0.1:4222');

// With options
$conn = Connection::connect(new ConnectionOptions(
    servers: ['nats://host1:4222', 'nats://host2:4222'],
    name: 'my-service',
    user: 'alice',
    pass: 'secret',
    connectTimeout: 5.0,
    allowReconnect: true,
    maxReconnectAttempts: 60,
));

// From URL with credentials
$conn = Connection::connect('nats://user:pass@127.0.0.1:4222');
```

### Publishing

```php
use Utopia\NATS\Headers;

// Simple publish
$conn->publish('orders.new', '{"id": 1}');

// Publish with headers
$headers = new Headers();
$headers->set('Content-Type', 'application/json');
$headers->set('X-Trace-Id', 'abc-123');
$conn->publish('orders.new', '{"id": 1}', headers: $headers);
```

### Subscribing

```php
// Async with callback
$sub = $conn->subscribe('orders.*', function ($msg) {
    echo "{$msg->subject}: {$msg->data}\n";
});

// Sync
$sub = $conn->subscribe('orders.new');
$msg = $sub->nextMessage(timeout: 5.0);

// Wildcards
$conn->subscribe('events.>',  fn($msg) => handle($msg)); // multi-level
$conn->subscribe('orders.*',  fn($msg) => handle($msg)); // single-level

// Queue groups (load-balanced)
$conn->queueSubscribe('tasks', 'workers', function ($msg) {
    processTask($msg->data);
});

// Unsubscribe
$sub->unsubscribe();

// Auto-unsubscribe after N messages
$conn->unsubscribe($sub, maxMessages: 10);
```

### Request-Reply

```php
// Responder
$conn->subscribe('math.double', function ($msg) use ($conn) {
    $value = (int) $msg->data;
    $conn->publish($msg->replyTo, (string) ($value * 2));
});

// Requester
$response = $conn->request('math.double', '21', timeout: 2.0);
echo $response->data; // "42"
```

### Connection Management

```php
$conn->flush();                    // Ensure all messages are sent
$conn->drain();                    // Gracefully close (flush + unsubscribe)
$conn->close();                    // Immediate close

$conn->isConnected();              // Check status
$conn->getServerInfo()->version;   // Server info
```

## JetStream

### Streams

```php
use Utopia\NATS\JetStream\StreamConfig;
use Utopia\NATS\JetStream\StorageType;
use Utopia\NATS\JetStream\RetentionPolicy;

$js = $conn->jetStream();

// Create a stream
$stream = $js->createOrUpdateStream(new StreamConfig(
    name: 'ORDERS',
    subjects: ['orders.>'],
    storage: StorageType::File,
    retention: RetentionPolicy::Limits,
    maxAge: 86400.0, // 1 day in seconds
    replicas: 1,
));

// Stream info
$info = $stream->info(refresh: true);
echo "Messages: {$info->state->messages}\n";

// List streams
$names = $js->getStreamNames();

// Delete
$stream->delete();
```

### Publishing with Acknowledgment

```php
$ack = $js->publish('orders.new', '{"id": 1}');
echo "Stream: {$ack->stream}, Seq: {$ack->sequence}\n";

// With deduplication
$ack = $js->publish('orders.new', '{"id": 1}', msgId: 'order-1');

// With expected sequence (optimistic concurrency)
$ack = $js->publish('orders.new', $data, expectedLastSeq: 42);
```

### Consumers

```php
use Utopia\NATS\JetStream\ConsumerConfig;
use Utopia\NATS\JetStream\AckPolicy;
use Utopia\NATS\JetStream\DeliverPolicy;

// Create a pull consumer
$consumer = $js->createConsumer('ORDERS', new ConsumerConfig(
    name: 'order-processor',
    durableName: 'order-processor',
    ackPolicy: AckPolicy::Explicit,
    filterSubject: 'orders.>',
    deliverPolicy: DeliverPolicy::All,
));

// Fetch a batch of messages
$batch = $consumer->fetch(batch: 10, timeout: 5.0);
foreach ($batch as $msg) {
    echo "Processing: {$msg->getData()}\n";

    $msg->ack();          // Acknowledge
    // $msg->nak();       // Negative ack (redeliver)
    // $msg->term();      // Terminate (no redeliver)
    // $msg->inProgress(); // Extend ack deadline
}

// Fetch single message
$msg = $consumer->next(timeout: 5.0);

// Message metadata
$meta = $msg->metadata();
echo "Stream seq: {$meta->streamSequence}\n";
echo "Deliveries: {$meta->numDelivered}\n";
```

## Key-Value Store

```php
use Utopia\NATS\KeyValue\KeyValueConfig;
use Utopia\NATS\JetStream\StorageType;

$js = $conn->jetStream();

// Create a KV bucket
$kv = $js->createKeyValue(new KeyValueConfig(
    bucket: 'config',
    history: 5,
    ttl: 3600.0, // 1 hour
    storage: StorageType::File,
));

// Put
$revision = $kv->put('app.name', 'My Service');

// Get
$entry = $kv->get('app.name');
echo "{$entry->key} = {$entry->value} (rev: {$entry->revision})\n";

// Create (fails if key exists)
$revision = $kv->create('app.version', '1.0.0');

// Update with CAS (compare-and-swap)
$revision = $kv->update('app.version', '1.1.0', revision: $revision);

// Delete / Purge
$kv->delete('app.name');
$kv->purge('app.version');

// List keys
$keys = $kv->keys();

// Bucket status
$status = $kv->status();
echo "Values: {$status->values}, Bytes: {$status->bytes}\n";
```

## Authentication

```php
use Utopia\NATS\ConnectionOptions;

// User/Password
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    user: 'alice',
    pass: 'secret',
));

// Token
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    token: 'my-token',
));

// NKey (requires ext-sodium)
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    nkey: 'UABC...',
    nkeySeed: 'SUABC...',
));

// JWT Credentials file (requires ext-sodium)
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    credentialsFile: '/path/to/user.creds',
));
```

## TLS

```php
use Utopia\NATS\ConnectionOptions;

// TLS with system CA
$conn = Connection::connect(new ConnectionOptions(
    servers: 'tls://nats.example.com:4222',
));

// TLS with custom CA and client certificates (mTLS)
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    tls: true,
    tlsCaFile: '/path/to/ca.pem',
    tlsCertFile: '/path/to/client-cert.pem',
    tlsKeyFile: '/path/to/client-key.pem',
));
```

## Event Callbacks

```php
$conn = Connection::connect(new ConnectionOptions(
    servers: 'nats://127.0.0.1:4222',
    onDisconnect: function () {
        echo "Disconnected!\n";
    },
    onReconnect: function () {
        echo "Reconnected!\n";
    },
    onClose: function () {
        echo "Connection closed.\n";
    },
    onError: function ($e) {
        echo "Error: {$e->getMessage()}\n";
    },
));
```

## Testing

```bash
# Unit tests
./vendor/bin/phpunit --testsuite unit

# Integration tests (requires a running nats-server)
./vendor/bin/phpunit --testsuite integration

# With custom NATS URL
NATS_URL=nats://host:4222 ./vendor/bin/phpunit --testsuite integration
```

## License

Apache-2.0
