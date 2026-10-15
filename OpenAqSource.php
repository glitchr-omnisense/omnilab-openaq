<?php

namespace Omnilab\OpenAq;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Point;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAQ, the open platform of air quality measurements, through its API
 * v3 (api.openaq.org/v3): the measurements of the nearest location of a
 * point that measures the pollutant asked - PM2.5, PM10, NO2, O3, SO2, CO,
 * black carbon... by OpenAQ's parameter names - as hourly averages or daily
 * averages, from the stations of governments, research and low-cost
 * sensor networks the world over.
 *
 * A key is required (X-API-Key): OpenAQ answers 401 to a call without
 * one. 60 calls a minute and 2,000 an hour per key: a second between two
 * calls by default.
 */
final class OpenAqSource extends HttpSource
{
    public const BASE_URI = 'https://api.openaq.org/v3/';

    /** The most results a page holds here. */
    public const PAGE = 1000;

    /** The pages followed for one series, at most. */
    private const MAX_PAGES = 20;

    /** The largest radius OpenAQ searches around a point, in kilometres. */
    public const MAX_RADIUS = 25.0;

    /**
     * @param float $radius how far from a Point a location is looked for, in kilometres (25 at most)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly float $radius = 10.0,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 1.0,
    ) {
        if ($radius <= 0 || $radius > self::MAX_RADIUS) {
            throw new \InvalidArgumentException(\sprintf('OpenAQ searches up to %d km around a point, not %s.', self::MAX_RADIUS, $radius));
        }
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'openaq';
    }

    public function capabilities(): array
    {
        return [Capability::SERIES, Capability::HISTORICAL];
    }

    /**
     * The series of the nearest location of a Point that measures each
     * variable (OpenAQ's parameter names: "pm25", "pm10", "no2", "o3",
     * "so2", "co", "bc", "temperature", "relativehumidity"...), within the
     * configured radius: SeriesQuery::HOURLY, the hourly averages (a point
     * at each hour's start), or DAILY, the daily averages OpenAQ computes in
     * the location's local days. The unit is the sensor's (µg/m³, ppm...).
     * A variable no location near measures: no series for it. The period
     * defaults to the last seven days; a date without a time is the whole
     * day.
     */
    public function series(SeriesQuery $query): array
    {
        $this->station($query);
        if (!$query->place instanceof Point) {
            throw NotSupportedException::operation('openaq', 'serve a series over an area (a Point: the nearest location answers)');
        }
        $daily = SeriesQuery::DAILY === $query->step;
        if (!$daily && SeriesQuery::HOURLY !== $query->step) {
            throw NotSupportedException::operation('openaq', \sprintf('serve a step of %s (hourly or daily averages)', $query->step));
        }
        if (null !== $query->aggregation && Series::MEAN !== $query->aggregation) {
            throw NotSupportedException::operation('openaq', 'aggregate by '.$query->aggregation.' (OpenAQ averages)');
        }
        $timezone = new \DateTimeZone($query->timezone ?? 'UTC');
        [$from, $to] = self::period($query->from, $query->to, $timezone);

        $locations = $this->getJson('locations', [
            'coordinates' => round($query->place->latitude, 4).','.round($query->place->longitude, 4),
            'radius' => (int) round($this->radius * 1000),
            'limit' => 100,
        ])['results'] ?? [];

        $series = [];
        foreach ($query->variables as $variable) {
            $found = self::nearest($locations, $query->place, strtolower($variable));
            if (null === $found) {
                continue;
            }
            [$location, $sensor] = $found;
            $points = [];
            for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
                $data = $this->getJson('sensors/'.(int) $sensor['id'].($daily ? '/days' : '/hours'), $daily
                    ? ['date_from' => $from->format('Y-m-d'), 'date_to' => $to->modify('-1 day')->format('Y-m-d'), 'limit' => self::PAGE, 'page' => $page]
                    : ['datetime_from' => self::utc($from), 'datetime_to' => self::utc($to), 'limit' => self::PAGE, 'page' => $page]) ?? [];
                $results = array_values(array_filter($data['results'] ?? [], 'is_array'));
                foreach ($results as $result) {
                    $start = self::time($result['period']['datetimeFrom']['utc'] ?? null);
                    if (null !== $start) {
                        $local = $daily ? new \DateTimeImmutable(substr((string) ($result['period']['datetimeFrom']['local'] ?? $result['period']['datetimeFrom']['utc']), 0, 10), $timezone) : $start->setTimezone($timezone);
                        $points[$local->getTimestamp()] = [$local, is_numeric($result['value'] ?? null) ? 0 + $result['value'] : null];
                    }
                }
                if (\count($results) < self::PAGE) {
                    break;
                }
                if (self::MAX_PAGES === $page) {
                    throw new ProviderException('openaq', \sprintf('more than %d values were asked: narrow the period', self::MAX_PAGES * self::PAGE));
                }
            }
            ksort($points);
            $c = $location['coordinates'] ?? [];
            $place = is_numeric($c['latitude'] ?? null) && is_numeric($c['longitude'] ?? null) ? new Point((float) $c['latitude'], (float) $c['longitude']) : $query->place;
            $label = \sprintf('%s (OpenAQ location %d, sensor %d%s)', self::text($location['name'] ?? null) ?? 'location', (int) $location['id'], (int) $sensor['id'], null === ($provider = self::text($location['provider']['name'] ?? null)) ? '' : ', '.$provider);
            $series[] = new Series($variable, self::text($sensor['parameter']['units'] ?? null), $place, array_values($points), $query->step, Series::MEAN, $label, false, 'openaq');
        }

        return $series;
    }

    /**
     * The nearest location measuring the parameter, and its sensor.
     *
     * @param list<array<string, mixed>> $locations
     *
     * @return array{array<string, mixed>, array<string, mixed>}|null
     */
    private static function nearest(array $locations, Point $point, string $parameter): ?array
    {
        $best = null;
        foreach ($locations as $location) {
            if (!\is_array($location) || !isset($location['id'])) {
                continue;
            }
            foreach ($location['sensors'] ?? [] as $sensor) {
                if (\is_array($sensor) && isset($sensor['id']) && $parameter === strtolower((string) ($sensor['parameter']['name'] ?? ''))) {
                    $c = $location['coordinates'] ?? [];
                    $distance = is_numeric($location['distance'] ?? null) ? (float) $location['distance'] / 1000
                        : (is_numeric($c['latitude'] ?? null) && is_numeric($c['longitude'] ?? null) ? self::distance($point, new Point((float) $c['latitude'], (float) $c['longitude'])) : INF);
                    if (null === $best || $distance < $best[2]) {
                        $best = [$location, $sensor, $distance];
                    }
                    break;
                }
            }
        }

        return null === $best ? null : [$best[0], $best[1]];
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private static function period(?\DateTimeInterface $from, ?\DateTimeInterface $to, \DateTimeZone $timezone): array
    {
        $local = static fn (\DateTimeInterface $t) => new \DateTimeImmutable($t->format('Y-m-d H:i:s'), $timezone);
        $end = null === $to ? new \DateTimeImmutable('today', $timezone) : $local($to);
        if (null === $to || '00:00:00' === $end->format('H:i:s')) {
            $end = $end->modify('+1 day');
        }
        $start = null === $from ? $end->modify('-7 days') : $local($from);

        return [$start, $end];
    }

    private static function utc(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function distance(Point $a, Point $b): float
    {
        $dLat = deg2rad($b->latitude - $a->latitude);
        $dLon = deg2rad($b->longitude - $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a->latitude)) * cos(deg2rad($b->latitude)) * sin($dLon / 2) ** 2;

        return 6371.0 * 2 * asin(min(1.0, sqrt($h)));
    }
}
