# omnilab/openaq

> **Not tested live**: OpenAQ's API v3 requires a key (it answered 401 "Unauthorized. A valid API
> key must be provided in the X-API-Key header." to every call without one on 2026-10-15 and
> 2026-10-16), and no key was created for this package. The tests rest on the example payloads of
> OpenAQ's documentation and on its OpenAPI schema; only the refusal without a key was met live.
> See [Verified, and not](docs/index.md#verified-and-not).

Air quality measurements of [OpenAQ](https://openaq.org) for
[omniscient/omnilab](https://github.com/glitchr-studio/omnilab), through its API v3: the hourly or
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

Air quality from models, without a key, is [omnilab/open-meteo](https://github.com/glitchr-studio/omnilab-open-meteo)
(`api: air_quality`) and [omnilab/cams](https://github.com/glitchr-studio/omnilab-cams).

See [docs/](docs/index.md) for what is read, how it maps, and what the API does not give here.

## Terms of use

OpenAQ's API needs a key, which its user obtains by registering at
[explore.openaq.org/register](https://explore.openaq.org/register) and keeps secret; the key's
rate limit is 60 calls a minute and 2,000 an hour (more under a custom agreement), and repeated
excesses can suspend it: the source waits a second between two calls. OpenAQ's terms of use apply,
"including source attribution": credit OpenAQ and the data's provider and owner, under each
location's licence (the API gives it, `licenses`, with its attribution). Do not leave requests
running once the data are no longer needed.

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
