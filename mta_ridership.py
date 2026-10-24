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
  charts/*.png            matplotlib charts (skip with --no-charts)

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


def cached_query(cache, select, group, label, refresh=False):
    """Run a grouped SoQL query (paged) and cache the small result as CSV."""
    if os.path.exists(cache) and not refresh:
        with open(cache, newline="", encoding="utf-8") as f:
            return [dict(r, riders=float(r["riders"])) for r in csv.DictReader(f)]
    rows, offset = [], 0
    while True:
        batch = soql({"$select": select + ", sum(ridership) AS riders",
                      "$where": "transit_mode = 'subway'",
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
