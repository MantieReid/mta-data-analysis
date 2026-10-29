"""Link-preview images (1200x630) for the subway lines, bus routes and stations pages, in the same
style as assets/congestion-share.jpg: dark background, title, two big numbers and a small bar chart.

Usage: python tools/make_share_images.py data.json
data.json comes from the live site (WP-CLI), e.g. {"otp": 82.9, "lines": [[name, otp], ...],
"bus": [riders, mph, routes], "routes": [[label, riders], ...], "st": [[name, avg], ...], "nst": 424}.
"""
import json
import os
import re
import sys

import matplotlib
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
BG = (11, 18, 32)
TEXT = (230, 237, 246)
MUTED = (163, 173, 189)
ACCENT = (138, 180, 248)
BAR_BG = (40, 50, 68)
FONTS = os.path.join(os.path.dirname(matplotlib.__file__), 'mpl-data', 'fonts', 'ttf')
OUT = os.path.join(os.path.dirname(__file__), '..', 'wordpress-plugin', 'mta-ridership', 'assets')


def font(size, bold=False):
    return ImageFont.truetype(os.path.join(FONTS, 'DejaVuSans-Bold.ttf' if bold else 'DejaVuSans.ttf'), size)


def card(name, title, subtitle, stats, bars_title, bars, bar_fmt, footer, top=None):
    im = Image.new('RGB', (W, H), BG)
    d = ImageDraw.Draw(im)
    d.text((60, 80), title, font=font(54, True), fill=TEXT)
    d.text((60, 160), subtitle, font=font(30), fill=MUTED)
    x = 60
    for big, small in stats:
        d.text((x, 255), big, font=font(68, True), fill=ACCENT)
        d.text((x, 345), small, font=font(24), fill=MUTED)
        x += max(d.textlength(big, font=font(68, True)), d.textlength(small, font=font(24))) + 60
    # Bar chart on the right.
    bx, by, bw = 720, 250, 420
    d.text((bx, by - 50), bars_title, font=font(22, True), fill=TEXT)
    top = top or max(v for _, v in bars)
    for i, (label, v) in enumerate(bars):
        y = by + i * 52
        d.text((bx, y), label, font=font(20), fill=TEXT)
        d.text((bx + bw, y), bar_fmt(v), font=font(20, True), fill=ACCENT, anchor='ra')
        d.rounded_rectangle((bx, y + 28, bx + bw, y + 36), radius=4, fill=BAR_BG)
        d.rounded_rectangle((bx, y + 28, bx + max(8, int(bw * v / top)), y + 36), radius=4, fill=ACCENT)
    d.text((60, 560), footer, font=font(22), fill=MUTED)
    path = os.path.join(OUT, name)
    im.save(path, quality=88)
    print('wrote', os.path.normpath(path))


def main():
    data = json.load(open(sys.argv[1], encoding='utf-8'))
    lines = sorted(data['lines'], key=lambda x: x[1])
    card('lines-share.jpg', 'NYC Subway Lines Ranked', 'Which line is the worst? Delays & on-time rate',
         [(f"{data['otp']}%", 'subway trips on time'), (str(len(lines)), 'lines with report cards')],
         'Least on-time lines', [(n, v) for n, v in lines[:6]], lambda v: f'{v}%',
         'mantiereidii.com/subway-lines  ·  MTA open data, updated monthly', top=100)

    riders, mph, n_routes = data['bus']
    routes = list(data['routes'].values()) if isinstance(data['routes'], dict) else data['routes']
    routes = sorted(routes, key=lambda r: -int(r[1] or 0))[:6]
    card('buses-share.jpg', 'NYC Bus Routes Ranked', 'Busiest, slowest & least reliable buses',
         [(f'{riders / 1e6:.1f}M', 'bus riders a day'), (f'{mph} mph', 'local bus average')],
         'Busiest routes (riders a day)', [(r, int(v)) for r, v in routes], lambda v: f'{v:,}',
         f'mantiereidii.com/bus-routes  ·  {n_routes} routes, MTA open data')

    short = lambda s: re.sub(r'\s*\(.*$', '', s).split('/')[0]
    card('stations-share.jpg', 'Busiest NYC Subway Stations', f"All {data['nst']} stations ranked, with busiest hours",
         [(f"{data['st'][0][1]:,}", 'riders a day at the busiest'), (str(data['nst']), 'stations')],
         'Busiest stations (riders a day)', [(short(n), v) for n, v in data['st'][:6]], lambda v: f'{v:,}',
         'mantiereidii.com/stations  ·  MTA open data, updated daily')


if __name__ == '__main__':
    main()
