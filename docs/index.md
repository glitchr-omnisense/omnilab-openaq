# omnilab/openaq

OpenAQ's API v3 (`https://api.openaq.org/v3/`) for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
the hourly and daily averages of a pollutant (or a temperature, a humidity) at the nearest
location of a point that measures it, as series. A key is required. Not tested live with a key.

## Installation

```sh
composer require omnilab/openaq
```

```php
use Omnilab\OpenAq\OpenAqSourceFactory;

$openaq = (new OpenAqSourceFactory($httpClient))->create([
    'api_key' => getenv('OPENAQ_API_KEY'), // required by OpenAQ: the user's own key
    'radius_km' => 10,                     // optional: how far from a Point (25 at most)
]);
```

With the Symfony bundle, the factory is registered as `openaq` when the bundle knows it;
otherwise register `OpenAqSourceFactory` as a service.

## Credentials

Every call needs an OpenAQ API key: its user registers at
[explore.openaq.org/register](https://explore.openaq.org/register), keeps the key secret, and gives
it as `api_key` (sent as `X-API-Key`). The key's limit is 60 calls a minute and 2,000 an hour;
repeated excesses can suspend it. Without a key the source is built and its calls are made:
OpenAQ answers each with a 401.

## Options

The factory's options, with the defaults it applies (`OpenAqSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `api_key` | `?string` | null | no | the user's key, sent as `X-API-Key`; null: no header, and OpenAQ's 401 on every call |
| `radius_km` | `float` | `10` | no | how far from a `Point` a location is looked for, in kilometres: 25 at most (more is an `\InvalidArgumentException` at `create()`) |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `1` | no | seconds left between two calls: 60 a minute per key |
| `base_uri` | `string` | `https://api.openaq.org/v3/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `series`, `historical`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | no | locations are not given as entities: the series' label carries their ids |
| `search` | no |  |
| `series` | yes | at a `Point`: for each variable (OpenAQ's parameter: `pm25`, `no2`, `o3`...), the nearest location within `radius_km` measuring it, then its sensor's hourly or daily means; the period defaults to the last seven days |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no |  |
| `forecast` | no |  |
| `historical` | yes | the averages of the past; no forecast |
| `area` | no | a `BBox` is refused: the nearest location of a `Point` answers |
| `station` | no | a station's identifier is refused (the core has no scheme for OpenAQ's locations) |
| `cloud_cover` | no |  |

## Which API

OpenAQ's API v3 (`https://api.openaq.org/v3/`, OpenAPI 3.0.0 on 2026-10-16), the key in the
`X-API-Key` header:

| Call | For |
|---|---|
| `locations?coordinates=lat,lon&radius=&limit=100` | the locations within the radius (in metres, 25,000 at most), each with its sensors and the parameter each measures |
| `sensors/{id}/hours?datetime_from=&datetime_to=&limit=1000&page=` | the hourly averages ("the hourly average (mean) value of measurements") |
| `sensors/{id}/days?date_from=&date_to=&limit=1000&page=` | the daily averages ("computed from the hourly average values from 01:00 to 0:00 in local time") |

Pages of 1,000, followed until one is shorter (twenty at most for one series). Errors: a 401
without a key or with a wrong one, a 429 past the rate (a `RateLimitedException`), a 422 for a
bad parameter; their `message` (or `detail`) is said.

## Its shape

A **series** (`Capability::SERIES`, `HISTORICAL`): at a `Point`, for each variable asked, the
nearest location within `radius_km` that has a sensor of that parameter (by its `distance`, or the
great circle when the API gives none), then that sensor's averages. A variable no location near
measures gives a series without points, at the point asked: one series per variable asked, in
the order asked. The variables are
OpenAQ's parameter names: `pm25`, `pm10`, `pm1`, `no2`, `no`, `nox`, `o3`, `so2`, `co`, `bc`,
`temperature`, `relativehumidity`... and the unit the sensor's (`µg/m³`, `ppm`...: OpenAQ keeps
each provider's). `SeriesQuery::HOURLY` gives a point at each hour's start (the period's
`datetimeFrom`), `DAILY` each local day; both are means (`Series::MEAN`; any other aggregation,
another step, a box or a station are refused before any call). The period defaults to the last
seven days. The label names the location, its OpenAQ ids and its provider.

OpenAQ's locations are not given as entities: the core has no scheme for them, and the series'
label carries their ids.

## The Collector

No path.

## What the API does not give here

The raw measurements (`sensors/{id}/measurements`), the monthly, yearly and periodic aggregates
(hour of day, day of week...), the latest values, the flags, the locations as entities and their
search by country, provider or bounding box, the parameters, providers, owners, instruments,
manufacturers and licences lists are not read.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Exception\ProviderException;
use Omnilab\Model\Point;
use Omnilab\OpenAq\OpenAqSourceFactory;
use Omnilab\Source\SeriesQuery;
use Symfony\Component\HttpClient\HttpClient;

$openaq = (new OpenAqSourceFactory(HttpClient::create()))->create([
    'api_key' => getenv('OPENAQ_API_KEY') ?: null,   // required by OpenAQ: the user's own key
    'mailto' => 'you@example.org',
]);

$day = new DateTimeImmutable('2026-10-26');
try {
    foreach ($openaq->series(new SeriesQuery(['pm25', 'no2'], new Point(48.5734, 7.7521), $day, $day, SeriesQuery::DAILY)) as $series) {
        printf("%s %s at %s: %s\n", $series->variable, $series->unit, $series->label, implode(', ', $series->values()));
    }
} catch (ProviderException $e) {
    echo $e->status, ' ', $e->getMessage(), "\n";
}
```

Without a key (run on 2026-10-27: one call, `locations`):

```
401 [openaq] the service answered 401: Unauthorized. A valid API key must be provided in the X-API-Key header.
```

With a key it prints the daily means of PM2.5 and NO2 at the nearest locations of Strasbourg: not
run here (no key).

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        openaq: { factory: openaq, options: { api_key: '%env(OPENAQ_API_KEY)%', mailto: '%env(OMNILAB_MAILTO)%', radius_km: 10 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Before any call (`NotSupportedException`): a box, a station, a step other than hourly or daily,
an aggregation other than the mean. A 401 (no key, a wrong one) and a 422 (a bad parameter) are
`ProviderException`s with OpenAQ's `message` or `detail`; a 429 past the key's rate a
`RateLimitedException`. A series that would take more than twenty pages of 1,000 values is a
`ProviderException` ("narrow the period").

## Verified, and not

**Not tested live**: the API requires a key and none was created for this package (its user
obtains one). Met live on 2026-10-15: every call without a key answers 401 "Unauthorized. A
valid API key must be provided in the X-API-Key header." (`locations`, `parameters`); read on
2026-10-16: the OpenAPI schema (`api.openaq.org/openapi.json`), whose paths and fields the source
follows, and the documentation's example payloads. The fixtures are: the documentation's example
of location 2178 (Del Norte, Albuquerque, AirNow; its eight sensors) as the answer to
`locations`; an hourly answer built after the schema's `HourlyData` around the value the
documentation's `latest` example gives for that location's PM2.5 sensor 3920 (4.4 µg/m³ at
2024-09-25 22:00 UTC, taken here as the hour from 21:00); the real 401. What OpenAQ answers with a
key - the `distance` field, the hours' periods, the paging past 1,000, a daily answer - is not
verified.

On 2026-10-27 the example below ran without a key: OpenAQ's 401 again.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by OpenAQ. Its terms of use apply, "including source attribution": credit OpenAQ and the
data's provider and owner under each location's licence, keep the key secret and within its rate,
as the README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-openaq#terms-of-use)
says. Whoever runs the package holds the key and is responsible for complying with those terms and
for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-openaq#responsibility) section.
