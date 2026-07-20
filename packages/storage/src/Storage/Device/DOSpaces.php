<?php

declare(strict_types=1);

namespace Utopia\Storage\Device;

use Psr\Http\Client\ClientInterface;
use Utopia\Storage\Acl;
use Utopia\Storage\DeviceType;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Adapter\None as NoTelemetry;

class DOSpaces extends S3
{
    /**
     * Regions constants
     */
    public const SGP1 = 'sgp1';

    public const NYC3 = 'nyc3';

    public const FRA1 = 'fra1';

    public const SFO2 = 'sfo2';

    public const SFO3 = 'sfo3';

    public const AMS3 = 'AMS3';

    /**
     * DOSpaces Constructor
     *
     * @param  int  $retryDelay  Delay between retries in milliseconds
     */
    public function __construct(
        string $root,
        string $accessKey,
        #[\SensitiveParameter]
        string $secretKey,
        string $bucket,
        string $region = self::NYC3,
        Acl $acl = Acl::Private,
        int $retryAttempts = 3,
        int $retryDelay = 500,
        Telemetry $telemetry = new NoTelemetry(),
        ?ClientInterface $client = null,
    ) {
        $host = $bucket . '.' . $region . '.digitaloceanspaces.com';
        parent::__construct($root, $accessKey, $secretKey, $host, $region, $acl, $retryAttempts, $retryDelay, $telemetry, $client);
    }

    #[\Override]
    public function getName(): string
    {
        return 'Digitalocean Spaces Storage';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Digitalocean Spaces Storage';
    }

    #[\Override]
    public function getType(): DeviceType
    {
        return DeviceType::DoSpaces;
    }
}
