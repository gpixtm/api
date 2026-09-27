"""Exercise the real installer in temporary fixtures with Docker fully stubbed."""
import os
import pathlib
import shutil
import subprocess
import tempfile

root = pathlib.Path(__file__).resolve().parent.parent
shell = shutil.which('sh') or r'C:\Program Files\Git\bin\sh.exe'
cases = [
    ('valid internal secrets; no personal API key', 32, 32, True),
    ('short database secret', 31, 32, False),
    ('short app secret', 32, 31, False),
]
for label, db_length, app_length, valid in cases:
    with tempfile.TemporaryDirectory(prefix='gpix-install-test-') as tmp:
        fixture = pathlib.Path(tmp)
        (fixture / 'tools').mkdir()
        shutil.copyfile(root / 'tools/install.sh', fixture / 'tools/install.sh')
        shutil.copyfile(root / 'Makefile', fixture / 'Makefile')
        stub = fixture / 'docker'
        stub.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> docker-calls.log\nexit 0\n', encoding='utf-8')
        stub.chmod(0o755)
        contents = 'DB_PASSWORD=' + 'd' * db_length + '\nAPP_SECRET=' + 's' * app_length + '\n'
        env_file = fixture / '.env'
        env_file.write_text(contents, encoding='utf-8')
        before = env_file.read_bytes()
        env = dict(os.environ, PATH=str(fixture) + os.pathsep + os.environ['PATH'])
        command = ['make', 'install', f'SH={shell}'] if shutil.which('make') else [shell, 'tools/install.sh']
        result = subprocess.run(command, cwd=fixture, env=env, capture_output=True)
        assert (result.returncode == 0) == valid, (label, result.stderr.decode())
        assert env_file.read_bytes() == before, label
        calls = (fixture / 'docker-calls.log').read_text().splitlines()
        if not valid:
            assert calls == ['compose version', 'info'], (label, calls)
        print('PASS', label)
