"""Local preview of eight new MTA datasets, styled like the live site (nothing is uploaded).

Builds output/preview-new-data/*.html from data.ny.gov, using a live data page (/mta-pay/) as the page shell so the
header, fonts and colours match. Analytics scripts are stripped from the shell and the pages are marked noindex.

Datasets: Subway Mean Distance Between Failures (e2qc-xgxs), Subway Hot Car Work Orders (bx99-sb4y),
20-Year Needs Assessment Railcar Fleet Inventory (hjck-ty8r), Subway General Orders (umx2-k5hm),
LIRR Trip-Level Occupancy (73th-g5ad), Bus Mean Distance Between Failures (7mt2-y7ip), Bus System Fleet Age
(kf6s-w5a7), Bus Fare Evasion (uv5h-dfhp).

Usage: python tools/preview_new_data.py   then serve the repo root (python -m http.server 8771) and open
http://localhost:8771/output/preview-new-data/
"""
import collections
import datetime as dt
import html
import json
import os
import re
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'output', 'preview-new-data')
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
e = html.escape


def api(ds, soql):
    if 'LIMIT' not in soql:
        soql += ' LIMIT 50000'  # the API returns only 1,000 rows otherwise
    url = f'https://data.ny.gov/resource/{ds}.json?' + urllib.parse.urlencode({'$query': soql})
    with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': UA}), timeout=300) as r:
        return json.load(r)


def num(n, d=0):
    return f'{n:,.{d}f}'


def pct(x, d=0):
    return f'{x * 100:.{d}f}%'


def ym(s):
    return s[:7]


def month_name(m):
    return dt.date(int(m[:4]), int(m[5:7]), 1).strftime('%b %Y')


# ---------- the site's pieces (same markup as includes/moredata.php) ----------

def sec(title, body, note=''):
    return f'<section class="mta-section"><h2>{e(title)}</h2>' + (f'<p class="mta-note">{note}</p>' if note else '') + body + '</section>'


def h3(t):
    return f'<h3>{e(t)}</h3>'


def tile(big, small):
    return f'<div class="mta-tile"><strong>{e(big)}</strong><span>{e(small)}</span></div>'


def bars(items):
    """[name, value, number shown, small line]."""
    if not items:
        return ''
    mx = max(1e-9, max(i[1] for i in items))
    o = ''
    for name, val, shown, sub in items:
        o += (f'<li><div class="mta-top-row"><span class="mta-top-name">{e(name)}' + (f'<br><small class="mta-note">{e(sub)}</small>' if sub else '') +
              f'</span><span class="mta-top-num">{e(shown)}</span></div><div class="mta-bar"><span style="width:{round(max(0, val) / mx * 100, 1)}%"></span></div></li>')
    return '<ul class="mta-top" style="list-style:none;padding-left:0">' + o + '</ul>'


def table(head, rows):
    """Row cells are HTML (escape before passing)."""
    o = '<div class="mta-table-wrap"><table class="mta-table"><thead><tr>' + ''.join(f'<th>{e(h)}</th>' for h in head) + '</tr></thead><tbody>'
    for r in rows:
        o += '<tr><td>' + '</td><td>'.join(r) + '</td></tr>'
    return o + '</tbody></table></div>'


def chart(points, label, fmt=num):
    """Column chart like mta_more_month_chart: points = [(hover label, value, axis label or '')]."""
    if len(points) < 2:
        return ''
    vals = [p[1] for p in points]
    mx = max(1e-9, max(vals))
    w = 720 / len(points)
    svg = ''
    low = 0
    if fmt(vals[0]).endswith('%') and min(vals) > 0.5 * mx:
        low = max(0, int(min(vals) - max(0.01, (mx - min(vals)) * 0.5)) if mx > 1 else 0)
    for i, (name, v, axis) in enumerate(points):
        hgt = round(max(2, (v - low) / max(1e-9, mx - low) * 160), 1)
        x = round(i * w + 1, 1)
        svg += (f'<rect x="{x}" y="{round(175 - hgt, 1)}" width="{round(max(1, w - 2), 1)}" height="{hgt}" rx="1" fill="#8ab4f8" '
                f'data-l="{e(name)}" data-v="{e(fmt(v))}"><title>{e(name)}: {e(fmt(v))}</title></rect>')
        if axis:
            svg += f'<text x="{x}" y="208" fill="#a3adbd" font-size="24">{e(axis)}</text>'
    return f'<div class="mta-mchart"><svg viewBox="0 0 720 215" width="100%" role="img" aria-label="{e(label)}">{svg}</svg></div>'


def month_points(series):
    """{YYYY-MM: value} → chart points with the year under each January."""
    pts = []
    items = sorted(series.items())
    step = 2 if len(items) > 100 else 1  # every other year on long charts, so labels don't collide
    for i, (m, v) in enumerate(items):
        pts.append((month_name(m), v, m[:4] if m[5:7] == '01' and int(m[:4]) % step == 0 else (month_name(m)[:3] if i == 0 and m[5:7] < '10' else '')))
    return pts


def source(links, extra=''):
    a = ', '.join(f'<a href="https://data.ny.gov/d/{i}" rel="noopener">{e(n)}</a>' for i, n in links.items())
    t = dt.date.today()
    return f'<p class="mta-source">Data (data.ny.gov): {a}.{" " + extra if extra else ""} Preview built {t:%b} {t.day}, {t.year}.</p>'


def page(slug, title, sub, tiles, body, shell, nav):
    hero = (f'<header class="mta-hero"><h1>{e(title)}</h1><p class="mta-hero-links">{e(sub)} · Preview, not on the live site</p>'
            f'<div class="mta-tiles">{"".join(tiles)}</div></header>')
    banner = ('<p class="mta-note" style="border:1px dashed #8ab4f8;padding:8px 12px;border-radius:8px">'
              'Local preview of new data. Other preview pages: ' + nav + '</p>')
    main = f'<main id="main" class="nyc-main nyc-main-data"><div class="mta-ridership">{banner}{hero}{body}</div></main>'
    h = shell.replace('%%MAIN%%', main).replace('%%TITLE%%', e(title + ' (preview)'))
    with open(os.path.join(OUT, slug + '.html'), 'w', encoding='utf-8') as f:
        f.write(h)


# ---------- page shell from the live site ----------

def get_shell():
    req = urllib.request.Request('https://mantiereidii.com/mta-pay/', headers={'User-Agent': UA, 'Accept': 'text/html', 'Accept-Language': 'en-US'})
    with urllib.request.urlopen(req, timeout=60) as r:
        h = r.read().decode('utf-8')
    i, j = h.index('<main'), h.index('</main>') + len('</main>')
    h = h[:i] + '%%MAIN%%' + h[j:]
    # No analytics or tracking from a preview: drop every script except the theme's menu script.
    h = re.sub(r'<script\b(?![^>]*themes/mantie-nyc/assets/site\.js)[^>]*>.*?</script>', '', h, flags=re.S)
    h = re.sub(r'<title>.*?</title>', '<title>%%TITLE%%</title>', h, flags=re.S)
    h = re.sub(r'<meta[^>]+name=["\']robots["\'][^>]*>', '', h)
    h = h.replace('<head>', '<head><meta name="robots" content="noindex,nofollow">', 1)
    # Tap a bar to see its month and value, as on the live site.
    h = h.replace('</body>', '<script src="/wordpress-plugin/mta-ridership/assets/mta-month-chart.js"></script></body>')
    return h


# ---------- subway cars: breakdowns, hot cars, fleet ----------

CAR_INFO = {  # car class → (friendly name, first-last year delivered), from the fleet inventory (hjck-ty8r)
    'R46': ('R46', '1975–78'), 'R62': ('R62', '1984–85'), 'R62A': ('R62A', '1984–87'), 'R68': ('R68', '1986–88'),
    'R68A': ('R68A', '1988–90'), 'R142': ('R142', '2000–03'), 'R142A': ('R142A', '2000–05'), 'R143': ('R143', '2001–03'),
    'R160': ('R160', '2007–10'), 'R188-CONVERSION': ('R188 (rebuilt R142A)', '2000–05'), 'R188-NEW': ('R188 (new)', '2014–16'),
    'R179': ('R179', '2018–19'), 'R211A': ('R211A', '2023–'), 'R211T': ('R211T (open gangway)', '2023–'),
}


def build_cars(shell, nav):
    md = api('e2qc-xgxs', 'SELECT month, car_class, number_of_cars, _12_month_average_mdbf, mdbf ORDER BY month')
    last = max(ym(r['month']) for r in md)
    latest = {r['car_class']: r for r in md if ym(r['month']) == last}
    # Which lines each model runs on, from the hot car data's service lines over the last year.
    hot = api('bx99-sb4y', "SELECT month, car_number, car_class, service_lines, number_of_cars_in_service, number_of_years_in_service, number_of_work_orders WHERE month >= '2015-01-01' LIMIT 50000")
    hot_last = max(ym(r['month']) for r in hot)
    y0 = f'{int(hot_last[:4]) - 1}-{int(hot_last[5:7]) + 1:02d}' if hot_last[5:7] != '12' else f'{hot_last[:4]}-01'
    lines_by = collections.defaultdict(set)
    for r in hot:
        if ym(r['month']) >= y0:
            for ln in re.split(r'[,\s/]+', r.get('service_lines') or ''):
                if ln:
                    lines_by[r['car_class'].replace('-', '')].add(ln.upper())

    shuttles = {'FS': 'S', 'GCS': 'S', 'RS': 'S', 'H': 'S'}  # the MTA's codes for the three shuttles

    def lines_for(cc):
        base = cc.split('-')[0].rstrip('T') if cc.startswith('R211') else cc.split('-')[0]
        ls = {shuttles.get(x, x) for x in lines_by.get(base, set()) | lines_by.get(cc.replace('-', ''), set())}
        order = '1234567ACEBDFMGJZLNQRWS'
        return ' '.join(sorted(ls, key=lambda x: order.find(x) if x in order else 99))

    rows = sorted(latest.values(), key=lambda r: -float(r['_12_month_average_mdbf']))
    worst = min(rows, key=lambda r: float(r['_12_month_average_mdbf']))
    best = max(rows, key=lambda r: float(r['_12_month_average_mdbf']))
    total_cars = sum(int(r['number_of_cars']) for r in rows)
    items = []
    for r in rows:
        name, yrs = CAR_INFO.get(r['car_class'], (r['car_class'], ''))
        ls = lines_for(r['car_class'])
        items.append([name, float(r['_12_month_average_mdbf']), num(float(r['_12_month_average_mdbf'])) + ' mi',
                      f'Built {yrs} · {num(int(r["number_of_cars"]))} cars' + (f' · runs on {ls}' if ls else '')])
    body = sec('Which subway trains break down the most?', bars(items),
               f'Average miles a car runs between breakdowns that delay service, over the 12 months to {month_name(last)}. Higher is better. '
               'Lines come from the MTA\'s air-conditioning repair records, so a model that had no repairs may show no lines.')
    ratio = float(best['_12_month_average_mdbf']) / float(worst['_12_month_average_mdbf'])

    # System trend: miles-weighted 12-month MDBF across all models, from each month's numbers.
    sysm = collections.defaultdict(lambda: [0.0, 0.0])
    for r in md:
        if r.get('mdbf') and float(r['mdbf']) > 0:
            cars = float(r['number_of_cars'])
            sysm[ym(r['month'])][0] += cars
            sysm[ym(r['month'])][1] += cars / float(r['mdbf'])
    sys_series = {m: v[0] / v[1] for m, v in sysm.items() if v[1] > 0 and m >= '2019-01'}
    # A 12-month rolling average, since single months jump around.
    ks = sorted(sys_series)
    roll = {ks[i]: sum(sys_series[k] for k in ks[i - 11:i + 1]) / 12 for i in range(11, len(ks))}
    body += sec('Are subway breakdowns getting better?', chart(month_points(roll), 'Miles between breakdowns, whole fleet', lambda v: num(v) + ' mi'),
                'All models together, 12-month average, weighted by how many cars each model has. Each bar is one month.')

    # Hot cars.
    by_class = collections.defaultdict(lambda: [0, 0, 0])
    by_line = collections.Counter()
    by_car = collections.Counter()
    car_meta = {}
    years = collections.Counter()
    for r in hot:
        n = int(r['number_of_work_orders'])
        years[r['month'][:4]] += n
        if ym(r['month']) >= y0:
            c = by_class[r['car_class']]
            c[0] += n
            c[1] = max(c[1], int(r.get('number_of_cars_in_service') or 0))
            c[2] = max(c[2], int(r.get('number_of_years_in_service') or 0))
            for ln in set(re.split(r'[,\s/]+', r.get('service_lines') or '')):
                if ln:
                    by_line[shuttles.get(ln.upper(), ln.upper())] += n
            car = re.sub(r'[^0-9]', '', r['car_number'])
            by_car[car] += n
            car_meta[car] = (r['car_class'], r.get('number_of_years_in_service') or '?', ' '.join(shuttles.get(x.upper(), x.upper()) for x in re.split(r'[,\s/]+', r.get('service_lines') or '') if x))
    cars_now = collections.Counter()
    for cc, r in latest.items():
        cars_now[cc.split('-')[0] if cc.startswith('R188') else cc] += int(r['number_of_cars'])
    for cc, c in by_class.items():
        if cars_now.get(cc):
            c[1] = cars_now[cc]
    hot_total = sum(c[0] for c in by_class.values())
    items = [[cc, c[0] / max(1, c[1]) * 100, num(c[0] / max(1, c[1]) * 100, 1), f'{num(c[0])} repairs · {num(c[1])} cars · about {c[2]} years old']
             for cc, c in by_class.items()]
    items.sort(key=lambda i: -i[1])
    body += sec('Which subway cars have broken air conditioning most often?', bars(items),
                f'Air-conditioning repair requests ("hot car" work orders) for every 100 cars of each model (cars in service in {month_name(last)}), {month_name(y0)} – {month_name(hot_last)}. '
                f'{num(hot_total)} repairs in all.')
    yr_pts = [(y + (' (to ' + month_name(hot_last)[:3] + ')' if y == hot_last[:4] and hot_last[5:7] != '12' else ''), v, y if int(y) % 2 == 1 or y == min(years) else '')
              for y, v in sorted(years.items())]
    body += sec('Hot car repairs by year', chart(yr_pts, 'Hot car repairs by year'),
                f'All air-conditioning repair requests each year since {min(years)}. {hot_last[:4]} runs to {month_name(hot_last)}.')
    line_items = [[ln + ' train', n, num(n), ''] for ln, n in by_line.most_common(12)]
    body += sec('Lines with the most hot car repairs', bars(line_items),
                f'{month_name(y0)} – {month_name(hot_last)}. A car that runs on two lines counts for both.')
    rows = [[e(c), e(car_meta[c][0]), e(car_meta[c][2]), e(car_meta[c][1] + ' years'), num(n)] for c, n in by_car.most_common(10)]
    body += sec('The 10 cars with the most AC repairs', table(['Car number', 'Model', 'Lines', 'Age', 'Repairs'], rows),
                'The number painted on the car, inside and out. Same 12 months.')

    # Fleet inventory.
    inv = api('hjck-ty8r', 'SELECT * LIMIT 200')
    rows = []
    for r in sorted(inv, key=lambda r: ({'NYCT': 0, 'LIRR': 1}.get(r['agency'], 2), r.get('acceptance_date_of_first_cars_accepted') or '')):
        first = r.get('acceptance_date_of_first_cars_accepted') or ''
        lastd = r.get('acceptance_date_of_last_cars_accepted') or ''
        life = int(r.get('useful_life') or 0)
        due = (int(lastd) + life) if lastd.isdigit() and life else None
        flag = ' <small class="mta-note">past its planned life</small>' if due and due < dt.date.today().year else ''
        rows.append([e(r['asset']), e({'NYCT': 'Subway / SIR', 'LIRR': 'LIRR', 'MNR': 'Metro-North'}.get(r['agency'], r['agency'])) + '<br><small class="mta-note">' + e(r.get('type') or '') + '</small>',
                     num(int(r['total'])), e(f'{first}–{lastd}' if first != lastd else first), (e(str(due)) if due else '') + flag])
    body += sec('Every MTA train model and how old it is', table(['Model', 'Where', 'Cars', 'Delivered', 'Planned retirement'], rows),
                'From the MTA\'s 2025–2044 needs assessment (a 2023 count, so models being retired show more cars than run today). '
                'Planned retirement = last delivery year plus the planned life the MTA gives the model.')
    body += source({'e2qc-xgxs': 'Subway Mean Distance Between Failures', 'bx99-sb4y': 'Subway Hot Car Work Orders',
                    'hjck-ty8r': '20-Year Needs Assessment Railcar Fleet Inventory'})
    old = [r for r in inv if r['asset'] == 'R46']
    tiles = [tile(num(total_cars), 'subway cars in service'),
             tile(f'{ratio:.0f}x', f'farther between breakdowns: {CAR_INFO.get(best["car_class"], (best["car_class"],))[0]} vs {CAR_INFO.get(worst["car_class"], (worst["car_class"],))[0]}'),
             tile(num(hot_total), 'AC repairs in 12 months'),
             tile('1975', 'oldest subway cars still running (R46)' if old else 'oldest model')]
    page('subway-cars', 'NYC Subway Cars: Breakdowns, Broken AC and Age', 'Every subway train model compared, from MTA open data', tiles, body, shell, nav)
    return {'best': best, 'worst': worst, 'ratio': ratio, 'hot_total': hot_total, 'top_line': by_line.most_common(1)[0]}


# ---------- planned work ----------

def build_work(shell, nav):
    last = api('umx2-k5hm', 'SELECT max(start_time) AS m')[0]['m'][:7]
    end = f'{last}-01'
    y, m = int(last[:4]), int(last[5:7])
    start = f'{y - 1}-{m + 1:02d}-01' if m < 12 else f'{y}-01-01'
    end_ex = f'{y + (m == 12)}-{(m % 12) + 1:02d}-01'
    rows = api('umx2-k5hm', f"SELECT go_purpose, job_category, impacted_trains, impacted_stations, impacted_boroughs, start_time, stop_time "
                            f"WHERE start_time >= '{start}' AND start_time < '{end_ex}' LIMIT 50000")
    monthly = {r['m'][:7]: int(r['n']) for r in api('umx2-k5hm', f"SELECT date_trunc_ym(start_time) AS m, count(*) AS n WHERE start_time < '{end_ex}' GROUP BY m ORDER BY m")}
    by_line = collections.defaultdict(lambda: [0, 0.0])
    by_station = collections.Counter()
    by_boro = collections.Counter()
    by_cat = collections.Counter()
    hours_total = 0.0
    for r in rows:
        try:
            a = dt.datetime.fromisoformat(r['start_time'][:19])
            b = dt.datetime.fromisoformat(r['stop_time'][:19])
            hrs = max(0.0, min((b - a).total_seconds() / 3600, 24 * 14))
        except (KeyError, ValueError):
            hrs = 0.0
        hours_total += hrs
        for ln in {x.strip().upper() for x in (r.get('impacted_trains') or '').split(',') if x.strip()}:
            by_line[ln][0] += 1
            by_line[ln][1] += hrs
        for s in re.findall(r'\s*([^,(]+(?:\([^)]*\))?)', r.get('impacted_stations') or ''):
            if s.strip():
                by_station[s.strip()] += 1
        for b2 in {x.strip() for x in (r.get('impacted_boroughs') or '').split(',') if x.strip()}:
            by_boro[b2] += 1
        cat = (r.get('job_category') or 'Not given')
        key = 'Weekends' if 'Weekend' in cat and 'Week' not in cat.replace('Weekend', '') else \
              'Weeknights' if cat.startswith('Weeknight') else 'Weekdays' if cat.startswith('Weekday') else \
              'Around the clock' if '24x7' in cat else 'Mixed' if cat != 'Not given' else 'Not given'
        by_cat[key] += 1
    n = len(rows)
    with_st = sum(1 for r in rows if r.get('impacted_stations'))
    order = '1234567ACEBDFMGJZLNQRWS'
    line_items = [[f'{ln} train', v[0], num(v[0]), ''] for ln, v in sorted(by_line.items(), key=lambda kv: -kv[1][0]) if ln in order or ln in ('SIR', 'S')]
    body = sec('Which subway lines have the most planned work?', bars(line_items[:15]),
               f'Planned work orders ("general orders") that change a line\'s service, {month_name(start[:7])} – {month_name(last)}. '
               'One job can affect several lines; it counts once for each.')
    body += sec('When the work happens', bars([[k, v, num(v), pct(v / n)] for k, v in by_cat.most_common()]),
                'How each job was scheduled.')
    body += sec('Stations hit by planned work most often', bars([[s, v, num(v), ''] for s, v in by_station.most_common(15)]),
                f'Number of work orders that changed service at the station. Same 12 months. Only {num(with_st)} of the {num(n)} jobs list the stations they affect, so these are the jobs that do.')
    body += sec('Planned work by borough', bars([[b2, v, num(v), pct(v / max(1, with_st)) + ' of jobs that list stations'] for b2, v in by_boro.most_common()]), 'A job that crosses boroughs counts in each.')
    body += sec('Planned work orders by month', chart(month_points(monthly), 'Planned work orders by month'),
                f'Every month since {month_name(min(monthly))}. Each bar is one month.')
    body += source({'umx2-k5hm': 'MTA Subway General Orders'})
    top = line_items[0]
    tiles = [tile(num(n), 'planned work orders in 12 months'), tile(top[0], 'line with the most work orders'),
             tile(pct(by_cat['Weekends'] / n), 'scheduled for weekends'), tile(by_station.most_common(1)[0][0] if by_station else '', 'station hit most often')]
    page('planned-work', 'Subway Planned Work: Which Lines and Stations Get It Most', f'Every planned subway work order, {month_name(start[:7])} – {month_name(last)}',
         tiles, body, shell, nav)
    return {'n': n, 'top': top}


# ---------- LIRR crowding by train ----------

BRANCH = {'HUNT. HICKS.': 'Port Jefferson (Huntington)', 'RONK. (GREENPT.)': 'Ronkonkoma', 'CITY TERMINAL ZONE': 'City Terminal Zone (Brooklyn, Queens)'}


def bname(b):
    return BRANCH.get(b or '', (b or '').title())


def short(s):
    return re.sub(r'\s+Psgr$', '', s or '').replace('NY Penn Station', 'Penn Station')


def build_lirr(shell, nav):
    last = api('73th-g5ad', 'SELECT max(service_date) AS m')[0]['m'][:10]
    d1 = dt.date.fromisoformat(last)
    d0 = (d1.replace(day=1) - dt.timedelta(days=62)).replace(day=1)
    w = f"service_date >= '{d0}' AND service_date <= '{d1}' AND max_occupancy IS NOT NULL"
    trains = api('73th-g5ad', f"SELECT trip_id, trip_origin_station_name AS o, trip_destination_station AS d, branch_name AS b, date_extract_hh(scheduled_time_start) AS h, "
                              f"date_extract_mm(scheduled_time_start) AS mi, avg(max_occupancy) AS occ, avg(max_passengers) AS pax, max(max_stop_name) AS stop, count(*) AS n "
                              f"WHERE {w} GROUP BY trip_id, o, d, b, h, mi HAVING n >= 20 ORDER BY occ DESC LIMIT 15")
    branches = api('73th-g5ad', f"SELECT branch_name AS b, avg(max_occupancy) AS occ, count(*) AS n WHERE {w} AND "
                                f"((date_extract_hh(scheduled_time_start) >= 6 AND date_extract_hh(scheduled_time_start) < 9) OR "
                                f"(date_extract_hh(scheduled_time_start) >= 16 AND date_extract_hh(scheduled_time_start) < 19)) AND date_extract_dow(service_date) BETWEEN 1 AND 5 "
                                f"GROUP BY b HAVING n >= 50 ORDER BY occ DESC")
    hours = api('73th-g5ad', f"SELECT date_extract_hh(scheduled_time_start) AS h, avg(max_occupancy) AS occ WHERE {w} AND date_extract_dow(service_date) BETWEEN 1 AND 5 GROUP BY h ORDER BY h")
    models = api('73th-g5ad', f"SELECT model, avg(max_occupancy) AS occ, avg(car_count) AS cars, count(*) AS n WHERE {w} GROUP BY model HAVING n >= 100 ORDER BY occ DESC")
    full = api('73th-g5ad', f"SELECT count(*) AS n, sum(case(max_occupancy >= 1, 1, true, 0)) AS over WHERE {w}")[0]

    def t12(h, mi):
        h, mi = int(h), int(mi)
        return f'{(h % 12) or 12}:{mi:02d} {"am" if h < 12 else "pm"}'

    rows = [[e(t12(r['h'], r['mi'])), e(short(r['o'])) + ' → ' + e(short(r['d'])) + f'<br><small class="mta-note">{e(bname(r.get("b")))} branch</small>',
             pct(float(r['occ'])), num(float(r['pax']))] for r in trains]
    rng = f'{d0:%b %Y} – {d1:%b %Y}'
    body = sec('The most crowded LIRR trains', table(['Leaves', 'Train', 'Seats filled', 'Riders on board'], rows),
               f'Trains that ran at least 20 times, {rng}. "Seats filled" is riders on board at the train\'s fullest point, as a share of its seats, averaged over every run. '
               'Over 100% means people standing.')
    body += sec('Most crowded branches at rush hour', bars([[bname(r['b']), float(r['occ']), pct(float(r['occ'])), f'{num(int(r["n"]))} trips'] for r in branches if r.get('b')]),
                'Weekday trains leaving 6–9 am or 4–7 pm. Average share of seats filled at each train\'s fullest point.')
    pts = [(t12(r['h'], 0).replace(':00', ''), float(r['occ']), t12(r['h'], 0).replace(':00', '') if int(r['h']) % 3 == 0 else '') for r in hours]
    body += sec('How full LIRR trains get by hour', chart(pts, 'LIRR seats filled by hour', lambda v: pct(v)),
                'Weekdays, by the hour the train leaves its first station.')
    body += sec('Crowding by train model', bars([[r['model'], float(r['occ']), pct(float(r['occ'])), f'{num(int(r["n"]))} trips · {float(r["cars"]):.1f} cars on average'] for r in models]), '')
    body += source({'73th-g5ad': 'LIRR Trip-Level Occupancy'})
    top = trains[0]
    tiles = [tile(pct(float(top['occ'])), f'seats filled on the {t12(top["h"], top["mi"])} {short(top["o"])} → {short(top["d"])}'),
             tile(num(float(top['pax'])), 'riders on that train at its fullest'),
             tile(pct(int(full['over']) / max(1, int(full['n'])), 1), 'of trips had more riders than seats'),
             tile(num(int(full['n'])), f'trips counted, {rng}')]
    page('lirr-crowding', 'LIRR Crowding: The Most Packed Trains', f'How full every LIRR train gets, {rng}', tiles, body, shell, nav)
    return {'top': top}


# ---------- buses: breakdowns, fleet age, fare evasion ----------

def build_buses(shell, nav):
    md = api('7mt2-y7ip', 'SELECT month, borough, monthly_miles, monthly_road_call_count ORDER BY month')
    last = max(ym(r['month']) for r in md)
    y, m = int(last[:4]), int(last[5:7])
    y0 = f'{y - 1}-{m + 1:02d}' if m < 12 else f'{y}-01'
    boro = collections.defaultdict(lambda: [0.0, 0])
    sysm = collections.defaultdict(lambda: [0.0, 0])
    for r in md:
        mi, rc = float(r['monthly_miles'] or 0), int(float(r['monthly_road_call_count'] or 0))
        sysm[ym(r['month'])][0] += mi
        sysm[ym(r['month'])][1] += rc
        if ym(r['month']) >= y0:
            boro[r['borough']][0] += mi
            boro[r['borough']][1] += rc
    items = sorted([[b, v[0] / max(1, v[1]), num(v[0] / max(1, v[1])) + ' mi', f'{num(v[1])} breakdowns · {num(v[0] / 1e6, 1)} million miles'] for b, v in boro.items()],
                   key=lambda i: i[1])
    body = sec('Where buses break down the most', bars(items),
               f'Average miles a bus runs between breakdowns that need a road call, by the borough of its depot, {month_name(y0)} – {month_name(last)}. Higher is better; '
               'the borough at the top breaks down most often.')
    ks = sorted(sysm)
    roll = {ks[i]: sum(sysm[k][0] for k in ks[i - 11:i + 1]) / max(1, sum(sysm[k][1] for k in ks[i - 11:i + 1])) for i in range(11, len(ks)) if ks[i] >= '2019-01'}
    body += sec('Are bus breakdowns getting better?', chart(month_points(roll), 'Miles between bus breakdowns', lambda v: num(v) + ' mi'),
                'All boroughs, 12-month average. Each bar is one month.')

    fl = api('kf6s-w5a7', 'SELECT month, company, fleet_age, total_of_buses, _of_buses_over_12_years ORDER BY month')
    fl_last = max(ym(r['month']) for r in fl)
    now = [r for r in fl if ym(r['month']) == fl_last]
    names = {'NYCT': 'New York City Transit', 'MTA Bus': 'MTA Bus Company'}
    items = [[names.get(r['company'], r['company']), float(r['fleet_age']), f'{float(r["fleet_age"]):.1f} years',
              f'{num(int(r["total_of_buses"]))} buses · {num(int(r["_of_buses_over_12_years"]))} over 12 years old'] for r in now]
    total_bus = sum(int(r['total_of_buses']) for r in now)
    old_bus = sum(int(r['_of_buses_over_12_years']) for r in now)
    age = collections.defaultdict(lambda: [0.0, 0])
    for r in fl:
        age[ym(r['month'])][0] += float(r['fleet_age']) * int(r['total_of_buses'])
        age[ym(r['month'])][1] += int(r['total_of_buses'])
    body += sec('How old are NYC buses?', bars(items) + h3('Average bus age, by month') +
                chart(month_points({k: v[0] / v[1] for k, v in age.items() if v[1]}), 'Average bus age', lambda v: f'{v:.1f} yrs'),
                f'Average age of the bus fleet, {month_name(fl_last)}. The MTA plans to replace a bus after about 12 years. '
                'NYC Transit runs most local buses; MTA Bus Company runs routes the city took over from private companies, mostly in Queens.')

    fe = api('uv5h-dfhp', 'SELECT time_period, trip_type, fare_evasion ORDER BY time_period')
    q_last = max(r['time_period'] for r in fe)
    tot = {r['time_period']: float(r['fare_evasion']) for r in fe if r['trip_type'] == 'Total'}
    pts = [(q.replace('-', ' '), v, q[:4] if q.endswith('Q1') else (q.replace('-', ' ') if i == 0 and q[-1] in '12' else '')) for i, (q, v) in enumerate(sorted(tot.items()))]
    latest = [r for r in fe if r['time_period'] == q_last and r['trip_type'] != 'Total']
    names = {'Local': 'Local buses', 'SBS': 'Select Bus Service', 'Express': 'Express buses'}
    body += sec('How many bus riders don\'t pay?', chart(pts, 'Bus fare evasion by quarter', lambda v: pct(v)) + h3(f'By kind of bus, {q_last.replace("-", " ")}') +
                bars([[names.get(r['trip_type'], r['trip_type']), float(r['fare_evasion']), pct(float(r['fare_evasion'])), ''] for r in sorted(latest, key=lambda r: -float(r['fare_evasion']))]),
                'Share of bus riders who boarded without paying, from MTA staff counts on a sample of trips. Each bar is three months.')
    body += source({'7mt2-y7ip': 'Bus Mean Distance Between Failures', 'kf6s-w5a7': 'Bus System Fleet Age', 'uv5h-dfhp': 'Bus Fare Evasion'})
    worst = items and min(boro.items(), key=lambda kv: kv[1][0] / max(1, kv[1][1]))
    first_q = min(tot)
    tiles = [tile(pct(tot[q_last]), f'of bus riders didn\'t pay ({q_last.replace("-", " ")})'),
             tile(pct(tot[first_q]), f'in {first_q.replace("-", " ")}'),
             tile(worst[0], 'borough where buses break down most'),
             tile(num(old_bus), f'of {num(total_bus)} buses are over 12 years old')]
    page('bus-reliability', 'NYC Buses: Breakdowns, Bus Age and Fare Evasion', 'How reliable NYC buses are, how old they are and how many riders pay', tiles, body, shell, nav)
    return {'fare': tot[q_last], 'fare0': tot[first_q], 'worst': worst[0]}


# ---------- MTA money: income and spending, debt, subsidies ----------

def money(m):
    """Millions of dollars → '$5.2 billion' / '$331 million'."""
    return f'${m / 1000:,.1f} billion' if abs(m) >= 1000 else f'${m:,.0f} million'


def build_money(shell, nav):
    # Statement of operations: actual results, day-to-day (non-reimbursable) side, in millions.
    rows = api('yg77-3tkj', "SELECT fiscal_year AS y, type, subtype, general_ledger AS g, sum(amount)/1e6 AS m "
                            "WHERE scenario='Actual' AND expense_type='NREIMB' GROUP BY y, type, subtype, g")
    months = {r['y']: int(r['n']) for r in api('yg77-3tkj', "SELECT fiscal_year AS y, count(distinct month) AS n WHERE scenario='Actual' GROUP BY y")}
    full = sorted(y for y, n in months.items() if n == 12)
    yr = full[-1]
    by = collections.defaultdict(lambda: collections.defaultdict(float))
    for r in rows:
        m = float(r['m'] or 0)
        t, st, g = r.get('type') or '', r.get('subtype') or '', r.get('g') or ''
        if t == 'Total Revenue':
            by[r['y']]['rev:' + g] += m
            by[r['y']]['rev'] += m
        elif t.startswith('Total Expenses Before'):
            by[r['y']]['cost'] += m
            by[r['y']]['cost:' + st] += m
            if st == 'Labor Expenses':
                by[r['y']]['labor:' + g] += m
        elif t == 'Debt Service Expenses':
            by[r['y']]['debt'] += m
    x = by[yr]
    fares, tolls, other = x['rev:Farebox Revenue'], x['rev:Toll Revenue'], x['rev:Other Revenue']
    cost, debt_svc = x['cost'], x['debt']
    gap = cost + debt_svc - x['rev']
    body = sec(f'Where the MTA\'s money came from in {yr}',
               bars([['Fares (subway, bus, LIRR, Metro-North)', fares, money(fares), ''],
                     ['Bridge and tunnel tolls', tolls, money(tolls), ''],
                     ['Other income', other, money(other), 'advertising, rents, parking and other sources'],
                     ['Gap filled by taxes and government aid', gap, money(gap), 'what fares, tolls and other income didn\'t cover']]),
               f'Day-to-day operations only, actual results for {yr}. Building new things (the capital program) is paid for separately.')
    labor = sorted([(g[6:], v) for g, v in x.items() if g.startswith('labor:') and v > 0], key=lambda kv: -kv[1])
    names = {'Payroll': 'Pay (before overtime)', 'Health and Welfare': 'Health insurance', 'Pension': 'Pensions', 'Overtime': 'Overtime',
             'Other Fringe Benefits': 'Other benefits', 'OPEB Current Payments': 'Retiree health care'}
    spend = [[names.get(g, g), v, money(v), 'workers'] for g, v in labor]
    spend += [['Everything else (power, fuel, parts, contracts, insurance…)', x['cost:Non-Labor Expenses'], money(x['cost:Non-Labor Expenses']), ''],
              ['Paying off debt', debt_svc, money(debt_svc), 'interest and principal on bonds']]
    spend.sort(key=lambda i: -i[1])
    body += sec(f'Where the money went in {yr}', bars(spend),
                f'Total day-to-day costs of {money(cost)} plus {money(debt_svc)} of debt payments. Workers\' pay and benefits were {pct(x["cost:Labor Expenses"] / cost)} of day-to-day costs.')
    pts = [(y, by[y]['rev:Farebox Revenue'] / by[y]['cost'], y) for y in full if by[y]['cost']]
    trows = [[e(y), money(by[y]['rev:Farebox Revenue']), money(by[y]['rev:Toll Revenue']), money(by[y]['cost']), money(by[y]['debt']),
              pct(by[y]['rev:Farebox Revenue'] / by[y]['cost'])] for y in full]
    body += sec('How much of the cost do fares cover?', chart(pts, 'Share of day-to-day costs covered by fares', lambda v: pct(v)) +
                table(['Year', 'Fares', 'Tolls', 'Day-to-day costs', 'Debt payments', 'Fares cover'], trows),
                'Fares as a share of day-to-day costs, each full year. Fares fell sharply in 2020 when ridership collapsed.')

    # Debt.
    dates = [r['d'] for r in api('sze3-m8qh', 'SELECT posted_date AS d GROUP BY d ORDER BY d')]
    last = dates[-1]
    bonds = api('sze3-m8qh', f"SELECT type_of_credit AS t, total_outstanding_par AS p, tic, series_original_final_maturity AS mat WHERE posted_date = '{last}'")
    total = sum(float(b['p'] or 0) for b in bonds)
    by_t = collections.Counter()
    for b in bonds:
        by_t[b['t']] += float(b['p'] or 0)
    rate = sum(float(b['p'] or 0) * float(b.get('tic') or 0) for b in bonds) / max(1e-9, sum(float(b['p'] or 0) for b in bonds if b.get('tic')))
    lastmat = max(b['mat'][:4] for b in bonds if b.get('mat'))
    hist = {r['d'][:7]: float(r['p']) for r in api('sze3-m8qh', 'SELECT posted_date AS d, sum(total_outstanding_par) AS p GROUP BY d ORDER BY d')}
    tnames = {'Transportation Revenue Bonds': 'Backed by fares and subsidies (Transportation Revenue Bonds)'}
    debt_body = bars([[tnames.get(t, t), v, money(v), ''] for t, v in by_t.most_common()])
    if len(hist) > 2:
        debt_body += h3('Total debt over time') + chart(month_points(hist), 'MTA debt outstanding', lambda v: money(v))
    body += sec('How much does the MTA owe?', debt_body,
                f'Bonds still to be repaid on {dt.date.fromisoformat(last[:10]):%b} {int(last[8:10])}, {last[:4]}, by what pays them back. '
                f'Average interest rate about {rate * 100:.1f}%; the last bonds are due in {lastmat}.')

    # Subsidies: only the sources this dataset actually lists.
    sub = api('dfg9-c5rx', f"SELECT general_ledger AS g, account_category AS c, sum(amount)/1e6 AS m WHERE scenario='Actual' AND expense_type='Cash' AND fiscal_year='{yr}' GROUP BY g, c")
    labels = {('City Subsidy for MTA Bus Company', 'NaN'): 'New York City, for MTA Bus Company routes',
              ('Capital Lockbox Fund', 'Central Business District Tolling Program (CBDTP)'): 'Congestion pricing tolls (for building projects)',
              ('Casino License and Gaming Tax Revenues', 'Casino License Fees'): 'Casino license fees',
              ('Capital Lockbox Fund', 'Payroll Mobility Tax (PMT) for Capital Funding'): 'Payroll tax set aside for building projects',
              ('Capital Lockbox Fund', 'Real Estate Transfer Tax'): 'Tax on expensive home sales ("mansion tax")',
              ('Capital Lockbox Fund', 'Internet Marketplace Tax - State & City'): 'Sales tax on online shopping',
              ('For-Hire Vehicle (FHV) Surcharge', 'Subway Action Plan Account'): 'Surcharge on taxi and Uber/Lyft rides in Manhattan',
              ('PMT and MTA Aid', 'Payroll Mobility Tax Replacement Funds'): 'State payments replacing a cut payroll tax',
              ('Automated Camera Enforcement (ACE)', 'NaN'): 'Bus lane camera tickets'}
    items = []
    for r in sub:
        k = (r['g'], r['c'])
        if k in labels and float(r['m']) > 1:
            items.append([labels[k], float(r['m']), money(float(r['m'])), ''])
    items.sort(key=lambda i: -i[1])
    body += sec(f'Some of the taxes and fees that fund the MTA ({yr})', bars(items),
                'From the MTA\'s subsidy data. <strong>This dataset leaves out the MTA\'s biggest taxes</strong> (the main payroll tax, mortgage recording taxes, '
                'petroleum taxes and state operating aid), so these are only some of the sources. The "gap" above shows the full amount taxes and aid had to cover.')
    body += source({'yg77-3tkj': 'MTA Statement of Operations', 'sze3-m8qh': 'MTA Debt Outstanding', 'dfg9-c5rx': 'MTA Subsidies'},
                   'All amounts are actual results, not budgets.')
    tiles = [tile(money(cost + debt_svc), f'spent running the MTA in {yr}'), tile(pct(fares / cost), 'of day-to-day costs paid by fares'),
             tile(money(total), 'in debt still owed'), tile(money(x['labor:Overtime']), f'spent on overtime in {yr}')]
    page('mta-money', 'MTA Money: Where It Comes From, Where It Goes', f'The MTA\'s income, spending and debt, {yr}', tiles, body, shell, nav)
    return {'cost': cost, 'debt': total, 'fares': fares, 'gap': gap}


def main():
    os.makedirs(OUT, exist_ok=True)
    shell = get_shell()
    pages = [('subway-cars', 'Subway cars'), ('planned-work', 'Planned work'), ('lirr-crowding', 'LIRR crowding'), ('bus-reliability', 'Buses'), ('mta-money', 'MTA money')]
    nav = ' · '.join(f'<a href="{s}.html">{e(t)}</a>' for s, t in pages)
    facts = {'cars': build_cars(shell, nav), 'work': build_work(shell, nav), 'lirr': build_lirr(shell, nav), 'buses': build_buses(shell, nav), 'money': build_money(shell, nav)}
    idx = '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>New data preview</title><body style="font:16px system-ui;max-width:640px;margin:40px auto;padding:0 16px">' \
          '<h1>New MTA data: local preview</h1><ul>' + ''.join(f'<li><a href="{s}.html">{e(t)}</a></li>' for s, t in pages) + '</ul></body>'
    with open(os.path.join(OUT, 'index.html'), 'w', encoding='utf-8') as f:
        f.write(idx)
    print(json.dumps(facts, default=str, indent=1)[:3000])


if __name__ == '__main__':
    main()
