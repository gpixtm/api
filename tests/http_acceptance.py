"""Local HTTP acceptance checks; creates isolated test accounts and cleans only them.

Run from backend/: python tests/http_acceptance.py. Requires the local Docker
stack after migrations. No real email is sent and no fixture account is changed.
"""
import json
import subprocess
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

BASE = 'http://127.0.0.1:8080'
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
created = []


def call(path, body=None, access=None, expected=200):
    headers = {'Content-Type': 'application/json'}
    if access:
        headers['Authorization'] = 'Bearer ' + access
    request = urllib.request.Request(BASE + path,
        data=None if body is None else json.dumps(body).encode(), headers=headers)
    try:
        response = opener.open(request, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        status = response.status
        data = json.loads(response.read())
    allowed = expected if isinstance(expected, tuple) else (expected,)
    assert status in allowed, f'{path}: expected {allowed}, got {status}'
    return data


def check(label):
    print('PASS', label)


def reset_code(email, previous=None):
    url = 'http://127.0.0.1:8025/view/latest.txt?' + urllib.parse.urlencode({'query': 'to:' + email})
    deadline = time.monotonic() + 30
    while time.monotonic() < deadline:
        try:
            with opener.open(url, timeout=3) as response:
                match = re.search(r'\b[a-f0-9]{64}\b', response.read().decode())
                if match and match.group() != previous:
                    return match.group()
        except urllib.error.HTTPError as error:
            if error.code != 404:
                raise
        time.sleep(1)
    raise RuntimeError('No matching reset email captured in local Mailpit')


try:
    password = 'Qa-' + uuid.uuid4().hex
    accounts = []
    for _ in range(2):
        username = 'qa_' + uuid.uuid4().hex[:12]
        session = call('/api/auth/register',
            {'username': username, 'email': username + '@example.invalid', 'password': password})
        uuid.UUID(session['user']['id'])
        created.append(session['user']['id'])
        accounts.append(session)
    a, b = accounts
    check('HTTP registration of two isolated accounts')

    for identifier in (a['user']['email'], a['user']['username']):
        a = call('/api/auth/login', {'identifier': identifier, 'password': password})
        assert call('/api/auth/me', access=a['access_token'])['user']['id'] == created[0]
    call('/api/auth/login', {'identifier': a['user']['email'], 'password': 'incorrect'}, expected=401)
    call('/api/auth/me', access='invalid-test-token', expected=401)
    call('/api/trails', expected=401)
    call('/api/regions', expected=401)
    check('email/username login, me, wrong password and protected endpoints')

    old = a
    a = call('/api/auth/refresh', {'refresh_token': old['refresh_token']})
    assert a['refresh_token'] == old['refresh_token']
    call('/api/auth/me', access=old['access_token'], expected=401)
    # Simulate losing a successful refresh response: retry the retained credential.
    a = call('/api/auth/refresh', {'refresh_token': old['refresh_token']})
    assert call('/api/auth/me', access=a['access_token'])['user']['id'] == created[0]
    check('access rotation and retry after a lost refresh response')

    trail_id, operation = str(uuid.uuid4()), str(uuid.uuid4())
    mutation = {'id': trail_id, 'mutation': operation, 'baseRevision': 0, 'deleted': False,
        'payload': {'id': trail_id, 'name': 'HTTP acceptance isolated',
            'segments': [[[43.0, -1.0, 100], [43.01, -1.01, None]]], 'pois': []}}
    first = call('/api/sync', mutation, a['access_token'])
    assert call('/api/sync', mutation, a['access_token']) == first
    call('/api/sync', mutation, b['access_token'], expected=(403, 404, 409, 422))
    assert any(t['id'] == trail_id for t in call('/api/trails', access=a['access_token']))
    assert not any(t['id'] == trail_id for t in call('/api/trails', access=b['access_token']))
    other_id = str(uuid.uuid4())
    mutation['id'] = other_id
    mutation['payload']['id'] = other_id
    assert call('/api/sync', mutation, b['access_token'])['conflict'] is False
    check('owner isolation for reads, UUID writes and idempotency receipts')

    call('/api/auth/change-password', {'current_password': 'incorrect', 'password': password + 'x'}, a['access_token'], expected=422)
    call('/api/auth/change-password', {'current_password': password, 'password': password + 'x'}, a['access_token'])
    call('/api/auth/me', access=a['access_token'], expected=401)
    call('/api/auth/login', {'identifier': a['user']['username'], 'password': password}, expected=401)
    a = call('/api/auth/login', {'identifier': a['user']['username'], 'password': password + 'x'})
    call('/api/auth/logout', {'refresh_token': a['refresh_token']}, a['access_token'])
    call('/api/auth/me', access=a['access_token'], expected=401)
    call('/api/auth/refresh', {'refresh_token': a['refresh_token']}, expected=401)
    check('password change and logout invalidate sessions')

    if '--mailpit' in sys.argv:
        # Refuse email tests unless BOTH processes use the internal capture service.
        for service in ('api', 'mail-worker'):
            subprocess.run(['docker', 'compose', 'exec', '-T', service, 'php', '-r',
                "exit(getenv('MAILER_DSN') === 'smtp://mailpit:1025' ? 0 : 1);"], check=True)
        before_reset = call('/api/auth/login', {'identifier': a['user']['email'], 'password': password + 'x'})
        known = call('/api/auth/forgot-password', {'email': a['user']['email']})
        unknown = call('/api/auth/forgot-password', {'email': 'absent-' + uuid.uuid4().hex + '@example.invalid'})
        assert known == unknown
        code = reset_code(a['user']['email'])
        call('/api/auth/reset-password', {'token': code, 'password': password + 'reset'})
        call('/api/auth/reset-password', {'token': code, 'password': password + 'reuse'}, expected=422)
        call('/api/auth/me', access=before_reset['access_token'], expected=401)
        call('/api/auth/refresh', {'refresh_token': before_reset['refresh_token']}, expected=401)
        call('/api/auth/login', {'identifier': a['user']['email'], 'password': password + 'x'}, expected=401)
        a = call('/api/auth/login', {'identifier': a['user']['email'], 'password': password + 'reset'})
        call('/api/auth/forgot-password', {'email': a['user']['email']})
        expired = reset_code(a['user']['email'], previous=code)
        owner = str(uuid.UUID(a['user']['id']))
        subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'psql', '-U', 'gpix', '-d', 'gpix', '-v', 'ON_ERROR_STOP=1'],
            input=f"UPDATE password_resets SET expires_at=now()-interval '1 second' WHERE user_id='{owner}';", text=True,
            stdout=subprocess.DEVNULL, check=True)
        call('/api/auth/reset-password', {'token': expired, 'password': password + 'expired'}, expected=422)
        check('local SMTP capture, neutral reset reply, new password, single-use and expiry over HTTP')
    # A random nonexistent identifier exercises the persisted per-identifier limit.
    limited = 'qa_missing_' + uuid.uuid4().hex
    for _ in range(10):
        call('/api/auth/login', {'identifier': limited, 'password': 'incorrect'}, expected=401)
    call('/api/auth/login', {'identifier': limited, 'password': 'incorrect'}, expected=429)
    check('persisted login rate limit returns 429 after repeated attempts')
finally:
    # UUIDs above are server-returned and validated; only test-owned records removed.
    if created:
        ids = ','.join("'" + str(uuid.UUID(value)) + "'" for value in created)
        sql = f'BEGIN; DELETE FROM receipts WHERE owner_id IN ({ids}); DELETE FROM trails WHERE owner_id IN ({ids}); DELETE FROM users WHERE id IN ({ids}); COMMIT;'
        subprocess.run(['docker', 'compose', 'exec', '-T', 'db', 'psql', '-U', 'gpix', '-d', 'gpix', '-v', 'ON_ERROR_STOP=1'],
            input=sql, text=True, stdout=subprocess.DEVNULL, check=True)
        print('PASS temporary accounts cleaned; personal data untouched')
