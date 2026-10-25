# MTA Data Analysis

NYC subway ridership for 2025 and 2026 to date, from MTA's open data. It includes:

- `mta_ridership.py`: a Python script that writes small JSON/CSV summaries and PNG charts.
- `wordpress-plugin/`: a WordPress plugin that shows the same trends on a web page and refreshes them daily.

Source: [MTA Subway Hourly Ridership: Beginning 2025](https://data.ny.gov/d/5wq4-mkjj)
(data.ny.gov dataset `5wq4-mkjj`). Neither tool downloads the full CSV (40M+ rows). They
ask the data.ny.gov API to add up the rows first and download only the totals.

## Reproduce the results

Requirements: Python 3.9+ and an internet connection. No account or API key is needed.

```bash
git clone https://github.com/MantieReid/mta-data-analysis.git
cd mta-data-analysis
pip install -r requirements.txt
python mta_ridership.py
```

This writes everything to `output/`. A first run takes a few minutes. After that, the
downloaded totals are cached in `output/*_cache.csv` (not committed); use `--refresh` to
download them again.

Options:

| Flag | What it does |
|---|---|
| `--week YYYY-MM-DD` | Also writes `output/week_<monday>.json`: riders per station for that week |
| `--refresh` | Ignore the cache and query the API again |
| `--no-charts` | Skip the PNG charts |

Optional: set the `SOCRATA_APP_TOKEN` environment variable to a free
[Socrata app token](https://data.ny.gov/profile/edit/developer_settings) to avoid throttling.

### Will I get exactly the same numbers?

The committed `output/` files are a snapshot of data **through Oct 14, 2026**.
`system_monthly.json` records the date the data runs through. If you run the script later,
your numbers will differ:

- MTA adds new days, usually 1–2 weeks behind.
- MTA occasionally revises past data.

To compare like for like, look at periods that were complete in both runs. Rows marked
`"partial": true` (months, weeks, seasons and years with missing days) will change.

## How the numbers are calculated

- **Riders**: the `ridership` column (entries into the station), `transit_mode = 'subway'`,
  grouped by `station_complex_id`.
- **Weeks** run Monday–Sunday. A week belongs to the season of its Thursday.
- **Seasons**: Winter = Dec–Feb (December counts toward the following winter, so Dec 2025 is
  in "winter 2025-26"), Spring = Mar–May, Summer = Jun–Aug, Fall = Sep–Nov. Seasonal averages
  use complete weeks only.
- **Partial periods**: a month, week, season or year is partial if the data doesn't cover every
  day in it.
- **Hourly averages**: riders in each hour of the day ÷ the number of days with data in that year.

Station trends (the script and the WordPress plugin use the same method and get identical results):

- **Unusually busy/quiet days**: a station's riders that day compared with its *normal* level,
  which is the median of the same weekday over the previous 26 weeks (at least 10 of them).
  The comparison then divides by the whole system's own ratio that day, so holidays and storms
  that affect every station don't count. "Busy" means at least 3× normal. "Quiet" means 35% of
  normal or less. Stations normally under 1,500 riders/day (3,000 for quiet days) are skipped,
  and back-to-back days are grouped into one event.
- **Weekend vs weekday**: average daily riders over the last 364 days, skipping days a station
  was nearly closed (under 20% of its median).
- **Late-night share**: riders between midnight and 5 AM ÷ all riders, last 365 days.
- **Transfers**: the dataset's `transfers` column, last 365 days.

## Outputs

| File | Contents |
|---|---|
| `station_monthly.csv` | Riders per station per month, with a `partial` flag |
| `station_month_avg.csv` | Average riders per month per station (complete months only) |
| `system_monthly.json` | System-wide monthly totals and the average month |
| `weekly.csv` / `weekly.json` | Weekly totals and average riders per station |
| `seasons.json` | Average weekly riders per season |
| `station_yearly.csv`, `yearly.json` | Yearly totals per station, top 10 per year |
| `day_of_week.json` | Average daily riders per weekday, per year |
| `station_hourly_avg.csv`, `system_hourly_avg.json`, `month_hourly_avg.json` | Average riders per hour of day |
| `station_seasonal.csv`, `seasonal_top5.json` | Season totals per station, top 5 per season |
| `top_stations_latest.json` | Top 5 stations on the latest day of data and over the last 7 days |
| `unusual_days.json` | Unusually busy and quiet days per station: the last 14 days and the biggest per year, each with its daily riders around the day |
| `weekend_vs_weekday.json` | Stations busiest on weekends vs mostly weekday, last 364 days |
| `late_night.json` | Stations with the largest share of riders between midnight and 5 AM, last 365 days |
| `transfers.json` | Stations with the most transfers, last 365 days |
| `charts/*.png` | Charts of the above |

## WordPress plugin

See [`wordpress-plugin/README.md`](wordpress-plugin/README.md). In short:

1. Upload `wordpress-plugin/mta-ridership.zip` under Plugins → Add New → Upload Plugin.
2. Activate it.
3. Add `[mta_ridership]` to a page.

## License

Code: [MIT](LICENSE). Data: published by the MTA on [data.ny.gov](https://data.ny.gov/d/5wq4-mkjj),
subject to data.ny.gov's terms of use.
