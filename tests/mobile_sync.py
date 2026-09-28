"""Run Flutter SQLite sync against local HTTP with one disposable account.

From backend/: py tests/mobile_sync.py
Requires the full checkout (mobile/), the Puro environment gpix and the already-running
migrated local stack. Does not change listeners,
fixtures, real credentials or production URL validation. No mail is sent.
"""
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import urllib.request
import uuid

backend = Path(__file__).resolve().parents[1]
root = backend.parent
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
username = 'sync_' + uuid.uuid4().hex[:12]
password = secrets.token_urlsafe(24)
created = None
result = 1
try:
    request = urllib.request.Request('http://127.0.0.1:8080/api/auth/register',
        data=json.dumps({'username': username, 'email': username + '@example.invalid',
                         'password': password}).encode(),
        headers={'Content-Type': 'application/json'})
    with opener.open(request, timeout=30) as response:
        assert response.status == 200, 'Temporary account registration failed'
        session = json.load(response)
    created = str(uuid.UUID(session['user']['id']))
    assert session['user']['username'] == username
    print('PASS temporary sync account created (no fixture changed)', flush=True)
    environment = os.environ.copy()
    environment.update(GPIX_TEST_IDENTIFIER=username, GPIX_TEST_PASSWORD=password,
                       GPIX_TEST_URL='http://10.0.2.2:8080', GPIX_TEST_LOOPBACK='1')
    result = subprocess.run(['puro', '-e', 'gpix', 'flutter', 'test', 'test/api_integration_test.dart'],
                            cwd=root / 'mobile', env=environment).returncode
finally:
    if created:
        # The only target is the validated server UUID returned for this new user.
        sql = (f"BEGIN; DELETE FROM receipts WHERE owner_id='{created}'; "
               f"DELETE FROM trails WHERE owner_id='{created}'; "
               f"DELETE FROM public_trails WHERE author_id='{created}'; "
               f"DELETE FROM users WHERE id='{created}' AND username='{username}'; COMMIT;")
        subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'psql', '-U', 'gpix',
                        '-d', 'gpix', '-v', 'ON_ERROR_STOP=1'], cwd=backend,
                       input=sql, text=True, stdout=subprocess.DEVNULL, check=True)
        print('PASS temporary sync account, trails and receipts removed; personal data untouched', flush=True)
sys.exit(result)
