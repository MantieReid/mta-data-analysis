#!/usr/bin/env python3
"""Build small per-station timetable files from MTA and NYC Ferry schedule (GTFS) feeds.

Runs on the web server once a night, at low priority. It downloads a feed only when the feed changed, reads it
one row at a time (low memory) and writes one small JSON file per station:

    <out>/<feed>/<station>.json  {"u": updated, "s": {date: [service, ...]}, "d": [[minute, service, to, train, arrives, route], ...]}
    <out>/<feed>/index.json      stations and the dates covered

"minute" is minutes after midnight of the service day (can pass 1440 for trains after midnight).
Standard library only. Usage: python3 timetables.py <out dir> [feed ...]
"""
import csv
import io
import json
import os
import re
import sys
import urllib.request
import zipfile
from datetime import date, datetime, timedelta

FEEDS = {
    'lirr': {'url': 'https://rrgtfsfeeds.s3.amazonaws.com/gtfslirr.zip', 'key': 'stop_code'},
    'mnr': {'url': 'https://rrgtfsfeeds.s3.amazonaws.com/gtfsmnr.zip', 'key': 'stop_code'},
    'ferry': {'url': 'http://nycferry.connexionz.net/rtt/public/resource/gtfs.zip', 'key': 'stop_name'},  # one landing can have a stop per route
    'sir': {'url': 'https://rrgtfsfeeds.s3.amazonaws.com/gtfs_subway.zip', 'key': 'stop_id', 'routes': {'SI'}},
}
DAYS = 21  # how many days ahead to keep


def rows(z, name):
    with z.open(name) as f:
        yield from csv.DictReader(io.TextIOWrapper(f, encoding='utf-8-sig', newline=''))


def mins(t):
    h, m, *_ = t.split(':')
    return int(h) * 60 + int(m)


def fetch(url, cache):
    """The feed's bytes, or None when it has not changed since the last run."""
    meta = cache + '.meta'
    req = urllib.request.Request(url, headers={'User-Agent': 'mta-ridership-timetables'})
    old = open(meta).read().strip() if os.path.exists(meta) and os.path.exists(cache) else ''
    with urllib.request.urlopen(req, timeout=120) as r:
        stamp = r.headers.get('Last-Modified', '') + '|' + r.headers.get('Content-Length', '')
        if old and stamp == old:
            return None
        data = r.read()
    with open(cache, 'wb') as f:
        f.write(data)
    with open(meta, 'w') as f:
        f.write(stamp)
    return data


def build(name, cfg, out, force=False):
    os.makedirs(os.path.join(out, name), exist_ok=True)
    data = fetch(cfg['url'], os.path.join(out, name + '.zip'))
    idx = os.path.join(out, name, 'index.json')
    fresh = os.path.exists(idx) and (datetime.now().timestamp() - os.path.getmtime(idx)) < 6 * 86400
    if data is None and fresh and not force:  # same feed and the dates still reach 2+ weeks ahead
        print(name, 'unchanged')
        return
    if data is None:
        data = open(os.path.join(out, name + '.zip'), 'rb').read()
    z = zipfile.ZipFile(io.BytesIO(data))
    names = set(z.namelist())
    today = date.today()
    window = [(today + timedelta(days=i)).strftime('%Y%m%d') for i in range(DAYS)]

    # Which services run on which dates (weekly calendar plus exceptions).
    on = {d: set() for d in window}
    if 'calendar.txt' in names:
        wd = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
        for r in rows(z, 'calendar.txt'):
            for d in window:
                dt = datetime.strptime(d, '%Y%m%d').date()
                if r['start_date'] <= d <= r['end_date'] and r[wd[dt.weekday()]] == '1':
                    on[d].add(r['service_id'])
    if 'calendar_dates.txt' in names:
        for r in rows(z, 'calendar_dates.txt'):
            if r['date'] in on:
                (on[r['date']].add if r['exception_type'] == '1' else on[r['date']].discard)(r['service_id'])
    used = set().union(*on.values())

    routes = {r['route_id']: (r.get('route_short_name') or r.get('route_long_name') or '') for r in rows(z, 'routes.txt')}
    keep = cfg.get('routes')
    trips = {}
    for r in rows(z, 'trips.txt'):
        if r['service_id'] in used and (not keep or r['route_id'] in keep):
            trips[r['trip_id']] = [r['service_id'], r.get('trip_headsign', ''), r.get('trip_short_name', ''), routes.get(r['route_id'], ''), None]

    stops = {}
    for r in rows(z, 'stops.txt'):
        if r.get('location_type', '0') in ('', '0'):
            # Platforms of one station (subway feed: "S31N", "S31S") share their parent station's file.
            key = r.get('parent_station') or r.get(cfg['key']) or r['stop_id']
            stops[r['stop_id']] = (re.sub(r'[^A-Za-z0-9]+', '-', key).strip('-').lower() if cfg['key'] == 'stop_name' else key, r['stop_name'])

    # One pass over stop times: departures per station, and each trip's last stop and arrival.
    deps = {}
    last = {}
    for r in rows(z, 'stop_times.txt'):
        t = trips.get(r['trip_id'])
        if not t or r['stop_id'] not in stops:
            continue
        seq = int(r['stop_sequence'])
        if r['trip_id'] not in last or seq > last[r['trip_id']][0]:
            last[r['trip_id']] = (seq, mins(r['arrival_time']), r['stop_id'])
        if r.get('pickup_type', '0') not in ('1',):
            deps.setdefault(stops[r['stop_id']][0], []).append((mins(r['departure_time']), r['trip_id'], seq))

    updated = datetime.now().strftime('%Y-%m-%d %H:%M')
    index = {}
    for code, lst in deps.items():
        svc_ids = {}
        rows_out = []
        for m, tid, seq in sorted(lst):
            sv, head, short, route, _ = trips[tid]
            ls = last[tid]
            if ls[0] == seq:  # the train ends here: not a departure
                continue
            if sv not in svc_ids:
                svc_ids[sv] = len(svc_ids)
            rows_out.append([m, svc_ids[sv], head or stops[ls[2]][1], short, ls[1], route])
        if not rows_out:
            continue
        s = {d: sorted(svc_ids[x] for x in on[d] if x in svc_ids) for d in window}
        name_ = next((n for c, n in stops.values() if c == code), code)
        with open(os.path.join(out, name, code.replace('/', '_') + '.json'), 'w') as f:
            json.dump({'u': updated, 'n': name_, 's': s, 'd': rows_out}, f, separators=(',', ':'))
        index[code] = name_
    with open(os.path.join(out, name, 'index.json'), 'w') as f:
        json.dump({'u': updated, 'days': window, 'stations': index}, f, separators=(',', ':'))
    print(name, len(index), 'stations', len(trips), 'trips')


if __name__ == '__main__':
    out = sys.argv[1]
    force = '--force' in sys.argv
    pick = [a for a in sys.argv[2:] if not a.startswith('--')] or list(FEEDS)
    for n in pick:
        try:
            build(n, FEEDS[n], out, force)
        except Exception as e:  # one bad feed must not stop the others
            print(n, 'failed:', e, file=sys.stderr)
