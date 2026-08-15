<?php

declare(strict_types=1);

namespace Utopia\Tests\Auth\OAuth2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Auth\OAuth2\Provider;
use Utopia\Auth\OAuth2\Providers\Amazon;
use Utopia\Auth\OAuth2\Providers\Apple;
use Utopia\Auth\OAuth2\Providers\Appwrite;
use Utopia\Auth\OAuth2\Providers\Auth0;
use Utopia\Auth\OAuth2\Providers\Authentik;
use Utopia\Auth\OAuth2\Providers\Autodesk;
use Utopia\Auth\OAuth2\Providers\Bitbucket;
use Utopia\Auth\OAuth2\Providers\Bitly;
use Utopia\Auth\OAuth2\Providers\Box;
use Utopia\Auth\OAuth2\Providers\Dailymotion;
use Utopia\Auth\OAuth2\Providers\Discord;
use Utopia\Auth\OAuth2\Providers\Disqus;
use Utopia\Auth\OAuth2\Providers\Dropbox;
use Utopia\Auth\OAuth2\Providers\Etsy;
use Utopia\Auth\OAuth2\Providers\Facebook;
use Utopia\Auth\OAuth2\Providers\Figma;
use Utopia\Auth\OAuth2\Providers\FusionAuth;
use Utopia\Auth\OAuth2\Providers\Gitea;
use Utopia\Auth\OAuth2\Providers\Github;
use Utopia\Auth\OAuth2\Providers\Gitlab;
use Utopia\Auth\OAuth2\Providers\Google;
use Utopia\Auth\OAuth2\Providers\Keycloak;
use Utopia\Auth\OAuth2\Providers\Kick;
use Utopia\Auth\OAuth2\Providers\Linkedin;
use Utopia\Auth\OAuth2\Providers\Microsoft;
use Utopia\Auth\OAuth2\Providers\Mock;
use Utopia\Auth\OAuth2\Providers\Notion;
use Utopia\Auth\OAuth2\Providers\Oidc;
use Utopia\Auth\OAuth2\Providers\Okta;
use Utopia\Auth\OAuth2\Providers\Paypal;
use Utopia\Auth\OAuth2\Providers\Podio;
use Utopia\Auth\OAuth2\Providers\Salesforce;
use Utopia\Auth\OAuth2\Providers\Slack;
use Utopia\Auth\OAuth2\Providers\Spotify;
use Utopia\Auth\OAuth2\Providers\Stripe;
use Utopia\Auth\OAuth2\Providers\Tradeshift;
use Utopia\Auth\OAuth2\Providers\Twitch;
use Utopia\Auth\OAuth2\Providers\WordPress;
use Utopia\Auth\OAuth2\Providers\X;
use Utopia\Auth\OAuth2\Providers\Yahoo;
use Utopia\Auth\OAuth2\Providers\Yammer;
use Utopia\Auth\OAuth2\Providers\Yandex;
use Utopia\Auth\OAuth2\Providers\Zoho;
use Utopia\Auth\OAuth2\Providers\Zoom;

final class ProviderLoginUrlTest extends TestCase
{
    /**
     * @return \Iterator<string, array{class-string<Provider>, string}>
     */
    public static function providers(): \Iterator
    {
        $map = [
            Amazon::class => 'amazon',
            Apple::class => 'apple',
            Appwrite::class => 'appwrite',
            Auth0::class => 'auth0',
            Authentik::class => 'authentik',
            Autodesk::class => 'autodesk',
            Bitbucket::class => 'bitbucket',
            Bitly::class => 'bitly',
            Box::class => 'box',
            Dailymotion::class => 'dailymotion',
            Discord::class => 'discord',
            Disqus::class => 'disqus',
            Dropbox::class => 'dropbox',
            Etsy::class => 'etsy',
            Facebook::class => 'facebook',
            Figma::class => 'figma',
            FusionAuth::class => 'fusionauth',
            Gitea::class => 'gitea',
            Github::class => 'github',
            Gitlab::class => 'gitlab',
            Google::class => 'google',
            Keycloak::class => 'keycloak',
            Kick::class => 'kick',
            Linkedin::class => 'linkedin',
            Microsoft::class => 'microsoft',
            Mock::class => 'mock',
            Notion::class => 'notion',
            Okta::class => 'okta',
            Paypal::class => 'paypal',
            Podio::class => 'podio',
            Salesforce::class => 'Salesforce',
            Slack::class => 'slack',
            Spotify::class => 'spotify',
            Stripe::class => 'stripe',
            Tradeshift::class => 'tradeshift',
            Twitch::class => 'twitch',
            WordPress::class => 'wordpress',
            X::class => 'x',
            Yahoo::class => 'yahoo',
            Yammer::class => 'yammer',
            Yandex::class => 'Yandex',
            Zoho::class => 'zoho',
            Zoom::class => 'zoom',
        ];

        foreach ($map as $class => $name) {
            yield $name => [$class, $name];
        }
    }

    /**
     * @param class-string<Provider> $class
     */
    #[DataProvider('providers')]
    public function testLoginUrlContainsClientId(string $class, string $name): void
    {
        $provider = new $class(
            'client-id',
            json_encode([
                'clientSecret' => 'client-secret',
                'auth0Domain' => 'example.auth0.com',
                'oktaDomain' => 'example.okta.com',
                'endpoint' => 'https://gitlab.com',
                'authorizationEndpoint' => 'https://idp.example.com/authorize',
            ], JSON_THROW_ON_ERROR),
            'https://example.com/callback',
            [],
            [],
            new FakeHttpClient(),
            'unit-test-openssl-key',
        );

        if ($provider instanceof Gitea) {
            $provider->setEndpoint('https://gitea.example.com');
        }

        $this->assertSame($name, $provider->getName());
        $url = $provider->getLoginURL();
        $this->assertNotSame('', $url);
        $this->assertStringContainsString('client-id', $url);
    }

    public function testOidcLoginUrlNeedsWellKnownOrEndpoints(): void
    {
        $oidc = new Oidc(
            'client-id',
            json_encode([
                'clientSecret' => 'secret',
                'authorizationEndpoint' => 'https://idp.example.com/authorize',
            ], JSON_THROW_ON_ERROR),
            'https://example.com/callback',
        );

        $this->assertStringContainsString('https://idp.example.com/authorize', $oidc->getLoginURL());
    }
}
