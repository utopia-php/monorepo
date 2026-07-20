<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Utopia\Storage\Acl;
use Utopia\Storage\DeviceType;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

class Backblaze extends S3
{
    /**
     * Region constants
     *
     * (Technically, these are clusters. There are two Backblaze regions,
     * US West and EU Central.)
     */
    public const US_WEST_000 = 'us-west-000';

    public const US_WEST_001 = 'us-west-001';

    public const US_WEST_002 = 'us-west-002';

    public const US_WEST_004 = 'us-west-004';

    public const EU_CENTRAL_003 = 'eu-central-003';

    /**
     * Backblaze Constructor
     *
     * @param  int  $retryDelay  Delay between retries in milliseconds
     */
    public function __construct(
        string $root,
        string $accessKey,
        #[\SensitiveParameter]
        string $secretKey,
        string $bucket,
        string $region = self::US_WEST_004,
        Acl $acl = Acl::Private,
        ?int $httpVersion = null,
        int $retryAttempts = 3,
        int $retryDelay = 500,
        Telemetry $telemetry = new NoTelemetry(),
    ) {
        $host = $bucket . '.' . 's3' . '.' . $region . '.backblazeb2.com';
        parent::__construct($root, $accessKey, $secretKey, $host, $region, $acl, $httpVersion, $retryAttempts, $retryDelay, $telemetry);
    }

    #[\Override]
    public function getName(): string
    {
        return 'Backblaze B2 Storage';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Backblaze B2 Storage';
    }

    #[\Override]
    public function getType(): DeviceType
    {
        return DeviceType::Backblaze;
    }
}
