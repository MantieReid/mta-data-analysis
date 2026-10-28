# MTA Subway Ridership WordPress plugin

Shows NYC subway ridership charts on any WordPress page with the shortcode `[mta_ridership]`:

- Top 5 stations on the latest day MTA has published, and over the last 7 days
- Riders per month, by hour of day, by day of week, and by season
- Top stations per year (top 10) and per season (top 5)
- Unusually busy and quiet days per station (vs. its 26-week same-weekday median, adjusted for the whole system)
- Weekend vs weekday stations, late-night share, most transfers

A public read-only copy of the data is at `/wp-json/mta-ridership/v1/data`.

Data comes from data.ny.gov dataset `5wq4-mkjj`. MTA does all the summing; the plugin
downloads only small aggregated results. WP-Cron refreshes them once a day and stores them
in a WordPress option, so visitors never wait on the API.

MTA publishes about 1–2 weeks behind, so "latest day" is the newest date MTA has
released, which the page shows.

## Install

1. Upload `mta-ridership.zip` under Plugins → Add New → Upload Plugin, then activate it.
2. The first data load starts right away. To force it, go to Settings → MTA Ridership and
   click "Save and refresh data now".
3. Add a Shortcode block with `[mta_ridership]` to a page.
   - To show only some sections: `[mta_ridership show="top5,monthly,hourly,dow,seasons,topstations"]`

## Scheduling

The data is refreshed by a chain of steps (refresh → trends → extras → congestion → lines →
buses), each started by WP-Cron after the one before. WP-Cron only runs when someone visits the
site, so on a quiet site the refresh can wait for a visitor. Use a real scheduler if your host has one:

- **System cron with WP-CLI** (best; e.g. SPanel/cPanel "Cron Jobs" or `crontab -e`), every 15 minutes:

  ```
  */15 * * * * cd /path/to/wordpress && wp cron event run --due-now --quiet
  ```

- **No WP-CLI, but cron or an external cron service:** call
  `https://your-site/wp-cron.php?doing_wp_cron` every 15 minutes, and add
  `define('DISABLE_WP_CRON', true);` to `wp-config.php` so visitors don't trigger it as well.
- **Nothing available:** it still works through visitors' page loads, just less punctually.

Check it with `wp cron event list | grep mta` (the daily refresh should be listed) and
`wp mta-ridership status`.

## Reliability and monitoring

- **One step at a time.** Each step takes a lock; if another step is still running, it tries again in
  5 minutes instead of overlapping. A lock older than 30 minutes is treated as a crashed run and taken over.
- **Retries.** A request that times out is tried again once. A failed step retries 15 minutes later,
  up to 4 times a day.
- **Re-checking history.** Each run downloads the last 30 days of station totals again and replaces
  them, so MTA revisions are picked up (Settings → MTA Ridership; 0 turns it off). Trends are only
  rebuilt when something changed, the latest day moved, or the last build is a week old.
- **Status.** Settings → MTA Ridership shows each step: current / delayed / unavailable, the newest MTA
  data date, last success, duration and the last error. "Delayed (MTA)" means our refreshes work but the
  MTA hasn't published newer data; "Delayed (our refresh)" means the step keeps failing. Data counts as
  delayed when it's older than the MTA's usual publishing lag: 21 days for subway ridership and congestion
  pricing (published weekly, 1–2 weeks behind), 60 days after month end for subway line and bus metrics
  (published monthly). Override per step with the `mta_ridership_max_age` option, e.g.
  `wp option update mta_ridership_max_age '{"congestion":30}' --format=json`.
- **Email alerts.** Enter an address under Settings → MTA Ridership. You get one email when a step
  fails twice in a row, and one when a dataset is delayed by the MTA, at most once a day per problem.
  Alerts use `wp_mail()`, so the site must be able to send email.
- **Log.** Each run writes one line to the PHP error log, e.g.
  `[mta-ridership] trends ok in 352.1s, data through 2026-10-14`.

WP-CLI commands:

| Command | What it does |
|---|---|
| `wp mta-ridership status` | Health of each step, plus the last history re-check (new / changed / unchanged / removed) |
| `wp mta-ridership run <step>` | Run one step now (refresh, trends, extras, congestion, lines, buses) |
| `wp mta-ridership rebuild-history [--from=YYYY-MM-DD] [--batch-days=45] [--restart]` | Download the subway history again and replace it, in batches. Safe to stop: running it again continues where it left off. Then run `wp mta-ridership run trends`. |

## Local testing

`docker compose up -d` in this folder starts WordPress at http://localhost:8088 with the
plugin mounted.
