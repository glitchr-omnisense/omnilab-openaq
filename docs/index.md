# omnilab/openaq

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
measures gives no series (the list may be shorter than the variables asked). The variables are
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
