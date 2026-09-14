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

// Optional: cap the in-memory feature cache (default: 256 entries)
GeoTz::setCache(['maxItems' => 512]);

// Optional: preload all features into memory. See the memory note below -
// this is the expensive path, and it is unbounded on purpose.
GeoTz::preCache();
GeoTz::setCache(['preload' => true]);

// Optional: provide a Symfony cache pool or PSR-16 cache
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$pool = new FilesystemAdapter('geo_tz');
GeoTz::setCache(['store' => $pool]);

$psr16 = new Psr16Cache($pool);
GeoTz::setCache(['store' => $psr16]);
```

### Memory

Lookups decode only the quad they need, straight out of `geo.dat`, and keep the
result in an in-memory cache so repeat lookups near the same place stay fast.

That cache is **bounded to 256 entries by default**, evicting least-recently-used.
The bound matters: left unlimited, a long-running process keeps every quad it has
ever touched, and the full dataset has 21,525 of them - roughly **2GB** once
saturated. Bounded, a worker settles at tens of megabytes no matter how much
traffic it serves, and loses almost nothing in speed, because real traffic
clusters on populated places that stay in the hot set.

Raise `maxItems` if your traffic is unusually spread out, or set it to `0` for
the old unbounded behaviour. `preCache()` and `setCache(['preload' => true])`
remain unbounded by design - loading everything is the entire point of them, so
budget for the full cost if you use them.

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
