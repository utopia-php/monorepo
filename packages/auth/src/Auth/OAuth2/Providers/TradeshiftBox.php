<?php

namespace Utopia\Auth\OAuth2\Providers;

class TradeshiftBox extends Tradeshift
{
    protected string $environment = 'sandbox';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'tradeshiftBox';
    }
}
