<?php

namespace Omnilab\OpenAq;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * OpenAQ (API v3): hourly and daily averages of air quality at the nearest
 * location of a point. A key is required: OpenAQ answers 401 without one.
 *
 *   options:
 *     api_key: '%env(OPENAQ_API_KEY)%' # sent as X-API-Key; from explore.openaq.org/register
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     radius_km: 10                    # how far from a Point a location is looked for (25 at most)
 *     throttle: 1                      # seconds between two calls: 60 a minute per key
 *     base_uri: 'https://api.openaq.org/v3/'
 */
final class OpenAqSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'openaq',
            'omnilab.required_options' => [],
            'api_key' => null,
            'radius_km' => 10.0,
            'throttle' => 1.0,
            'base_uri' => OpenAqSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        $key = $c->get('api_key');

        return new OpenAqSource(
            $this->http ?? HttpClient::create(),
            (float) $c->get('radius_km'),
            (string) $c->get('base_uri'),
            ['User-Agent' => self::userAgent($c)] + (null === $key || '' === $key ? [] : ['X-API-Key' => (string) $key]),
            (float) $c->get('throttle'),
        );
    }
}
