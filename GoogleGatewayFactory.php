<?php

namespace Omnireview\Google;

use Omnireview\Config;
use Omnireview\Exception\InvalidConfigException;
use Omnireview\GatewayFactory;
use Omnireview\GatewayInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google reviews.
 *
 *   options:
 *     api_key: '%env(GOOGLE_PLACES_KEY)%'          # the Places API (New): anyone's place, up to five reviews
 *     language: fr                                 # the reviews' language (languageCode)
 *     # the owner's side, the Business Profile API: every review, replying
 *     client_id: '%env(default::GOOGLE_CLIENT_ID)%'
 *     client_secret: '%env(default::GOOGLE_CLIENT_SECRET)%'
 *     refresh_token: '%env(default::GOOGLE_REFRESH_TOKEN)%'   # granted once by the owner, scope business.manage
 *
 * The api_key, the owner's three, or both.
 */
final class GoogleGatewayFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnireview.factory_name' => 'google',
            'omnireview.factory_title' => 'Google',
            'omnireview.required_options' => [],
            'api_key' => null,
            'language' => null,
            'client_id' => null,
            'client_secret' => null,
            'refresh_token' => null,
        ]);
    }

    protected function build(Config $c): GatewayInterface
    {
        $owner = null !== $c->string('client_id') && null !== $c->string('client_secret') && null !== $c->string('refresh_token');
        if (null === $c->string('api_key') && !$owner) {
            throw new InvalidConfigException('The "google" gateway needs an api_key (Places), or the owner\'s client_id, client_secret and refresh_token (Business Profile).');
        }

        return new GoogleGateway($this->http ?? HttpClient::create(), $c->string('api_key'), $c->string('client_id'), $c->string('client_secret'), $c->string('refresh_token'), $c->string('language'));
    }
}
