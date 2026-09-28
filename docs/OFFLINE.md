# Offline map packages

This contract describes legacy self-hosted packages. The current mobile map also downloads OpenFreeMap areas automatically; package support remains compatible.

MapLibre uses `pmtiles://file:///…` for local archives. The style itself is an absolute file path, without a `file://` prefix. References: [PMTiles](https://maplibre.org/flutter-maplibre-gl/advanced/pmtiles/), [local resources](https://maplibre.org/flutter-maplibre-gl/concepts/styles/#local-sprites-and-glyphs).

## Building a package

Prepare a vector **PMTiles v3 archive using the Protomaps schema**, from appropriately licensed data or OSM through Planetiler. Mapsforge `.map` is incompatible. Never bulk-prefetch `tile.openstreetmap.org`.

From the server root, with Python 3.11+ standard library:

```sh
python3 tools/package_region.py --pmtiles /srv/source/region.pmtiles --id basque-country --name 'Basque Country' --version 2026-09 --base-url https://hiking.example.org --output data/maps
```

The tool creates an uncompressed ZIP (tiles are already compressed), SHA-256 file manifest and atomically published catalog with size, digest, version and bounds. Verify full upstream coverage at useful zooms: bounds alone do not prove there are no holes. The personal catalog is the coverage source of truth. Long trails use the union of regions, including edges between distant GPX vertices.

The default style is self-contained water/buildings/roads/paths without labels or sprites. `--resources /srv/style` accepts a complete style/glyph/sprite tree. References must use `bundle://…`, for example `pmtiles://bundle://map.pmtiles` and `bundle://glyphs/{fontstack}/{range}.pbf`. Each declared font needs all 256 BMP ranges, `0-255.pbf` through `65280-65535.pbf`. Sprites include `.json`, `.png`, `@2x.json`, `@2x.png`. Combined regions need the same fonts/sprites; composition uses the first region's resources.

`bundle.json` contains `files` (relative path → SHA-256) and `fonts` (font directories). Parent paths, symlinks and network sources are forbidden. Mobile downloads to `.part`, verifies size/hash, extracts into `.staging`, verifies all resources and the PMTiles header, then publishes the complete version before marking availability. Interruption preserves the prior version. Interrupted transfers restart; HTTP Range resume is not implemented.

Old unreferenced versions can remain after updates; automatic cleanup is still incomplete. Removing an active map reports affected trails. Allow space for download, extraction and the prior version. Storage errors are shown, but free space is not pre-estimated.

## Demonstration and elevations

The mobile repository's `assets/demo` contains a real Florence archive (about 6.6 MB) from official PMTiles/Protomaps samples with OSM ODbL attribution. Its GPX and elevations are synthetic UI fixtures, not a hiking route or prepared Camino map.

Imported elevation profiles preserve segments and gaps. Only missing heights are requested from the configured server, then stored and marked estimated. DEM gaps stay null. Recorded-walk ascent/descent is derived from filtered GPS measurements; advanced terrain contours are not guaranteed by this package contract.
