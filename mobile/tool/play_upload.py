#!/usr/bin/env python3
"""Uploads an Android App Bundle to Google Play through the Play Developer API.

Needs only the standard library and `cryptography`. The service account's JSON
key stays outside every repository (default ~/.nirmaan360/play-service-account.json)
and is never printed, nor is the access token.

  python3 tool/play_upload.py check
      Read only: opens an edit, prints what the production track holds, and
      throws the edit away. Proves the key and the Play Console permissions.

  python3 tool/play_upload.py upload --aab build.aab --notes "..." [--commit]
      Uploads the bundle and sets it as a full production release. Without
      --commit the edit is validated and thrown away, so nothing changes on
      Play. With --commit it is sent for review, and goes live on approval
      when managed publishing is off.
"""

import argparse
import base64
import json
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding

PACKAGE = 'in.nirmaan360.app'
DEFAULT_KEY = os.path.expanduser('~/.nirmaan360/play-service-account.json')
SCOPE = 'https://www.googleapis.com/auth/androidpublisher'
API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications'
UPLOAD = 'https://androidpublisher.googleapis.com/upload/androidpublisher/v3/applications'


def _b64(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b'=').decode()


def access_token(key_path: str) -> str:
    with open(key_path, encoding='utf-8') as handle:
        key = json.load(handle)
    now = int(time.time())
    header = _b64(json.dumps({'alg': 'RS256', 'typ': 'JWT'}).encode())
    claims = _b64(json.dumps({
        'iss': key['client_email'],
        'scope': SCOPE,
        'aud': key['token_uri'],
        'iat': now,
        'exp': now + 3600,
    }).encode())
    signer = serialization.load_pem_private_key(key['private_key'].encode(), password=None)
    signature = signer.sign(f'{header}.{claims}'.encode(), padding.PKCS1v15(), hashes.SHA256())
    assertion = f'{header}.{claims}.{_b64(signature)}'
    body = urllib.parse.urlencode({
        'grant_type': 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion': assertion,
    }).encode()
    reply = _send(urllib.request.Request(key['token_uri'], data=body, method='POST'), timeout=60)
    return reply['access_token']


def _send(request: urllib.request.Request, timeout: int = 120) -> dict:
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read()
    except urllib.error.HTTPError as error:
        detail = error.read().decode(errors='replace')[:2000]
        sys.exit(f'Play API {error.code} on {request.get_method()} {request.full_url.split("?")[0]}: {detail}')
    return json.loads(raw) if raw else {}


class Play:
    def __init__(self, token: str, package: str):
        self.token = token
        self.package = package

    def _call(self, method: str, path: str, payload=None, timeout: int = 120) -> dict:
        data = None if payload is None else json.dumps(payload).encode()
        request = urllib.request.Request(f'{API}/{self.package}{path}', data=data, method=method)
        request.add_header('Authorization', f'Bearer {self.token}')
        if data is not None:
            request.add_header('Content-Type', 'application/json')
        return _send(request, timeout)

    def open_edit(self) -> str:
        return self._call('POST', '/edits', {})['id']

    def drop_edit(self, edit: str) -> None:
        self._call('DELETE', f'/edits/{edit}')

    def track(self, edit: str, name: str) -> dict:
        return self._call('GET', f'/edits/{edit}/tracks/{name}')

    def upload_bundle(self, edit: str, path: str) -> dict:
        with open(path, 'rb') as handle:
            data = handle.read()
        request = urllib.request.Request(
            f'{UPLOAD}/{self.package}/edits/{edit}/bundles?uploadType=media',
            data=data,
            method='POST',
        )
        request.add_header('Authorization', f'Bearer {self.token}')
        request.add_header('Content-Type', 'application/octet-stream')
        return _send(request, timeout=1800)

    def set_release(self, edit: str, name: str, version_code: int, release: str, notes: str, language: str) -> dict:
        return self._call('PUT', f'/edits/{edit}/tracks/{name}', {
            'track': name,
            'releases': [{
                'name': release,
                'versionCodes': [str(version_code)],
                'status': 'completed',
                'releaseNotes': [{'language': language, 'text': notes}],
            }],
        })

    def validate(self, edit: str) -> dict:
        return self._call('POST', f'/edits/{edit}:validate')

    def commit(self, edit: str) -> dict:
        return self._call('POST', f'/edits/{edit}:commit', timeout=600)


def describe(track: dict) -> str:
    lines = []
    for release in track.get('releases', []):
        codes = ', '.join(release.get('versionCodes', []))
        lines.append(f"  {release.get('name', '?')}: versions {codes or '-'}, {release.get('status', '?')}")
    return '\n'.join(lines) or '  (no releases)'


def version_name() -> str:
    """The app's version from pubspec.yaml ("1.0.2" of "version: 1.0.2+20"), for the release's label in Play Console."""
    pubspec = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'pubspec.yaml')
    with open(pubspec, encoding='utf-8') as lines:
        for line in lines:
            if line.startswith('version:'):
                return line.split(':', 1)[1].strip().split('+', 1)[0]
    sys.exit('No version line in pubspec.yaml')


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('action', choices=['check', 'upload'])
    parser.add_argument('--key', default=os.environ.get('PLAY_SERVICE_ACCOUNT', DEFAULT_KEY))
    parser.add_argument('--package', default=PACKAGE)
    parser.add_argument('--track', default='production')
    parser.add_argument('--aab')
    parser.add_argument('--notes', help='Release notes, at most 500 characters')
    parser.add_argument('--language', default='en-IN')
    parser.add_argument('--commit', action='store_true', help='Send the release for review; without it nothing changes')
    args = parser.parse_args()

    if not os.path.isfile(args.key):
        sys.exit(f'No service account key at {args.key}')
    play = Play(access_token(args.key), args.package)
    edit = play.open_edit()

    if args.action == 'check':
        print(f'{args.track} track now:\n{describe(play.track(edit, args.track))}')
        play.drop_edit(edit)
        print('Key and permissions work. Nothing was changed.')
        return

    if not args.aab or not args.notes:
        play.drop_edit(edit)
        sys.exit('upload needs --aab and --notes')
    if len(args.notes) > 500:
        play.drop_edit(edit)
        sys.exit('Release notes are limited to 500 characters')

    bundle = play.upload_bundle(edit, args.aab)
    code = int(bundle['versionCode'])
    print(f'Uploaded bundle: version code {code}')
    play.set_release(edit, args.track, code, f'{version_name()} ({code})', args.notes, args.language)
    play.validate(edit)
    print(f'Edit is valid: {args.track} would get version {code} at 100%.')
    if not args.commit:
        play.drop_edit(edit)
        print('Dry run: the edit was thrown away. Nothing changed on Play.')
        return
    play.commit(edit)
    print(f'Committed: version {code} is sent for review on the {args.track} track.')


if __name__ == '__main__':
    main()
