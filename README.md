# geo-tz (PHP)

A PHP 8.2+ port of the `node-geo-tz` public API for finding the timezone at a GPS coordinate.

Original project: `node-geo-tz` by Evan Siroky (https://github.com/evansiroky/node-geo-tz).

## Install

```bash
composer require mamluk/geo-tz
```

## Usage

Default dataset (1970 timezones):

```php
use GeoTz\GeoTz;

$tzids = GeoTz::find(47.650499, -122.35007);

// Optional: preload all features into memory
GeoTz::preCache();

// Optional: control caching
GeoTz::setCache(['preload' => true]);

// Optional: provide a Symfony cache pool or PSR-16 cache
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$pool = new FilesystemAdapter('geo_tz');
GeoTz::setCache(['store' => $pool]);

$psr16 = new Psr16Cache($pool);
GeoTz::setCache(['store' => $psr16]);
```

Full dataset (all timezones):

```php
use GeoTz\All\GeoTz as AllGeoTz;

$tzids = AllGeoTz::find(12.826174, 45.036933);
```

"Now" dataset (latest distinct zones):

```php
use GeoTz\Now\GeoTz as NowGeoTz;

$tzids = NowGeoTz::find(12.826174, 45.036933);
```

## Data updates

The data files are vendored under `data/`. To refresh them from the upstream
`node-geo-tz` GitHub repository:

```bash
composer update-data
```

This downloads the latest data files from the `master` branch and writes
`data/SOURCE.json` with the commit SHA and timestamps.

## Environment

Set `GEO_TZ_DATA_PATH` to point at an alternate directory containing the
`*.geo.dat` files if you want to store them outside the package.
