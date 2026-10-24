# MTA Subway Ridership WordPress plugin

Shows NYC subway ridership charts on any WordPress page with the shortcode `[mta_ridership]`:

- Top 5 stations on the latest day MTA has published, and over the last 7 days
- Riders per month, by hour of day, by day of week, and by season
- Top stations per year (top 10) and per season (top 5)

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

WP-Cron runs when someone visits the site. On low-traffic sites, set up a real cron job
that calls `wp-cron.php` daily. Many hosts offer this in their control panel.

## Local testing

`docker compose up -d` in this folder starts WordPress at http://localhost:8088 with the
plugin mounted.
