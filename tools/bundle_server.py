"""Create a transfer archive with an explicit allowlist. Excludes all live data/secrets."""
import pathlib
import tarfile
import hashlib

root = pathlib.Path(__file__).resolve().parent.parent
output = root / 'dist'
output.mkdir(exist_ok=True)
files = [root / p for p in ['README.md', 'INSTALL.md', '.env.example', 'compose.yaml', 'compose.https.yaml', 'compose.dev.yaml', 'compose.mailpit.yaml', 'Makefile']]
files += [root / 'docs' / p for p in ['OFFLINE.md', 'VALIDATION.md', 'AUTH.md']]
files += [root / 'tools' / p for p in ['install.sh', 'backup.sh', 'restore.sh', 'package_region.py', 'dev_backend.dart', 'test_install.py', 'bundle_server.py']]
files += [root / p for p in ['composer.json', 'composer.lock', 'Dockerfile', '.dockerignore']]
for directory in ['src', 'bin', 'public', 'migrations', 'docker', 'tests']:
    files += [p for p in (root / directory).rglob('*') if p.is_file()]
for p in files:
    if p.suffix == '.sh' and b'\r\n' in p.read_bytes():
        raise ValueError(f'CRLF shell script: {p}')
target = output / 'gpix-server.tar.gz'
with tarfile.open(target, 'w:gz') as archive:
    for p in sorted(files):
        archive.add(p, arcname='gpix-server/' + p.relative_to(root).as_posix(), recursive=False)
with tarfile.open(target, 'r:gz') as archive:
    names = archive.getnames()
    forbidden = {'.env', '.tooling', 'vendor', 'var', 'backups', 'data', '.git'}
    assert all(not forbidden.intersection(pathlib.PurePosixPath(n).parts) for n in names)
    assert len(names) == len(files)
    (output / 'server-archive-contents.txt').write_text('\n'.join(names) + '\n', encoding='utf-8')
with target.open('rb') as stream:
    digest = hashlib.sha256(stream.read()).hexdigest()
(output / 'gpix-server.tar.gz.sha256').write_text(f'{digest}  gpix-server.tar.gz\n', encoding='ascii')
print(f'{len(files)} allowed files; no live secrets/data; {target.stat().st_size} bytes; SHA256 {digest}')
