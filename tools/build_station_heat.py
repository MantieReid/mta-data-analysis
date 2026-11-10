"""Hour-by-weekday ridership for every subway station.

Pulls MTA Subway Hourly Ridership (data.ny.gov 5wq4-mkjj), sums riders per station, weekday and hour over the
last 26 full weeks, divides by the 26 days of each weekday, and writes output/station_heat.json:
  {"from": "...", "to": "...", "weeks": 26, "stations": {"<complex id>": [168 ints]}}
Each station has 168 numbers: Monday 0:00-0:59 first, then Monday 1:00 ... Sunday 23:00 (index = weekday*24 + hour,
Monday = 0). Values are average riders entering in that hour on that weekday.
Run: python tools/build_station_heat.py [YYYY-MM-DD last day, default = last full day in the data]
"""
import json, os, sys, time, urllib.parse, urllib.request
from datetime import date, timedelta

API = "https://data.ny.gov/resource/5wq4-mkjj.json"
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "output")
WEEKS = 26


def soql(params):
    req = urllib.request.Request(API + "?" + urllib.parse.urlencode(params))
    token = os.environ.get("SOCRATA_APP_TOKEN")
    if token:
        req.add_header("X-App-Token", token)
    for attempt in range(5):
        try:
            with urllib.request.urlopen(req, timeout=300) as r:
                return json.load(r)
        except Exception as e:
            if attempt == 4:
                raise
            print("retry", attempt + 1, e, file=sys.stderr)
            time.sleep(5 * (attempt + 1))


if len(sys.argv) > 1:
    last = date.fromisoformat(sys.argv[1])
else:
    latest = soql({"$select": "max(transit_timestamp) AS m"})[0]["m"][:10]
    last = date.fromisoformat(latest) - timedelta(days=1)  # the newest day may be partial
first = last - timedelta(days=WEEKS * 7 - 1)
where = f"transit_timestamp >= '{first}T00:00:00' AND transit_timestamp < '{last + timedelta(days=1)}T00:00:00'"
print("window", first, "to", last)

from concurrent.futures import ThreadPoolExecutor

ids = sorted({r["station_complex_id"] for r in soql({"$select": "station_complex_id", "$group": "station_complex_id", "$limit": "1000",
                                                      "$where": f"transit_timestamp >= '{last}T00:00:00' AND transit_timestamp < '{last + timedelta(days=1)}T00:00:00'"})})
print("stations", len(ids))


def one(sid):
    return soql({"$select": "station_complex_id,date_extract_dow(transit_timestamp) AS dow,date_extract_hh(transit_timestamp) AS hr,sum(ridership) AS r",
                 "$where": f"station_complex_id='{sid}' AND " + where, "$group": "station_complex_id,dow,hr", "$limit": "500"})


rows = []
with ThreadPoolExecutor(4) as ex:
    for i, part in enumerate(ex.map(one, ids)):
        rows += part
        if i % 50 == 0:
            print(i, len(rows), flush=True)

stations = {}
for r in rows:
    sid = r["station_complex_id"]
    dow = (int(r["dow"]) + 6) % 7  # Socrata: 0 = Sunday. Here Monday = 0.
    a = stations.setdefault(sid, [0] * 168)
    a[dow * 24 + int(r["hr"])] = round(float(r["r"]) / WEEKS)
os.makedirs(OUT, exist_ok=True)
with open(os.path.join(OUT, "station_heat.json"), "w") as f:
    json.dump({"from": str(first), "to": str(last), "weeks": WEEKS, "stations": stations}, f, separators=(",", ":"))
tot = sum(sum(v) for v in stations.values())
print("stations", len(stations), "system riders a day (avg)", round(tot / 7))
