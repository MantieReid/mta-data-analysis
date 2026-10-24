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

Usage:
  python mta_ridership.py                 # full run
  python mta_ridership.py --week 2026-03-09
  set SOCRATA_APP_TOKEN=...               # optional, avoids throttling
"""
import argparse
import csv
import json
import os
import sys
import time
import urllib.parse
import urllib.request
from collections import defaultdict
from datetime import date, datetime, timedelta

API = "https://data.ny.gov/resource/5wq4-mkjj.json"
PAGE = 50000
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "output")
CACHE = os.path.join(OUT, "daily_station_cache.csv")
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


def fetch_daily(refresh=False):
    """Daily ridership per station, summed by the API."""
    if os.path.exists(CACHE) and not refresh:
        with open(CACHE, newline="", encoding="utf-8") as f:
            return [dict(r, riders=float(r["riders"])) for r in csv.DictReader(f)]
    rows, offset = [], 0
    while True:
        batch = soql({
            "$select": "station_complex_id, station_complex, borough, "
                       "date_trunc_ymd(transit_timestamp) AS day, sum(ridership) AS riders",
            "$where": "transit_mode = 'subway'",
            "$group": "station_complex_id, station_complex, borough, day",
            "$order": "day, station_complex_id",
            "$limit": PAGE, "$offset": offset,
        })
        rows += batch
        print(f"  fetched {len(rows):,} station-days")
        if len(batch) < PAGE:
            break
        offset += PAGE
    out = [{"station_complex_id": r["station_complex_id"], "station_complex": r["station_complex"],
            "borough": r.get("borough", ""), "day": r["day"][:10], "riders": float(r["riders"])}
           for r in rows]
    os.makedirs(OUT, exist_ok=True)
    with open(CACHE, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(out[0]))
        w.writeheader()
        w.writerows(out)
    return out


def days_in_month(y, m):
    nxt = date(y + (m == 12), m % 12 + 1, 1)
    return (nxt - date(y, m, 1)).days


def week_start(d):
    return d - timedelta(days=d.weekday())  # Monday


def season_of(week_mon):
    """Season by the week's Thursday; Dec belongs to the next year's winter."""
    thu = week_mon + timedelta(days=3)
    s = SEASONS[thu.month]
    if s == "winter":
        y = thu.year if thu.month == 12 else thu.year - 1
        return f"winter {y}-{str(y + 1)[2:]}"
    return f"{s} {thu.year}"


def write_csv(name, rows):
    with open(os.path.join(OUT, name), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(rows)


def write_json(name, obj):
    with open(os.path.join(OUT, name), "w", encoding="utf-8") as f:
        json.dump(obj, f, indent=1)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--week", help="any date (YYYY-MM-DD) in the week to report per-station averages for")
    ap.add_argument("--refresh", action="store_true", help="re-query the API instead of using the cache")
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
