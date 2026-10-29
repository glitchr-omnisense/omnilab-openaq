<?php

namespace Omnilab\OpenAq\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Point;
use Omnilab\Model\Series;
use Omnilab\OpenAq\OpenAqSource;
use Omnilab\OpenAq\OpenAqSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\SeriesQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Not recorded from the service: OpenAQ answers 401 to a call without a
 * key, and none was created. locations-del-norte.json is the example
 * payload of OpenAQ's documentation (docs.openaq.org/resources/locations,
 * location 2178, Del Norte, Albuquerque), read on 2026-10-16; hours-3920.json
 * is built after the API's OpenAPI schema (HourlyData) around the value its
 * documentation gives for that location's PM2.5 sensor at 2024-09-25 22:00
 * UTC (4.4 µg/m³, docs.openaq.org/resources/latest); unauthorized.json is
 * the API's real answer without a key, met on 2026-10-15.
 */
final class OpenAqSourceTest extends TestCase
{
    /** @var list<array{string, array<string, string>, array<string, string>}> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = ['api_key' => 'test-key']): OpenAqSource
    {
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $headers = [];
            foreach ($o['headers'] ?? [] as $line) {
                [$name, $value] = explode(': ', $line, 2);
                $headers[strtolower($name)] = $value;
            }
            $this->calls[] = [$path, $query, $headers];
            if ($response) {
                return $response;
            }

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.match (true) {
                '/v3/locations' === $path => 'locations-del-norte.json',
                '/v3/sensors/3920/hours' === $path => 'hours-3920.json',
            }));
        });
        $source = (new OpenAqSourceFactory($http))->create(['throttle' => 0] + $options);
        self::assertInstanceOf(OpenAqSource::class, $source);

        return $source;
    }

    public function testCapabilities(): void
    {
        self::assertSame([Capability::SERIES, Capability::HISTORICAL], $this->source()->capabilities());
        self::assertSame('openaq', $this->source()->getName());
    }

    public function testTheHourlyAveragesOfTheNearestLocation(): void
    {
        $series = $this->source()->series(new SeriesQuery(['pm25', 'bc'], new Point(35.1353, -106.5847), new \DateTimeImmutable('2024-09-25'), new \DateTimeImmutable('2024-09-25')));

        // Del Norte has no black carbon sensor: a series without points for it, in its place.
        self::assertCount(2, $series);
        [$pm25, $bc] = $series;
        self::assertSame(['bc', null, [], SeriesQuery::HOURLY, 'openaq'], [$bc->variable, $bc->unit, $bc->points, $bc->step, $bc->source]);
        self::assertEquals(new Point(35.1353, -106.5847), $bc->place, 'the point asked');
        self::assertSame(['pm25', 'µg/m³', SeriesQuery::HOURLY, Series::MEAN, false, 'openaq'], [$pm25->variable, $pm25->unit, $pm25->step, $pm25->aggregation, $pm25->forecast, $pm25->source]);
        self::assertSame('Del Norte (OpenAQ location 2178, sensor 3920, AirNow)', $pm25->label);
        self::assertEquals(new Point(35.1353, -106.584702), $pm25->place);
        self::assertSame([4.4], $pm25->values());
        self::assertSame('2024-09-25T21:00:00+00:00', $pm25->times()[0]->format(\DATE_ATOM));

        self::assertSame(['/v3/locations', ['coordinates' => '35.1353,-106.5847', 'radius' => '10000', 'limit' => '100']], \array_slice($this->calls[0], 0, 2));
        self::assertSame('test-key', $this->calls[0][2]['x-api-key']);
        self::assertSame(['/v3/sensors/3920/hours', ['datetime_from' => '2024-09-25T00:00:00Z', 'datetime_to' => '2024-09-26T00:00:00Z', 'limit' => '1000', 'page' => '1']], \array_slice($this->calls[1], 0, 2));
    }

    public function testTheDailyAverages(): void
    {
        $responses = [new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/locations-del-norte.json')), new MockResponse('{"meta": {"name": "openaq-api", "website": "/", "page": 1, "limit": 1000, "found": 0}, "results": []}')];
        $calls = [];
        $source = (new OpenAqSourceFactory(new MockHttpClient(static function (string $method, string $url) use (&$responses, &$calls) {
            $calls[] = $url;

            return array_shift($responses);
        })))->create(['api_key' => 'test-key', 'throttle' => 0]);
        [$no2] = $source->series(new SeriesQuery(['no2'], new Point(35.1353, -106.5847), new \DateTimeImmutable('2024-09-01'), new \DateTimeImmutable('2024-09-07'), SeriesQuery::DAILY));

        self::assertSame(['ppm', SeriesQuery::DAILY, []], [$no2->unit, $no2->step, $no2->values()]);
        self::assertStringContainsString('/v3/sensors/3916/days?date_from=2024-09-01&date_to=2024-09-07&limit=1000&page=1', $calls[1]);
    }

    public function testWithoutAKeyOpenAqRefuses(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Unauthorized. A valid API key must be provided in the X-API-Key header.');
        try {
            $this->source(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/unauthorized.json'), ['http_code' => 401]), [])->series(new SeriesQuery(['pm25'], new Point(35.1353, -106.5847)));
        } finally {
            self::assertArrayNotHasKey('x-api-key', $this->calls[0][2]);
        }
    }

    public function testTooManyCalls(): void
    {
        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('{"message": "Too many requests"}', ['http_code' => 429]))->series(new SeriesQuery(['pm25'], new Point(35.1353, -106.5847)));
    }

    public function testRefusedBeforeAnyCall(): void
    {
        $source = $this->source();
        foreach ([
            new SeriesQuery(['pm25'], new BBox(-107, 35, -106, 36)),
            new SeriesQuery(['pm25'], new Point(35, -106), step: 'PT15M'),
            new SeriesQuery(['pm25'], new Point(35, -106), aggregation: Series::MAX),
        ] as $query) {
            try {
                $source->series($query);
                self::fail('Refused.');
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testARadiusPastOpenAqsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->source(null, ['radius_km' => 50]);
    }
}
