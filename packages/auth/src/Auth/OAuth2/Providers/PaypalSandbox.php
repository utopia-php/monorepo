<?php

namespace Utopia\Auth\OAuth2\Providers;

class PaypalSandbox extends Paypal
{
    protected string $environment = 'sandbox';

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'paypalSandbox';
    }
}
