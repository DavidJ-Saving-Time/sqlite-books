#!/usr/bin/env python3
"""
Push metadata from our library to OpenLibrary.
Called by do_transfer.php via subprocess.
"""

import argparse
import json
import sys

from olclient.openlibrary import OpenLibrary
from olclient.config import Credentials


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--olid',            required=True)
    p.add_argument('--session-cookie',  default=None, help='OL browser session cookie value')
    p.add_argument('--access',          default=None, help='OL S3 access key')
    p.add_argument('--secret',          default=None, help='OL S3 secret key')
    p.add_argument('--description',     default=None)
    p.add_argument('--subjects',        default=None,
                   help='Pipe-separated list of subjects')
    p.add_argument('--series-key',      default=None,
                   help='OL series key e.g. OL326110L')
    p.add_argument('--series-position', default=None,
                   help='Position in the series e.g. 1 or 1.5')
    p.add_argument('--cover',           default=None,
                   help='Absolute path to cover image file')
    p.add_argument('--dry-run',         action='store_true',
                   help='Print what would be sent without saving')
    args = p.parse_args()

    if args.session_cookie:
        # Use browser session cookie directly — bypasses ol-client login
        ol = OpenLibrary()  # no credentials, anonymous session
        ol.session.cookies.set('session', args.session_cookie, domain='openlibrary.org')
    elif args.access and args.secret:
        try:
            ol = OpenLibrary(credentials=Credentials(args.access, args.secret))
        except Exception as e:
            print(json.dumps({'ok': False, 'error': f'Login failed: {e}'}))
            sys.exit(1)
        if not ol.session.cookies:
            print(json.dumps({'ok': False, 'error': 'Login succeeded but no session cookie was set'}))
            sys.exit(1)
    else:
        print(json.dumps({'ok': False, 'error': 'No credentials provided — set OL_SESSION_COOKIE or OL_ACCESS+OL_SECRET in config.php'}))
        sys.exit(1)

    try:
        work = ol.Work.get(args.olid)
    except Exception as e:
        print(json.dumps({'ok': False, 'error': f'Could not fetch work {args.olid}: {e}'}))
        sys.exit(1)

    changes = []

    if args.description is not None:
        work.description = args.description
        changes.append('description')

    if args.subjects is not None:
        subjects = [s.strip() for s in args.subjects.split('|') if s.strip()]
        work.subjects = subjects
        changes.append('subjects')

    if args.series_key is not None:
        series_entry = {'series': {'key': f'/series/{args.series_key}'}}
        if args.series_position:
            series_entry['position'] = args.series_position
        work.series = [series_entry]
        changes.append('series')

    if not changes and args.cover is None:
        print(json.dumps({'ok': False, 'error': 'Nothing to transfer'}))
        sys.exit(1)

    if args.dry_run:
        print(json.dumps({'ok': True, 'dry_run': True, 'payload': work.json(), 'changes': changes}))
        return

    if changes:
        try:
            resp = work.save(comment=f'Updated {", ".join(changes)} via calibre-nilla')
        except Exception as e:
            print(json.dumps({'ok': False, 'error': f'Save failed: {e}'}))
            sys.exit(1)

        if resp.status_code not in (200, 201):
            print(json.dumps({
                'ok':     False,
                'error':  f'OL returned HTTP {resp.status_code}',
                'detail': resp.text[:2000],
            }))
            sys.exit(1)

    cover_result = None
    if args.cover:
        try:
            # ol-client's add_bookcover takes a URL; for a local file we post it directly
            url = f'{ol.base_url}/works/{args.olid}/-/add-cover'
            with open(args.cover, 'rb') as fh:
                resp = ol.session.post(url, files={
                    'file': (args.cover, fh, 'image/jpeg'),
                    'upload': (None, 'submit'),
                })
            if resp.status_code in (200, 201):
                cover_result = 'uploaded'
            else:
                cover_result = f'HTTP {resp.status_code}: {resp.text[:500]}'
        except Exception as e:
            cover_result = f'failed: {e}'

    print(json.dumps({
        'ok':           True,
        'changes':      changes,
        'cover_result': cover_result,
    }))


if __name__ == '__main__':
    main()
