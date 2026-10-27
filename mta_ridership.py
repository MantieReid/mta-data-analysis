"""NYC subway ridership analysis (2025 + 2026 to date).

Uses the data.ny.gov Socrata API, dataset 5wq4-mkjj ("MTA Subway Hourly
Ridership: Beginning 2025"). All summing happens server-side via SoQL: we only
download one row per station per day (~300k small rows), never the raw hourly CSV.

Outputs (in ./output):
  station_monthly.csv     riders per month per station_complex_id (+ partial flag)
  station_month_avg.csv   average riders per month per station (complete months only)
  system_monthly.json     system-wide monthly totals + average month
  weekly.csv / .json      weekly totals and average riders per station per week
  seasons.json            average weekly riders per season (complete weeks only)
  week_<date>.json        (with --week) per-station riders for one week
  station_yearly.csv, yearly.json        yearly totals per station, top 10 per year
  day_of_week.json                       average daily riders per weekday, per year
  station_hourly_avg.csv, system_hourly_avg.json, month_hourly_avg.json
                                         average riders per hour of day
  station_seasonal.csv, seasonal_top5.json   per-station season totals, top 5
  top_stations_latest.json               top 5 on the latest day and the last 7 days
  unusual_days.json                      unusually busy/quiet days per station
  weekend_vs_weekday.json, late_night.json, transfers.json   last-365-day station trends
  charts/*.png            matplotlib charts (skip with --no-charts)

Usage:
  python mta_ridership.py                 # full run
  python mta_ridership.py --week 2026-03-09
  set SOCRATA_APP_TOKEN=...               # optional, avoids throttling
"""
import argparse
import csv
import json
import math
from decimal import ROUND_HALF_UP, Decimal
import os
import statistics
import sys
import time
import urllib.parse
import urllib.request
from collections import defaultdict
from datetime import date, datetime, timedelta

API = "https://data.ny.gov/resource/5wq4-mkjj.json"
PAGE = 50000
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output")
CHARTS = os.path.join(OUT, "charts")
CACHE = os.path.join(OUT, "daily_station_cache.csv")
HOURLY_CACHE = os.path.join(OUT, "hourly_station_cache.csv")
MONTH_HOUR_CACHE = os.path.join(OUT, "month_hour_cache.csv")
WEEKDAYS = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]
SEASONS = {12: "winter", 1: "winter", 2: "winter", 3: "spring", 4: "spring", 5: "spring",
           6: "summer", 7: "summer", 8: "summer", 9: "fall", 10: "fall", 11: "fall"}


def soql(params):
    url = API + "?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url)
    token = os.environ.get("SOCRATA_APP_TOKEN")
    if token:
        req.add_header("X-App-Token", token)
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req, timeout=300) as r:
                return json.load(r)
        except Exception as e:  # network/throttle: back off and retry
            if attempt == 4:
                raise
            print(f"  retry {attempt + 1}: {e}", file=sys.stderr)
            time.sleep(5 * (attempt + 1))


def cached_query(cache, select, group, label, refresh=False, where=None):
    """Run a grouped SoQL query (paged) and cache the small result as CSV."""
    if os.path.exists(cache) and not refresh:
        with open(cache, newline="", encoding="utf-8") as f:
            return [dict(r, riders=float(r["riders"])) for r in csv.DictReader(f)]
    rows, offset = [], 0
    while True:
        batch = soql({"$select": select + ", sum(ridership) AS riders",
                      "$where": "transit_mode = 'subway'" + (f" AND {where}" if where else ""),
                      "$group": group, "$order": group,
                      "$limit": PAGE, "$offset": offset})
        rows += batch
        print(f"  fetched {len(rows):,} {label}")
        if len(batch) < PAGE:
            break
        offset += PAGE
    out = [{k: (v[:10] if k == "day" else v) for k, v in r.items()} for r in rows]
    for r in out:
        r.setdefault("borough", "")
        r["riders"] = float(r["riders"])
    os.makedirs(OUT, exist_ok=True)
    with open(cache, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(out[0]))
        w.writeheader()
        w.writerows(out)
    return out


def fetch_daily(refresh=False):
    """Daily ridership per station, summed by the API."""
    return cached_query(CACHE, "station_complex_id, station_complex, borough, "
                               "date_trunc_ymd(transit_timestamp) AS day",
                        "station_complex_id, station_complex, borough, day", "station-days", refresh)


def fetch_hourly(refresh=False):
    """Ridership per station per hour of day per year, summed by the API."""
    return cached_query(HOURLY_CACHE, "station_complex_id, date_extract_y(transit_timestamp) AS yr, "
                                      "date_extract_hh(transit_timestamp) AS hr",
                        "station_complex_id, yr, hr", "station-year-hours", refresh)


def fetch_month_hour(refresh=False):
    """System-wide ridership per month per hour of day, summed by the API."""
    return cached_query(MONTH_HOUR_CACHE, "date_trunc_ym(transit_timestamp) AS month, "
                                          "date_extract_hh(transit_timestamp) AS hr",
                        "month, hr", "month-hours", refresh)


def days_in_month(y, m):
    nxt = date(y + (m == 12), m % 12 + 1, 1)
    return (nxt - date(y, m, 1)).days


def week_start(d):
    return d - timedelta(days=d.weekday())  # Monday


def season_label(d):
    """Season of a date; Dec belongs to the next year's winter."""
    s = SEASONS[d.month]
    if s == "winter":
        y = d.year if d.month == 12 else d.year - 1
        return f"winter {y}-{str(y + 1)[2:]}"
    return f"{s} {d.year}"


def season_of(week_mon):
    """Season of a week, decided by its Thursday."""
    return season_label(week_mon + timedelta(days=3))


def write_csv(name, rows):
    with open(os.path.join(OUT, name), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(rows)


def write_json(name, obj):
    with open(os.path.join(OUT, name), "w", encoding="utf-8") as f:
        json.dump(obj, f, indent=1)


# --- Station trends: same method and thresholds as the WordPress plugin (includes/trends.php) ---

SPIKE_RATIO, QUIET_RATIO = 3.0, 0.35
SPIKE_MIN_BASELINE, QUIET_MIN_BASELINE = 1500, 3000


def php_round(x, nd=0):
    """Round half away from zero, like PHP's round(), so outputs match the plugin exactly."""
    # Work on the decimal form (like PHP's pre-rounding) so 40.755745 -> 40.75575, not 40.75574.
    r = Decimal(repr(float(x))).quantize(Decimal(1).scaleb(-nd), rounding=ROUND_HALF_UP)
    return int(r) if nd == 0 else float(r)


def baseline(series, i):
    """Median of the same weekday over the previous 26 weeks (needs at least 10)."""
    b = [series[i - 7 * k] for k in range(1, 27) if (i - 7 * k) in series]
    return statistics.median(b) if len(b) >= 10 else None


def find_events(st, n, sys_ratio, busy):
    """Runs of unusual days per station, relative to the whole system that day."""
    events = []
    for sid, series in st.items():
        run = None
        for i in range(70, n):
            if i not in series or not sys_ratio.get(i):
                continue
            base = baseline(series, i)
            if base is None or base < (SPIKE_MIN_BASELINE if busy else QUIET_MIN_BASELINE):
                continue
            ratio = series[i] / base
            rel = ratio / sys_ratio[i]
            hit = rel >= SPIKE_RATIO if busy else (rel <= QUIET_RATIO and series[i] > 0)
            if run and i - run["last"] > 2:
                events.append(run)
                run = None
            if not hit:
                continue
            if not run:
                run = {"id": sid, "first": i, "last": i, "days": 0, "peak": i,
                       "ratio": ratio, "riders": series[i], "baseline": base}
            run["last"] = i
            run["days"] += 1
            if (ratio > run["ratio"]) if busy else (ratio < run["ratio"]):
                run.update(peak=i, ratio=ratio, riders=series[i], baseline=base)
        if run:
            events.append(run)
    return events


def compute_trends(daily, names, latest, refresh):
    start = date.fromisoformat(min(r["day"] for r in daily))
    day = lambda i: str(start + timedelta(days=i))
    st = defaultdict(dict)
    for r in daily:
        st[r["station_complex_id"]][(date.fromisoformat(r["day"]) - start).days] = php_round(r["riders"])
    n = (latest - start).days + 1
    last = n - 1

    sys_tot = defaultdict(int)
    for series in st.values():
        for i, v in series.items():
            sys_tot[i] += v
    sys_ratio = {}
    for i in range(n):
        b = baseline(sys_tot, i)
        sys_ratio[i] = sys_tot[i] / b if b and sys_tot.get(i) else None

    def out(e):
        s = st[e["id"]]
        lo, hi = max(0, e["peak"] - 28), min(last, e["peak"] + 14)
        return {"id": e["id"], "name": names[e["id"]][0], "borough": names[e["id"]][1],
                "peak_day": day(e["peak"]), "first_day": day(e["first"]), "last_day": day(e["last"]),
                "days": e["days"], "riders": e["riders"], "baseline": php_round(e["baseline"]),
                "ratio": php_round(e["ratio"], 2), "series_start": day(lo),
                "series": [s.get(i) for i in range(lo, hi + 1)]}

    def by_year(events, busy):
        groups = defaultdict(list)
        for e in events:
            groups[day(e["peak"])[:4]].append(e)
        result = {}
        for y in sorted(groups):
            # busy: biggest multiple of normal; quiet: most riders missing (closures all sit near 0%)
            ranked = sorted(groups[y], key=(lambda e: -e["ratio"]) if busy else (lambda e: -e["baseline"] * e["days"]))
            seen, keep = defaultdict(int), []
            for e in ranked:  # at most 2 per station, so Mets-Willets Point doesn't fill the list
                seen[e["id"]] += 1
                if seen[e["id"]] <= 2:
                    keep.append(e)
            result[y] = [out(e) for e in keep[:10]]
        return result

    spikes = find_events(st, n, sys_ratio, True)
    quiet = find_events(st, n, sys_ratio, False)
    recent = sorted((e for e in spikes if e["last"] >= last - 13), key=lambda e: -e["ratio"])[:5]
    write_json("unusual_days.json", {
        "data_through": str(latest),
        "settings": {"spike_ratio": SPIKE_RATIO, "quiet_ratio": QUIET_RATIO,
                     "spike_min_baseline": SPIKE_MIN_BASELINE, "quiet_min_baseline": QUIET_MIN_BASELINE},
        "recent_14_days": [out(e) for e in recent],
        "busy_by_year": by_year(spikes, True),
        "quiet_by_year": by_year(quiet, False),
    })

    # Top 5 on the latest day and over the last 7 days.
    def top(lo, hi, k):
        tot = defaultdict(float)
        for sid, series in st.items():
            tot[sid] = sum(series.get(i, 0) for i in range(lo, hi + 1))
        best = sorted(tot.items(), key=lambda kv: -kv[1])[:k]
        return [{"id": sid, "name": names[sid][0], "borough": names[sid][1], "riders": php_round(v)} for sid, v in best]
    write_json("top_stations_latest.json", {
        "latest_day": str(latest), "top_latest_day": top(last, last, 5),
        "week_from": day(last - 6), "top_last_7_days": top(last - 6, last, 5)})

    # Weekend vs weekday over the last 364 days, skipping days a station was nearly closed.
    frm = max(0, n - 364)
    ww = []
    for sid, series in st.items():
        window = [v for i, v in series.items() if i >= frm]
        if not window:
            continue
        floor = 0.2 * statistics.median(window)
        wd, we = [], []
        for i in range(frm, n):
            if i not in series or series[i] < floor:
                continue
            (we if (start + timedelta(days=i)).weekday() >= 5 else wd).append(series[i])
        if len(wd) < 100 or len(we) < 40:
            continue
        a, b = sum(wd) / len(wd), sum(we) / len(we)
        if a < 1 or a + b < 2000:
            continue
        ww.append({"id": sid, "name": names[sid][0], "borough": names[sid][1],
                   "weekday_avg": php_round(a), "weekend_avg": php_round(b), "ratio": php_round(b / a, 3)})
    ww.sort(key=lambda x: -x["ratio"])
    year_from = day(last - 364)
    write_json("weekend_vs_weekday.json", {"from": year_from, "to": str(latest),
                                           "busier_on_weekends": ww[:10], "mostly_weekdays": ww[::-1][:10]})

    # Late-night share and transfers over the last 365 days (summed by the API).
    rng = (f"transit_timestamp >= '{year_from}' AND "
           f"transit_timestamp < '{latest + timedelta(days=1)}'")
    tot = {r["station_complex_id"]: {"riders": r["riders"], "transfers": float(r.get("transfers") or 0)}
           for r in cached_query(os.path.join(OUT, "year_station_cache.csv"),
                                 "station_complex_id, sum(transfers) AS transfers",
                                 "station_complex_id", "station totals (last 365 days)", refresh,
                                 where=rng)}
    night = []
    for r in cached_query(os.path.join(OUT, "late_night_cache.csv"), "station_complex_id",
                          "station_complex_id", "late-night station totals", refresh,
                          where=rng + " AND date_extract_hh(transit_timestamp) < 5"):
        sid = r["station_complex_id"]
        if sid not in tot or tot[sid]["riders"] < 365 * 2000 or sid not in names:
            continue
        night.append({"id": sid, "name": names[sid][0], "borough": names[sid][1],
                      "night_riders": php_round(r["riders"]), "share": php_round(r["riders"] / tot[sid]["riders"], 4)})
    night.sort(key=lambda x: -x["share"])
    write_json("late_night.json", {"from": year_from, "to": str(latest), "hours": "00:00-04:59", "top_10": night[:10]})
    transfers = sorted(({"id": sid, "name": names[sid][0], "borough": names[sid][1],
                         "transfers": php_round(t["transfers"]), "share": php_round(t["transfers"] / max(1, t["riders"]), 4)}
                        for sid, t in tot.items() if t["transfers"] > 0 and sid in names),
                       key=lambda x: -x["transfers"])
    write_json("transfers.json", {"from": year_from, "to": str(latest), "top_10": transfers[:10]})
    return spikes, recent


def compute_extras(daily, names, latest, refresh):
    """Map data (station locations + average riders per hour), year-over-year change,
    fare types and payment methods. Written to output/extras.json (the shape the plugin uses)."""
    year_from = latest - timedelta(days=364)
    rng = f"transit_timestamp >= '{year_from}' AND transit_timestamp < '{latest + timedelta(days=1)}'"
    ndays = len({r["day"] for r in daily if r["day"] >= str(year_from)})

    coords = {r["station_complex_id"]: (float(r["lat"]), float(r["lon"]))
              for r in cached_query(os.path.join(OUT, "coords_cache.csv"),
                                    "station_complex_id, max(latitude) AS lat, max(longitude) AS lon",
                                    "station_complex_id", "station locations", refresh)}
    # Map: the average day over the last 8 weeks. Grouping by station and hour over more than about
    # a week at a time times out on the API, so ask one week at a time and add up.
    map_from = latest - timedelta(days=55)
    map_days = len({r["day"] for r in daily if r["day"] >= str(map_from)})
    tot = defaultdict(lambda: [0.0] * 24)
    lo = map_from
    while lo <= latest:
        hi = min(lo + timedelta(days=7), latest + timedelta(days=1))
        for r in cached_query(os.path.join(OUT, f"station_hour_{lo:%Y%m%d}_cache.csv"),
                              "station_complex_id, date_extract_hh(transit_timestamp) AS hr",
                              "station_complex_id, hr", f"station-hours from {lo}", refresh,
                              where=f"transit_timestamp >= '{lo}' AND transit_timestamp < '{hi}'"):
            tot[r["station_complex_id"]][int(r["hr"])] += r["riders"]
        lo = hi
    hours = {sid: [php_round(v / map_days) for v in hs] for sid, hs in tot.items()}
    stations = sorted(({"id": sid, "name": names[sid][0], "borough": names[sid][1],
                        "lat": php_round(coords[sid][0], 5), "lon": php_round(coords[sid][1], 5), "hours": hours[sid]}
                       for sid in hours if sid in coords and sid in names), key=lambda s: -sum(s["hours"]))

    # Year over year: this year so far vs the same calendar days last year.
    cur_y = latest.year
    md = latest.strftime("%m-%d")
    cur, prev, boro_c, boro_p = defaultdict(float), defaultdict(float), defaultdict(float), defaultdict(float)
    for r in daily:
        y, rmd = int(r["day"][:4]), r["day"][5:]
        if rmd > md:
            continue
        if y == cur_y:
            cur[r["station_complex_id"]] += r["riders"]
            boro_c[r["borough"]] += r["riders"]
        elif y == cur_y - 1:
            prev[r["station_complex_id"]] += r["riders"]
            boro_p[r["borough"]] += r["riders"]
    yoy = None
    if prev:
        days_in = (latest - date(cur_y, 1, 1)).days + 1
        moves = [{"id": sid, "name": names[sid][0], "borough": names[sid][1], "prev": php_round(prev[sid]),
                  "cur": php_round(cur[sid]), "pct": php_round((cur[sid] / prev[sid] - 1) * 100, 1)}
                 for sid in prev if sid in cur and prev[sid] >= days_in * 2000]  # 2,000+ riders/day last year
        moves.sort(key=lambda m: -m["pct"])
        yoy = {"year": cur_y, "prev_year": cur_y - 1, "through": md,
               "total_prev": php_round(sum(prev.values())), "total_cur": php_round(sum(cur.values())),
               "total_pct": php_round((sum(cur.values()) / sum(prev.values()) - 1) * 100, 1),
               "boroughs": sorted(({"borough": b, "prev": php_round(boro_p[b]), "cur": php_round(boro_c[b]),
                                    "pct": php_round((boro_c[b] / boro_p[b] - 1) * 100, 1)}
                                   for b in boro_p if boro_c.get(b)), key=lambda x: -x["cur"]),
               "up": moves[:8], "down": moves[::-1][:8]}

    # Station lookup ("Find your station"): every station's average riders a day over the last
    # 365 days, its rank, and this year vs the same days last year (if it had 100+ riders/day then).
    days_cur = (latest - date(cur_y, 1, 1)).days + 1
    year_sum = defaultdict(int)
    for r in daily:
        if r["day"] >= str(year_from):
            year_sum[r["station_complex_id"]] += php_round(r["riders"])  # the plugin keeps whole riders per day
    lookup = [{"id": sid, "name": names[sid][0], "borough": names[sid][1], "avg": php_round(s / max(1, ndays)),
               "yoy": php_round((cur[sid] / prev[sid] - 1) * 100, 1)
               if prev.get(sid, 0) >= days_cur * 100 and cur.get(sid) else None}
              for sid, s in year_sum.items() if php_round(s / max(1, ndays)) >= 1 and sid in names]  # skips e.g. St George (SIR)
    lookup.sort(key=lambda x: (-x["avg"], x["id"]))
    for i, row in enumerate(lookup):
        row["rank"] = i + 1

    fares = cached_query(os.path.join(OUT, "fare_class_cache.csv"), "fare_class_category",
                         "fare_class_category", "fare types (last 365 days)", refresh, where=rng)
    ftot = sum(r["riders"] for r in fares)
    fares = sorted(({"category": r["fare_class_category"], "riders": php_round(r["riders"]),
                     "share": php_round(r["riders"] / ftot, 4)} for r in fares), key=lambda x: -x["riders"])

    pay = defaultdict(dict)
    for r in cached_query(os.path.join(OUT, "payment_cache.csv"),
                          "date_trunc_ym(transit_timestamp) AS month, payment_method",
                          "month, payment_method", "payment methods by month", refresh):
        pay[r["month"][:7]][r["payment_method"].lower()] = r["riders"]
    full_days = {}
    for r in daily:
        full_days.setdefault(r["day"][:7], set()).add(r["day"])
    payment = []
    for m in sorted(pay):
        o, mc = pay[m].get("omny", 0), pay[m].get("metrocard", 0)
        y, mo = int(m[:4]), int(m[5:])
        payment.append({"month": m, "omny": php_round(o), "metrocard": php_round(mc),
                        "omny_share": php_round(o / max(1, o + mc), 4),
                        "partial": len(full_days.get(m, ())) < days_in_month(y, mo)})

    extras = {"from": str(year_from), "to": str(latest), "days": ndays,
              "map_from": str(map_from), "map_days": map_days, "stations": stations,
              "yoy": yoy, "fares": fares, "payment": payment, "lookup": lookup}
    write_json("extras.json", extras)
    return extras


# Storms and events can't be worked out from dates, so they're listed by hand (same list as the plugin).
EVENTS = {
    "2026-01-25": "Snowstorm", "2026-01-26": "Snowstorm (cleanup day)",
    "2026-02-22": "Winter storm", "2026-02-23": "Winter storm",
    "2026-09-22": "UN General Assembly week", "2026-09-23": "UN General Assembly week",
    "2026-09-24": "UN General Assembly week",
}


def nth_weekday(y, m, wd, n):
    """n-th (1-based) weekday of a month, n = -1 for the last; wd: 0 = Mon ... 6 = Sun."""
    if n > 0:
        d = date(y, m, 1)
        return d + timedelta(days=(wd - d.weekday()) % 7 + 7 * (n - 1))
    d = date(y, m, days_in_month(y, m))
    return d - timedelta(days=(d.weekday() - wd) % 7)


def holidays(y):
    """US holidays and recurring NYC events for a year, as 'YYYY-MM-DD' -> label."""
    h = {f"{y}-01-01": "New Year's Day", str(nth_weekday(y, 1, 0, 3)): "MLK Day",
         str(nth_weekday(y, 2, 0, 3)): "Presidents' Day", str(nth_weekday(y, 5, 0, -1)): "Memorial Day",
         f"{y}-06-19": "Juneteenth", f"{y}-07-04": "July 4th", str(nth_weekday(y, 9, 0, 1)): "Labor Day",
         str(nth_weekday(y, 10, 0, 2)): "Columbus / Indigenous Peoples' Day",
         str(nth_weekday(y, 11, 6, 1)): "NYC Marathon", f"{y}-11-11": "Veterans Day",
         f"{y}-12-24": "Christmas Eve", f"{y}-12-25": "Christmas", f"{y}-12-31": "New Year's Eve"}
    thanks = nth_weekday(y, 11, 3, 4)
    h[str(thanks)] = "Thanksgiving"
    h[str(thanks + timedelta(days=1))] = "Day after Thanksgiving"
    july4 = date(y, 7, 4).weekday()
    if july4 == 5:
        h[f"{y}-07-03"] = "July 4th (observed)"
    elif july4 == 6:
        h[f"{y}-07-05"] = "July 4th (observed)"
    return h


def compute_calendar(daily):
    """Riders per day vs a normal day for that weekday (median of the 26 weeks before, at least 4).
    Same method as the plugin's calendar.php; written to output/calendar.json."""
    sys_tot = defaultdict(int)
    for r in daily:
        sys_tot[r["day"]] += php_round(r["riders"])
    days = sorted(date.fromisoformat(d) for d in sys_tot)
    labels = dict(EVENTS)
    for y in range(days[0].year, days[-1].year + 1):
        for d, l in holidays(y).items():
            labels.setdefault(d, l)  # hand-listed events win over holidays
    out = []
    for d in days:
        b = [sys_tot[str(d - timedelta(weeks=k))] for k in range(1, 27) if str(d - timedelta(weeks=k)) in sys_tot]
        out.append({"d": str(d), "r": sys_tot[str(d)],
                    "x": php_round(sys_tot[str(d)] / statistics.median(b), 3) if len(b) >= 4 else None,
                    "l": labels.get(str(d))})
    write_json("calendar.json", {"days": out})
    return out


def make_charts(sys_month, month_partial, weekly, seasons, yearly, dow, hourly_sys, month_hour, top5):
    """Save PNG charts to output/charts. Partial periods are drawn hatched/dashed."""
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    from matplotlib.ticker import FuncFormatter

    os.makedirs(CHARTS, exist_ok=True)
    millions = FuncFormatter(lambda x, _: f"{x / 1e6:.0f}M" if x >= 1e6 else f"{x / 1e3:.0f}K")
    colors = {"2025": "#2a6fdb", "2026": "#e8590c"}

    def save(fig, name):
        fig.tight_layout()
        fig.savefig(os.path.join(CHARTS, name), dpi=150)
        plt.close(fig)

    # monthly totals
    months = sorted(sys_month)
    fig, ax = plt.subplots(figsize=(12, 5))
    bars = ax.bar(months, [sys_month[m] for m in months],
                  color=[colors.get(m[:4], "gray") for m in months])
    for b, m in zip(bars, months):
        if month_partial[m]:
            b.set_hatch("//"); b.set_alpha(0.5)
    ax.yaxis.set_major_formatter(millions)
    ax.set_title("Subway riders per month (hatched = partial month)")
    ax.tick_params(axis="x", rotation=60)
    save(fig, "monthly_totals.png")

    # weekly totals
    fig, ax = plt.subplots(figsize=(12, 5))
    full = [w for w in weekly if not w["partial"]]
    ax.plot([date.fromisoformat(w["week_start"]) for w in full], [w["riders"] for w in full], color="#2a6fdb")
    ax.yaxis.set_major_formatter(millions)
    ax.set_title("Subway riders per week (complete weeks)")
    save(fig, "weekly_totals.png")

    # seasons
    fig, ax = plt.subplots(figsize=(10, 5))
    names_ = [s["season"] for s in seasons]
    bars = ax.bar(names_, [s["avg_weekly_riders"] or 0 for s in seasons],
                  color=["#4c9be8" if s["season"].startswith("winter") else "#7bc47f" if s["season"].startswith("spring")
                         else "#f5b041" if s["season"].startswith("summer") else "#d35400" for s in seasons])
    for b, s in zip(bars, seasons):
        if s["partial"]:
            b.set_hatch("//"); b.set_alpha(0.5)
    ax.yaxis.set_major_formatter(millions)
    ax.set_title("Average riders per week by season (hatched = partial season)")
    ax.tick_params(axis="x", rotation=30)
    save(fig, "seasonal_avg_weekly.png")

    # top 10 stations per year
    fig, axes = plt.subplots(1, len(yearly), figsize=(7 * len(yearly), 5))
    for ax, (y, info) in zip(axes if len(yearly) > 1 else [axes], yearly.items()):
        top = info["top_10_stations"][::-1]
        ax.barh([t["station_complex"] for t in top], [t["riders"] for t in top], color=colors.get(y, "gray"))
        ax.xaxis.set_major_formatter(millions)
        ax.set_title(f"Top 10 stations {y}" + (" (to date)" if info["partial"] else ""))
        ax.tick_params(axis="y", labelsize=8)
    save(fig, "top10_stations_by_year.png")

    # day of week
    years = sorted(yearly)
    fig, ax = plt.subplots(figsize=(10, 5))
    width = 0.8 / len(years)
    for i, y in enumerate(years):
        vals = [sum(dow[(int(y), d)]) / len(dow[(int(y), d)]) if dow[(int(y), d)] else 0 for d in range(7)]
        ax.bar([d + i * width for d in range(7)], vals, width, label=y, color=colors.get(y, "gray"))
    ax.set_xticks([d + width * (len(years) - 1) / 2 for d in range(7)], WEEKDAYS)
    ax.yaxis.set_major_formatter(millions)
    ax.set_title("Average daily subway riders by day of week")
    ax.legend()
    save(fig, "day_of_week_avg.png")

    # hourly profile per year
    fig, ax = plt.subplots(figsize=(10, 5))
    for y, vals in hourly_sys.items():
        ax.plot(range(24), vals, marker="o", label=y, color=colors.get(y, "gray"))
    ax.set_xticks(range(24))
    ax.yaxis.set_major_formatter(millions)
    ax.set_xlabel("Hour of day"); ax.set_title("Average riders per hour of day (system-wide)")
    ax.legend()
    save(fig, "hourly_avg_by_year.png")

    # hourly profile per month (small multiples, shared y)
    ms = sorted(month_hour)
    cols = 6
    rows = -(-len(ms) // cols)
    fig, axes = plt.subplots(rows, cols, figsize=(3 * cols, 2.2 * rows), sharex=True, sharey=True)
    for ax, m in zip(axes.flat, ms):
        ax.plot(range(24), month_hour[m], color=colors.get(m[:4], "gray"),
                linestyle="--" if month_partial[m] else "-")
        ax.set_title(m + (" (partial)" if month_partial[m] else ""), fontsize=9)
        ax.yaxis.set_major_formatter(millions)
    for ax in list(axes.flat)[len(ms):]:
        ax.axis("off")
    fig.suptitle("Average riders per hour of day, by month (peak vs off-peak)")
    save(fig, "hourly_avg_by_month.png")

    # top 5 stations per season
    ss = list(top5.items())
    cols = 4
    rows = -(-len(ss) // cols)
    fig, axes = plt.subplots(rows, cols, figsize=(5 * cols, 3 * rows))
    for ax, (s, info) in zip(axes.flat, ss):
        top = info["top_5"][::-1]
        ax.barh([t["station_complex"][:28] for t in top], [t["riders"] for t in top], color="#5f6caf")
        ax.xaxis.set_major_formatter(millions)
        ax.set_title(s + (" (partial)" if info["partial"] else ""), fontsize=10)
        ax.tick_params(axis="y", labelsize=7)
    for ax in list(axes.flat)[len(ss):]:
        ax.axis("off")
    fig.suptitle("Top 5 stations per season (total riders)")
    save(fig, "top5_stations_by_season.png")
    print(f"Charts written to {CHARTS}")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--week", help="any date (YYYY-MM-DD) in the week to report per-station averages for")
    ap.add_argument("--refresh", action="store_true", help="re-query the API instead of using the cache")
    ap.add_argument("--no-charts", action="store_true", help="skip PNG chart generation")
    args = ap.parse_args()

    print("Querying data.ny.gov (server-side daily sums)...")
    daily = fetch_daily(args.refresh)
    names, system_day = {}, defaultdict(float)
    for r in daily:
        names[r["station_complex_id"]] = (r["station_complex"], r["borough"])
        system_day[r["day"]] += r["riders"]
    days = sorted(date.fromisoformat(d) for d in system_day)
    first, last = days[0], days[-1]
    have = set(days)
    print(f"Data covers {first} to {last} ({len(days)} days, {len(names)} stations)")

    # --- monthly per station ---
    sm = defaultdict(float)
    for r in daily:
        sm[(r["station_complex_id"], r["day"][:7])] += r["riders"]
    month_days = defaultdict(int)
    for d in days:
        month_days[d.strftime("%Y-%m")] += 1
    month_partial = {m: n < days_in_month(int(m[:4]), int(m[5:])) for m, n in month_days.items()}

    rows = [{"station_complex_id": sid, "station_complex": names[sid][0], "borough": names[sid][1],
             "month": m, "riders": round(v), "days_with_data": month_days[m],
             "partial": month_partial[m]} for (sid, m), v in sorted(sm.items())]
    write_csv("station_monthly.csv", rows)

    # --- average riders per month per station (complete months only) ---
    per_station = defaultdict(list)
    for (sid, m), v in sm.items():
        if not month_partial[m]:
            per_station[sid].append(v)
    write_csv("station_month_avg.csv", sorted(
        ({"station_complex_id": sid, "station_complex": names[sid][0], "borough": names[sid][1],
          "avg_monthly_riders": round(sum(v) / len(v)), "complete_months": len(v)}
         for sid, v in per_station.items()), key=lambda r: -r["avg_monthly_riders"]))

    sys_month = defaultdict(float)
    for (sid, m), v in sm.items():
        sys_month[m] += v
    complete = [v for m, v in sys_month.items() if not month_partial[m]]
    write_json("system_monthly.json", {
        "generated": datetime.now().isoformat(timespec="seconds"),
        "data_through": str(last),
        "avg_monthly_riders_complete_months": round(sum(complete) / len(complete)),
        "months": [{"month": m, "riders": round(sys_month[m]), "days_with_data": month_days[m],
                    "partial": month_partial[m]} for m in sorted(sys_month)],
    })

    # --- weekly (Mon-Sun) ---
    wk_total, wk_days, wk_stations = defaultdict(float), defaultdict(set), defaultdict(set)
    for r in daily:
        w = week_start(date.fromisoformat(r["day"]))
        wk_total[w] += r["riders"]
        wk_days[w].add(r["day"])
        wk_stations[w].add(r["station_complex_id"])
    weekly = []
    for w in sorted(wk_total):
        n = len(wk_stations[w])
        weekly.append({"week_start": str(w), "season": season_of(w), "riders": round(wk_total[w]),
                       "stations": n, "avg_riders_per_station": round(wk_total[w] / n),
                       "days_with_data": len(wk_days[w]), "partial": len(wk_days[w]) < 7})
    write_csv("weekly.csv", weekly)
    write_json("weekly.json", weekly)

    # --- seasons: average weekly riders, complete weeks only ---
    by_season = defaultdict(list)
    for w in weekly:
        by_season[w["season"]].append(w)
    seasons = []
    for s, ws in by_season.items():
        full = [w["riders"] for w in ws if not w["partial"]]
        mon = date.fromisoformat(ws[0]["week_start"])
        # a season is partial if it's missing weeks at the dataset edges
        partial = (mon - timedelta(days=7) < first and season_of(mon - timedelta(days=7)) == s) or \
                  season_of(week_start(last) + timedelta(days=7)) == s or any(w["partial"] for w in ws)
        seasons.append({"season": s, "first_week": ws[0]["week_start"], "complete_weeks": len(full),
                        "avg_weekly_riders": round(sum(full) / len(full)) if full else None,
                        "partial": partial})
    seasons.sort(key=lambda x: x["first_week"])
    write_json("seasons.json", seasons)

    days_per_year = defaultdict(int)
    for d in days:
        days_per_year[d.year] += 1
    year_partial = {y: n < (366 if y % 4 == 0 else 365) for y, n in days_per_year.items()}

    # --- yearly totals per station + top 10 ---
    sy = defaultdict(float)
    for r in daily:
        sy[(r["station_complex_id"], int(r["day"][:4]))] += r["riders"]
    write_csv("station_yearly.csv", [
        {"station_complex_id": sid, "station_complex": names[sid][0], "borough": names[sid][1],
         "year": y, "riders": round(v), "days_with_data": days_per_year[y], "partial": year_partial[y]}
        for (sid, y), v in sorted(sy.items())])
    yearly = {}
    for y in sorted(days_per_year):
        st = sorted(((v, sid) for (sid, yy), v in sy.items() if yy == y), reverse=True)
        yearly[str(y)] = {
            "riders": round(sum(v for v, _ in st)), "days_with_data": days_per_year[y],
            "partial": year_partial[y],
            "top_10_stations": [{"station_complex_id": sid, "station_complex": names[sid][0],
                                 "riders": round(v)} for v, sid in st[:10]]}
    write_json("yearly.json", yearly)

    # --- average riders by day of week (system-wide, per year) ---
    dow = defaultdict(list)
    for d in days:
        dow[(d.year, d.weekday())].append(system_day[str(d)])
    write_json("day_of_week.json", {
        str(y): [{"day": WEEKDAYS[i], "avg_riders": round(sum(dow[(y, i)]) / len(dow[(y, i)])),
                  "days_counted": len(dow[(y, i)])} for i in range(7) if dow[(y, i)]]
        for y in sorted(days_per_year)})

    # --- average riders per hour of day per station, per year ---
    print("Querying hourly sums...")
    hourly = fetch_hourly(args.refresh)
    hr_rows, sys_hour = [], defaultdict(float)
    for r in hourly:
        y, h = int(r["yr"]), int(r["hr"])
        sys_hour[(y, h)] += r["riders"]
        if r["station_complex_id"] in names:
            hr_rows.append({"station_complex_id": r["station_complex_id"],
                            "station_complex": names[r["station_complex_id"]][0], "year": y, "hour": h,
                            "avg_riders": round(r["riders"] / days_per_year[y], 1)})
    hr_rows.sort(key=lambda r: (r["station_complex_id"], r["year"], r["hour"]))
    write_csv("station_hourly_avg.csv", hr_rows)
    hourly_sys = {str(y): [round(sys_hour[(y, h)] / days_per_year[y]) for h in range(24)]
                  for y in sorted(days_per_year)}
    write_json("system_hourly_avg.json", {"note": "average riders per day in each hour (0-23)",
                                          "years": hourly_sys})

    month_hour = defaultdict(lambda: [0.0] * 24)
    for r in fetch_month_hour(args.refresh):
        month_hour[r["month"][:7]][int(r["hr"])] = r["riders"] / month_days[r["month"][:7]]
    write_json("month_hourly_avg.json", {m: {"partial": month_partial[m], "avg_by_hour": [round(x) for x in v]}
                                         for m, v in sorted(month_hour.items())})

    # --- per-station seasonal totals + top 5 per season ---
    ss, season_days = defaultdict(float), defaultdict(int)
    for d in days:
        season_days[season_label(d)] += 1
    for r in daily:
        ss[(r["station_complex_id"], season_label(date.fromisoformat(r["day"])))] += r["riders"]
    season_partial = {s["season"]: s["partial"] for s in seasons}
    write_csv("station_seasonal.csv", [
        {"station_complex_id": sid, "station_complex": names[sid][0], "season": s, "riders": round(v),
         "days_with_data": season_days[s], "partial": season_partial.get(s, True)}
        for (sid, s), v in sorted(ss.items())])
    top5 = {}
    for s in sorted(season_days, key=lambda s: min(d for d in days if season_label(d) == s)):
        st = sorted(((v, sid) for (sid, ss_), v in ss.items() if ss_ == s), reverse=True)[:5]
        top5[s] = {"partial": season_partial.get(s, True), "days_with_data": season_days[s],
                   "top_5": [{"station_complex_id": sid, "station_complex": names[sid][0], "riders": round(v)}
                             for v, sid in st]}
    write_json("seasonal_top5.json", top5)

    print("Finding unusual days and station trends...")
    compute_trends(daily, names, last, args.refresh)
    print("Building map, year-over-year, fare and payment data...")
    compute_extras(daily, names, last, args.refresh)
    compute_calendar(daily)

    if not args.no_charts:
        make_charts(sys_month, month_partial, weekly, seasons, yearly, dow, hourly_sys, month_hour, top5)

    # --- one week, per station ---
    if args.week:
        w = week_start(date.fromisoformat(args.week))
        wk_days_set = {str(w + timedelta(days=i)) for i in range(7)}
        per = defaultdict(float)
        for r in daily:
            if r["day"] in wk_days_set:
                per[r["station_complex_id"]] += r["riders"]
        if not per:
            sys.exit(f"No data for week of {w}")
        ndays = len([d for d in wk_days_set if date.fromisoformat(d) in have])
        result = {"week_start": str(w), "days_with_data": ndays, "partial": ndays < 7,
                  "stations": len(per), "avg_riders_per_station": round(sum(per.values()) / len(per)),
                  "by_station": sorted(({"station_complex_id": k, "station_complex": names[k][0],
                                         "riders": round(v)} for k, v in per.items()),
                                       key=lambda r: -r["riders"])}
        write_json(f"week_{w}.json", result)
        print(f"Week of {w}: avg {result['avg_riders_per_station']:,} riders/station "
              f"across {len(per)} stations{' (PARTIAL)' if result['partial'] else ''}")

    print(f"Avg system riders per complete month: {round(sum(complete) / len(complete)):,}")
    for s in seasons:
        flag = " (partial)" if s["partial"] else ""
        print(f"  {s['season']:<15} avg/week {s['avg_weekly_riders'] or 0:>12,}{flag}")
    print(f"Outputs written to {OUT}")


if __name__ == "__main__":
    main()
