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

// Optional: cap the in-memory feature cache (default: 64 entries)
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

There are two costs: the quadtree index, and the cache of decoded features.

**The index is read from disk, not loaded into memory.** It is packed into a
fixed-layout binary (`data/*.geojson.index.bin`) whose nodes are addressed by
byte offset, so a lookup reads only the nodes on its path - 6 or 7 of the 56,221
in the full dataset. Holding all three datasets open costs under 1MB. The
equivalent JSON index cost about 20MB per dataset per process.

That distinction matters under a threaded runtime. Each FrankenPHP worker thread
has its own PHP context, so a decoded index is held once *per thread* and memory
grows with the thread count. The packed file is read through the OS page cache
instead, which is one copy per machine shared by every thread and every process.

**The feature cache is bounded to 64 entries by default**, evicting
least-recently-used. The bound matters: left unlimited, a long-running process
keeps every quad it has ever touched, and the full dataset has 21,525 of them -
roughly **2GB** once saturated. Real traffic clusters on populated places, so a
small cache loses very little.

The entries are decoded quads, not megabytes, and they vary in size with how
complex the local borders are. Since the right bound depends on how many threads
the deployment runs - something the library cannot know - it is settable from
the environment:

```bash
GEO_TZ_CACHE_ITEMS=16    # tighter, for high thread counts
GEO_TZ_CACHE_ITEMS=0     # unbounded
```

An explicit `setCache(['maxItems' => n])` still wins over the environment, and a
malformed value falls back to the default rather than throwing - taking a
service down over a typo in an env var is the worse failure.

Lowering it costs very little speed. Measured in a single process over 6,000
clustered lookups on the full dataset:

| maxItems | Per lookup | Arena |
|---|---|---|
| 256 | 0.0339 ms | 14.0 MB |
| 64 (default) | 0.0390 ms | 4.0 MB |
| 16 | 0.0411 ms | 2.0 MB |
| 4 | 0.0507 ms | 4.0 MB |

Going from 64 to 16 costs about 2 microseconds per lookup - well under 1% of the
time an HTTP request around it takes.

What actually decides both speed and memory is whether the working set fits, not
the size of the bound. Measured behind FrankenPHP at 40 worker threads:

| Traffic | Hit rate | Mean request | Resident |
|---|---|---|---|
| Repeating a bounded set of places | 99% | 0.56 ms | 178 MB |
| Scattered widely over the globe | 5% | 5.07 ms | 1116 MB |

Under the first, every bound from 16 to 256 performs identically - the hot quads
fit in all of them. Under the second the cache cannot help whatever its size,
and a larger bound only means each thread retains more of what it will not reuse.
So size this for the worst case you are willing to pay for, not for the hit rate
you hope to get.

Measured on the full dataset, 8,000 clustered lookups, one process per row:

| Index | Cache | Index resident | Steady state | Per lookup |
|---|---|---|---|---|
| JSON | 256 | 20.71 MB | 38.84 MB | 0.025 ms |
| JSON | 64 | 20.71 MB | 29.19 MB | 0.032 ms |
| packed | 64 (default) | 0.31 MB | 8.79 MB | 0.040 ms |
| packed | 16 | 0.31 MB | 3.66 MB | 0.040 ms |

Reading the index costs roughly 8 microseconds per lookup against a warm cache -
the price of an fseek over an array access. Startup drops from ~12ms to ~1ms,
which is worth having when every thread pays it.

Raise `maxItems` if your traffic is unusually spread out, or set it to `0` for
unbounded. `preCache()` and `setCache(['preload' => true])` remain unbounded by
design - loading everything is the entire point of them, so budget for the full
cost if you use them.

The file handle is opened once and held, so replacing the data files under a
running process has no effect until it restarts. That is deliberate: an index
and its `geo.dat` are a matched pair, and reading half of each would silently
return the wrong timezone.

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

This downloads the latest data files from the `master` branch, rebuilds the
packed indexes from them, and writes `data/SOURCE.json` with the commit SHA and
timestamps.

Any existing `*.index.bin` is deleted before the rebuild. A packed index holds
byte offsets into one specific `geo.dat`, so pairing a new one with an old index
would read features from the wrong place; if packing fails the library falls
back to the freshly downloaded JSON rather than trusting a stale binary.

To rebuild the packed indexes without downloading anything - after editing the
data by hand, or when upgrading from a version that shipped only JSON:

```bash
composer pack-index              # all three datasets
php scripts/pack-index.php timezones-now
```

The packer refuses anything it does not recognise rather than guessing. If
upstream changes the index format, the build stops with the offending node's
path instead of producing an index that quietly answers with the wrong timezone.

Packing the same JSON twice produces byte-identical output, so a committed index
can be verified against its source.

## Environment

Set `GEO_TZ_DATA_PATH` to point at an alternate directory containing the data
files if you want to store them outside the package. Both the index and the
`*.geo.dat` files are looked up there first, falling back to the package's own
`data/` - they are a matched pair and must come from the same place.

`scripts/pack-index.php` honours the same variable, and writes the packed index
next to the JSON it was built from.

Set `GEO_TZ_CACHE_ITEMS` to bound the in-memory feature cache - see the memory
section above. Unset, it defaults to 64.
