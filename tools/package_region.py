"""Build an atomic, verifiable map distribution package from a local PMTiles v3 archive.
Python 3.11+, standard library only. Never downloads map tiles.
"""
import argparse
import hashlib
import json
import pathlib
import shutil
import struct
import tempfile
import zipfile


def digest(path):
    with open(path, 'rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def style():
    return {'version': 8, 'sources': {'basemap': {'type': 'vector', 'url': 'pmtiles://bundle://map.pmtiles',
        'attribution': '© OpenStreetMap contributors · Protomaps · openstreetmap.org/copyright'}}, 'layers': [
        {'id': 'background', 'type': 'background', 'paint': {'background-color': '#edf0e4'}},
        {'id': 'landuse', 'type': 'fill', 'source': 'basemap', 'source-layer': 'landuse', 'paint': {'fill-color': '#dce7cd', 'fill-opacity': 0.6}},
        {'id': 'water', 'type': 'fill', 'source': 'basemap', 'source-layer': 'water', 'paint': {'fill-color': '#a3ccd1'}},
        {'id': 'buildings', 'type': 'fill', 'source': 'basemap', 'source-layer': 'buildings', 'paint': {'fill-color': '#d5d3c7'}},
        {'id': 'roads-casing', 'type': 'line', 'source': 'basemap', 'source-layer': 'roads', 'paint': {'line-color': '#d0c9b7', 'line-width': ['interpolate', ['linear'], ['zoom'], 8, 1, 15, 5]}},
        {'id': 'roads', 'type': 'line', 'source': 'basemap', 'source-layer': 'roads', 'paint': {'line-color': '#ffffff', 'line-width': ['interpolate', ['linear'], ['zoom'], 8, 0.5, 15, 3]}},
        {'id': 'paths', 'type': 'line', 'source': 'basemap', 'source-layer': 'roads', 'filter': ['in', 'kind', 'path', 'track', 'other'], 'paint': {'line-color': '#967d57', 'line-width': 1.5, 'line-dasharray': [2, 2]}},
    ]}


def build(args):
    if not args.id.replace('-', '').replace('_', '').isalnum():
        raise ValueError('Invalid region id')
    source = pathlib.Path(args.pmtiles)
    with source.open('rb') as f:
        header = f.read(127)
    if len(header) != 127 or header[:8] != b'PMTiles\x03':
        raise ValueError('Expected a PMTiles v3 archive')
    bounds = [v / 1e7 for v in struct.unpack_from('<iiii', header, 102)]
    output = pathlib.Path(args.output)
    output.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory() as temporary:
        folder = pathlib.Path(temporary)
        shutil.copyfile(source, folder / 'map.pmtiles')
        if args.resources:
            shutil.copytree(args.resources, folder, dirs_exist_ok=True)
        if not (folder / 'style.json').exists():
            (folder / 'style.json').write_text(json.dumps(style()), encoding='utf-8')
        files = {str(p.relative_to(folder)).replace('\\', '/'): digest(p) for p in folder.rglob('*') if p.is_file() and p.name != 'bundle.json'}
        fonts = [p.name for p in (folder / 'glyphs').iterdir() if p.is_dir()] if (folder / 'glyphs').exists() else []
        (folder / 'bundle.json').write_text(json.dumps({'files': files, 'fonts': fonts}), encoding='utf-8')
        name = f'{args.id}-{digest(source)[:16]}.zip'
        package = output / name
        with zipfile.ZipFile(str(package) + '.part', 'w', compression=zipfile.ZIP_STORED, allowZip64=True) as archive:
            for p in folder.rglob('*'):
                if p.is_file():
                    archive.write(p, p.relative_to(folder))
        pathlib.Path(str(package) + '.part').replace(package)
        catalog_file = output / 'catalog.json'
        catalog = json.loads(catalog_file.read_text()) if catalog_file.exists() else []
        catalog = [r for r in catalog if r['id'] != args.id]
        catalog.append({'id': args.id, 'name': args.name, 'version': args.version, 'bounds': bounds,
            'bytes': package.stat().st_size, 'sha256': digest(package), 'url': f'{args.base_url.rstrip("/")}/api/packages/{name}'})
        staged = output / 'catalog.json.part'
        staged.write_text(json.dumps(catalog, indent=2), encoding='utf-8')
        staged.replace(catalog_file)
        if args.fixture_output:
            shutil.copytree(folder, args.fixture_output, dirs_exist_ok=True)
    print(f'Packaged {name}. Coverage is the source archive envelope: verify its actual tile completeness before publication.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--pmtiles', required=True)
    parser.add_argument('--id', required=True)
    parser.add_argument('--name', required=True)
    parser.add_argument('--version', required=True)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--output', default='data/maps')
    parser.add_argument('--resources', help='Optional style.json, glyphs and sprites tree using bundle:// paths')
    parser.add_argument('--fixture-output', help='Also copy unpacked files for offline integration testing')
    build(parser.parse_args())
