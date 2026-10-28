# Deployment checklist (WordPress plugin)

Written for the live deployment (ScalaHosting shared hosting, SSH access, WP-CLI, system cron available).
Adjust paths for another host. Nothing here needs root.

## Before an upgrade

1. **Back up the plugin folder** (restoring it is the rollback):
   `cd public_html/wp-content/plugins && tar czf ~/backups/mta-ridership-plugin-before-<version>-$(date +%Y%m%d-%H%M).tgz mta-ridership`
2. **Back up the database** if stored data formats change (none of the 1.x upgrades so far change existing
   formats; new settings are only added). `wp db export ~/backups/db-$(date +%F).sql`.
3. Run the tests: `python -m pytest tests` and `php tests/php/run_tests.php` (CI does this on every push).

## Upgrade

1. Copy the plugin files over `wp-content/plugins/mta-ridership/` (or upload `wordpress-plugin/mta-ridership.zip`).
2. `php -l` every changed file, then confirm the version: `wp plugin get mta-ridership --field=version`.
3. Clear the page cache (`wp eval 'rocket_clean_domain();'` with WP Rocket).
4. Changes that alter what visitors see are first shipped behind `?mta_preview=1` (see
   `mta_ridership_preview()` in `includes/seo.php`) and switched on for everyone after review. Bump the version
   number with them: it is the cache-buster for the CSS and JavaScript.

## Scheduling (cron)

Every 15 minutes, so the daily refresh runs without a visitor and retries happen promptly:

```
*/15 * * * * cd /home/mantiere/public_html && /usr/bin/wp cron event run --due-now --quiet
```

Verify: `crontab -l`, `wp cron event list | grep mta` (the daily refresh should be listed), `wp mta-ridership status`.
Without system cron WP-Cron still runs on page visits, less punctually (see `wordpress-plugin/README.md`).

## Smoke tests after an upgrade

- Every page returns 200: `/`, `/mta-ridership/`, `/congestion-pricing/`, `/subway-lines/`, `/subway-lines/b/`,
  `/bus-routes/`, `/bus-routes/m15-sbs/`, and `/data-status/` once it is published.
- `wp mta-ridership status`: every step `current`, no failures.
- Run one quick step under the lock: `wp mta-ridership run lines`.
- In a browser: the station search finds a station, the map plays, the calendar draws, no console errors.
- Settings → MTA Ridership shows the status table.

## What "current" means (expected data freshness)

The MTA publishes with a delay, so "latest MTA data" is never today. Thresholds before a dataset counts as
delayed: subway ridership and congestion pricing 21 days (weekly, 1–2 weeks behind); subway lines and bus
routes 60 days after month end (monthly). Override with the `mta_ridership_max_age` option.
Delayed "(MTA)" = the MTA hasn't published yet; "(our refresh)" = the step keeps failing and retries.

## Monitoring

- Public: `/data-status/` and `/wp-json/mta-ridership/v1/status` (names, states and dates only).
- Admin: Settings → MTA Ridership (errors, durations) and `wp mta-ridership status`.
- Email: set an address under Settings → MTA Ridership; one alert per problem per day when a step fails twice
  in a row or a dataset is delayed by the MTA. Needs the site to be able to send mail (`wp_mail`).

## Rollback

Restore the backup folder from step 1 of "Before an upgrade" and clear the cache. Stored options are
backwards compatible (the old version ignores the newer settings). If a change was only a preview, nothing
needs rolling back: remove `?mta_preview=1` from the address.

## Steps outside the repository

- WordPress admin: Settings → MTA Ridership (alert email, days of history to re-check, optional Socrata token).
- Hosting panel: the cron job above; email must work for alerts.
- Cloudflare (if used): see `server/README-cloudflare.md` in the site's theme repository for the firewall rules
  and the origin lock, which must be removed before Cloudflare is paused or removed.
