# omnilab/openaq

> **Not tested live**: OpenAQ's API v3 requires a key (it answered 401 "Unauthorized. A valid API
> key must be provided in the X-API-Key header." to every call without one on 2026-10-15 and
> 2026-10-16), and no key was created for this package. The tests rest on the example payloads of
> OpenAQ's documentation and on its OpenAPI schema; only the refusal without a key was met live.
> See [Verified, and not](docs/index.md#verified-and-not).

Air quality measurements of [OpenAQ](https://openaq.org) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab), through its API v3: the hourly or
daily averages of a pollutant - PM2.5, PM10, NO2, O3, SO2, CO, black carbon... by OpenAQ's
parameter names - at the nearest location of a point that measures it, from the reference
monitors of governments and the low-cost sensors that OpenAQ gathers the world over.

```php
$openaq = (new OpenAqSourceFactory($http))->create(['api_key' => $yourOpenAqKey]);
[$pm25] = $openaq->series(new SeriesQuery(['pm25'], new Point(35.1353, -106.5847),
    new \DateTimeImmutable('2024-09-25'), new \DateTimeImmutable('2024-09-25')));
$pm25->label;    // Del Norte (OpenAQ location 2178, sensor 3920, AirNow)
$pm25->unit;     // µg/m³
```

```yaml
omnilab:
    sources:
        openaq:
            factory: openaq
            options:
                api_key: '%env(OPENAQ_API_KEY)%'   # your own key, from explore.openaq.org/register
                radius_km: 10                    # how far from a Point a location is looked for (25 at most)
```

Air quality from models, without a key, is [omnilab/open-meteo](https://github.com/glitchr-omnisense/omnilab-open-meteo)
(`api: air_quality`) and [omnilab/cams](https://github.com/glitchr-omnisense/omnilab-cams).

See [docs/](docs/index.md) for what is read, how it maps, and what the API does not give here.

## Install and a first call

```sh
composer require omnilab/openaq
```

OpenAQ's API v3 requires a key (free, with an account at explore.openaq.org). This first call needs
none: an hour of PM2.5 at Albuquerque's Del Norte station, its two answers taken from OpenAQ's
documented examples (trimmed), so nothing leaves the process (Symfony's `MockHttpClient` answers in
place of the service). A real call gives the factory no client (it makes its own) and the source
`['api_key' => getenv('OPENAQ_API_KEY')]`.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Point;
use Omnilab\OpenAq\OpenAqSourceFactory;
use Omnilab\Source\SeriesQuery;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// The service's answer, recorded: no account, and nothing leaves the process.
$http = new MockHttpClient([
    new MockResponse('{"meta": {"found": 1}, "results": [{"id": 2178, "name": "Del Norte", "locality": "Albuquerque", "timezone": "America/Denver",
        "provider": {"id": 119, "name": "AirNow"}, "sensors": [{"id": 3920, "name": "pm25 µg/m³", "parameter": {"id": 2, "name": "pm25", "units": "µg/m³"}}],
        "coordinates": {"latitude": 35.1353, "longitude": -106.584702}, "distance": 120.5}]}'),
    new MockResponse('{"meta": {"found": 1}, "results": [{"value": 4.4, "parameter": {"id": 2, "name": "pm25", "units": "µg/m³"},
        "period": {"label": "1hour", "datetimeFrom": {"utc": "2024-09-25T21:00:00Z"}, "datetimeTo": {"utc": "2024-09-25T22:00:00Z"}}}]}'),
]);
$openaq = (new OpenAqSourceFactory($http))->create(['api_key' => 'not-a-key']);
$day = new DateTimeImmutable('2024-09-25');
[$pm25] = $openaq->series(new SeriesQuery(['pm25'], new Point(35.1353, -106.5847), $day, $day));
echo $pm25->label, ': ', implode(' ', $pm25->values()), ' ', $pm25->unit, "\n";
```

Its output:

```
Del Norte (OpenAQ location 2178, sensor 3920, AirNow): 4.4 µg/m³
```

## Terms of use

OpenAQ's API needs a key, which its user obtains by registering at
[explore.openaq.org/register](https://explore.openaq.org/register) and keeps secret; the key's
rate limit is 60 calls a minute and 2,000 an hour (more under a custom agreement), and repeated
excesses can suspend it: the source waits a second between two calls. OpenAQ's terms of use apply,
"including source attribution": credit OpenAQ and the data's provider and owner, under each
location's licence (the API gives it, `licenses`, with its attribution). Do not leave requests
running once the data are no longer needed.

## Usage policy

Declared as data in [`policy.json`](policy.json) (read at https://docs.openaq.org/using-the-api/rate-limits on 2026-11-10), and honoured on every request by the
core: a limit reached waits when its window ends within `max_wait` seconds, else the request is not
sent ([Usage policies](https://github.com/glitchr-omnisense/omnilab/blob/1.x/docs/policies.md)).

- Limits: 60 a minute per key; 2,000 a hour per key.
- Licence: not stated; commercial use: unknown.
- Credit: required: OpenAQ (https://openaq.org).
- Needs: a key.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by OpenAQ. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
