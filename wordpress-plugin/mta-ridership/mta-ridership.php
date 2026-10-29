<?php
/**
 * Plugin Name: MTA Subway Ridership
 * Description: Daily-updated NYC subway ridership charts (top stations, monthly, hourly, day of week, seasons) from data.ny.gov. Use the shortcode [mta_ridership].
 * Version: 1.16.0
 * Author: Mantie Reid
 * License: CC BY 4.0
 * License URI: https://creativecommons.org/licenses/by/4.0/
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_RIDERSHIP_API    = 'https://data.ny.gov/resource/5wq4-mkjj.json';
const MTA_RIDERSHIP_OPTION = 'mta_ridership_data';
const MTA_RIDERSHIP_HOOK   = 'mta_ridership_daily_refresh';
const MTA_RIDERSHIP_WHERE  = "transit_mode = 'subway'";

require_once __DIR__ . '/includes/trends.php';
require_once __DIR__ . '/includes/extras.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/calendar.php';
require_once __DIR__ . '/includes/congestion.php';
require_once __DIR__ . '/includes/lines.php';
require_once __DIR__ . '/includes/buses.php';
require_once __DIR__ . '/includes/stations.php';
require_once __DIR__ . '/includes/status.php';
require_once __DIR__ . '/includes/chunks.php';
require_once __DIR__ . '/includes/datastatus.php';

/* ---------- scheduling ---------- */

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled(MTA_RIDERSHIP_HOOK)) {
        // 10:00 UTC daily (~6 AM New York); MTA publishes with a lag, so time of day isn't critical.
        wp_schedule_event(strtotime('tomorrow 10:00 UTC'), 'daily', MTA_RIDERSHIP_HOOK);
    }
    wp_schedule_single_event(time() + 5, MTA_RIDERSHIP_HOOK); // first fill right away
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook(MTA_RIDERSHIP_HOOK);
    wp_clear_scheduled_hook(MTA_RIDERSHIP_TRENDS_HOOK);
    wp_clear_scheduled_hook(MTA_RIDERSHIP_EXTRAS_HOOK);
    wp_clear_scheduled_hook(MTA_RIDERSHIP_CALENDAR_HOOK);
    wp_clear_scheduled_hook(MTA_CONGESTION_HOOK);
    wp_clear_scheduled_hook(MTA_LINES_HOOK);
    wp_clear_scheduled_hook(MTA_BUSES_HOOK);
});

add_action(MTA_RIDERSHIP_HOOK, function () { mta_ridership_run_step('refresh'); });

/* ---------- data.ny.gov queries (all sums done server-side) ---------- */

function mta_ridership_query(array $params)
{
    $params['$where'] = isset($params['$where'])
        ? MTA_RIDERSHIP_WHERE . ' AND ' . $params['$where']
        : MTA_RIDERSHIP_WHERE;
    return mta_ridership_api(MTA_RIDERSHIP_API, $params);
}

/** Any data.ny.gov dataset: GET with SoQL parameters, decoded JSON back. */
function mta_ridership_api($url, array $params)
{
    $args = ['timeout' => 180];
    $token = get_option('mta_ridership_app_token');
    if ($token) {
        $args['headers'] = ['X-App-Token' => $token];
    }
    $res = wp_remote_get(add_query_arg(array_map('rawurlencode', $params), $url), $args);
    if (is_wp_error($res) && strpos($res->get_error_message(), 'timed out') !== false) {
        $res = wp_remote_get(add_query_arg(array_map('rawurlencode', $params), $url), $args); // the API is sometimes slow: try once more
    }
    if (is_wp_error($res)) {
        throw new Exception($res->get_error_message());
    }
    if (wp_remote_retrieve_response_code($res) !== 200) {
        throw new Exception('HTTP ' . wp_remote_retrieve_response_code($res) . ': ' . substr(wp_remote_retrieve_body($res), 0, 300));
    }
    return json_decode(wp_remote_retrieve_body($res), true);
}

function mta_ridership_top_stations($from, $to_exclusive, $limit)
{
    $rows = mta_ridership_query([
        '$select' => 'station_complex_id, station_complex, borough, sum(ridership) AS riders',
        '$where'  => "transit_timestamp >= '{$from}' AND transit_timestamp < '{$to_exclusive}'",
        '$group'  => 'station_complex_id, station_complex, borough',
        '$order'  => 'riders DESC',
        '$limit'  => $limit,
    ]);
    return array_map(function ($r) {
        return [
            'id'      => $r['station_complex_id'],
            'name'    => $r['station_complex'],
            'borough' => $r['borough'] ?? '',
            'riders'  => (int) round($r['riders']),
        ];
    }, $rows);
}

function mta_ridership_season($ymd)
{
    $y = (int) substr($ymd, 0, 4);
    $m = (int) substr($ymd, 5, 2);
    if ($m === 12 || $m <= 2) {
        $start = $m === 12 ? $y : $y - 1;
        return 'Winter ' . $start . '-' . substr((string) ($start + 1), 2);
    }
    if ($m <= 5) return "Spring $y";
    if ($m <= 8) return "Summer $y";
    return "Fall $y";
}

function mta_ridership_next_day($ymd, $n = 1)
{
    return gmdate('Y-m-d', strtotime("$ymd +$n day UTC"));
}

/** Fetch everything and store it. Keeps the previous data if anything fails. */
function mta_ridership_refresh()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600); // ~15 API calls; the hourly one can take a while
    }
    try {
        // System-wide total per day (~1 row per day).
        $daily = [];
        foreach (mta_ridership_query([
            '$select' => 'date_trunc_ymd(transit_timestamp) AS day, sum(ridership) AS riders',
            '$group'  => 'day', '$order' => 'day', '$limit' => 50000,
        ]) as $r) {
            $daily[substr($r['day'], 0, 10)] = (float) $r['riders'];
        }
        if (!$daily) {
            throw new Exception('No daily data returned');
        }
        $days   = array_keys($daily);
        $first  = $days[0];
        $latest = end($days);

        // Monthly totals + partial flag.
        $months = [];
        foreach ($daily as $d => $v) {
            $m = substr($d, 0, 7);
            $months[$m]['riders'] = ($months[$m]['riders'] ?? 0) + $v;
            $months[$m]['days']   = ($months[$m]['days'] ?? 0) + 1;
        }
        $monthly = [];
        foreach ($months as $m => $info) {
            $dim = (int) gmdate('t', strtotime("$m-01 UTC"));
            $monthly[] = ['month' => $m, 'riders' => (int) round($info['riders']),
                          'days' => $info['days'], 'partial' => $info['days'] < $dim];
        }

        // Day-of-week averages per year.
        $dow = [];
        foreach ($daily as $d => $v) {
            $y = substr($d, 0, 4);
            $w = (int) gmdate('N', strtotime("$d UTC")) - 1; // 0 = Mon
            $dow[$y][$w][] = $v;
        }
        $day_of_week = [];
        foreach ($dow as $y => $by) {
            for ($i = 0; $i < 7; $i++) {
                $vals = $by[$i] ?? [];
                $day_of_week[$y][] = $vals ? (int) round(array_sum($vals) / count($vals)) : null;
            }
        }

        // Weeks (Mon-Sun) -> season by the week's Thursday; average over complete weeks.
        $weeks = [];
        foreach ($daily as $d => $v) {
            $mon = gmdate('Y-m-d', strtotime("$d -" . (gmdate('N', strtotime("$d UTC")) - 1) . " day UTC"));
            $weeks[$mon]['riders'] = ($weeks[$mon]['riders'] ?? 0) + $v;
            $weeks[$mon]['days']   = ($weeks[$mon]['days'] ?? 0) + 1;
        }
        $season_weeks = [];
        foreach ($weeks as $mon => $w) {
            $s = mta_ridership_season(mta_ridership_next_day($mon, 3));
            $season_weeks[$s][] = $w;
        }
        $first_season  = mta_ridership_season($first);
        $latest_season = mta_ridership_season($latest);
        $last_season_day = mta_ridership_season(mta_ridership_next_day($latest));
        $seasons = [];
        foreach ($season_weeks as $s => $ws) {
            $full = array_filter($ws, function ($w) { return $w['days'] === 7; });
            $seasons[] = [
                'season'  => $s,
                'avg_weekly_riders' => $full ? (int) round(array_sum(array_column($full, 'riders')) / count($full)) : null,
                'complete_weeks' => count($full),
                // partial if the data starts after the season began or the season isn't over yet
                'partial' => ($s === $first_season && mta_ridership_season(mta_ridership_next_day($first, -1)) === $s)
                             || ($s === $latest_season && $last_season_day === $s),
            ];
        }

        // Average riders per hour of day, per year.
        $year_days = [];
        foreach ($days as $d) {
            $year_days[substr($d, 0, 4)] = ($year_days[substr($d, 0, 4)] ?? 0) + 1;
        }
        $hourly = [];
        foreach (mta_ridership_query([
            '$select' => 'date_extract_y(transit_timestamp) AS yr, date_extract_hh(transit_timestamp) AS hr, sum(ridership) AS riders',
            '$group'  => 'yr, hr', '$limit' => 1000,
        ]) as $r) {
            $y = (string) $r['yr'];
            if (!isset($hourly[$y])) {
                $hourly[$y] = array_fill(0, 24, 0);
            }
            $hourly[$y][(int) $r['hr']] = (int) round($r['riders'] / max(1, $year_days[$y] ?? 1));
        }
        ksort($hourly);

        // Top stations: latest day, last 7 days, each year, each season.
        $after_latest = mta_ridership_next_day($latest);
        $week_from    = mta_ridership_next_day($latest, -6);
        $top_years = [];
        foreach (array_keys($year_days) as $y) {
            $top_years[$y] = [
                'partial'  => $year_days[$y] < (int) gmdate('z', strtotime("$y-12-31 UTC")) + 1,
                'stations' => mta_ridership_top_stations("$y-01-01", ($y + 1) . '-01-01', 10),
            ];
        }
        $season_ranges = [];
        foreach ($days as $d) {
            $s = mta_ridership_season($d);
            if (!isset($season_ranges[$s])) {
                $season_ranges[$s] = [$d, $d];
            }
            $season_ranges[$s][1] = $d;
        }
        $partial_by_season = array_column($seasons, 'partial', 'season');
        $top_seasons = [];
        foreach ($season_ranges as $s => [$from, $to]) {
            $top_seasons[$s] = [
                'partial'  => $partial_by_season[$s] ?? true,
                'stations' => mta_ridership_top_stations($from, mta_ridership_next_day($to), 5),
            ];
        }

        update_option(MTA_RIDERSHIP_OPTION, [
            'updated'       => gmdate('c'),
            'first_day'     => $first,
            'latest_day'    => $latest,
            'latest_total'  => (int) round($daily[$latest]),
            'top_latest'    => mta_ridership_top_stations($latest, $after_latest, 5),
            'week_from'     => $week_from,
            'top_week'      => mta_ridership_top_stations($week_from, $after_latest, 5),
            'monthly'       => $monthly,
            'day_of_week'   => $day_of_week,
            'hourly'        => $hourly,
            'seasons'       => $seasons,
            'top_years'     => $top_years,
            'top_seasons'   => $top_seasons,
        ], false);
        delete_option('mta_ridership_last_error');
        mta_ridership_clear_page_cache();
        // Trends run as a separate cron request so each stays within the host's time limit.
        mta_ridership_schedule_trends(60);
    } catch (Throwable $e) {
        update_option('mta_ridership_last_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_RIDERSHIP_HOOK, function ($d) { wp_schedule_single_event(time() + $d, MTA_RIDERSHIP_HOOK); });
    }
}

/**
 * The page embeds the data in its HTML, so a page cache keeps showing yesterday's numbers.
 * After new data is saved, clear the cached copy of every published page using the shortcode.
 * Only WP Rocket is handled; without it this does nothing.
 */
function mta_ridership_clear_page_cache()
{
    if (!function_exists('rocket_clean_post')) {
        return;
    }
    $ids = get_posts([
        'post_type' => 'any', 'post_status' => 'publish', 'fields' => 'ids',
        'posts_per_page' => 50, 's' => '[mta_ridership', 'no_found_rows' => true,
    ]);
    foreach ($ids as $id) {
        if (has_shortcode(get_post_field('post_content', $id), 'mta_ridership')) {
            rocket_clean_post($id);
        }
    }
}

/* ---------- shortcode ---------- */

add_shortcode('mta_ridership', function ($atts) {
    $atts = shortcode_atts(['show' => 'hero,map,top5,yoy,spikes,monthly,hourly,dow,seasons,holidays,topstations,quiet,weekend,latenight,transfers,fares,payment'], $atts, 'mta_ridership');
    $data = get_option(MTA_RIDERSHIP_OPTION);
    if (!$data) {
        if (!wp_next_scheduled(MTA_RIDERSHIP_HOOK)) {
            wp_schedule_single_event(time(), MTA_RIDERSHIP_HOOK);
        }
        return '<p class="mta-ridership-empty">Subway ridership data is loading. Check back in a few minutes.</p>';
    }
    $show = array_map('trim', explode(',', $atts['show']));
    if (true) { // live: "Find your station", key findings, MetroCard, FAQ, let's talk; mta_ridership_preview() hides changes behind ?mta_preview=1
        $at = function ($after, $k) use (&$show) {
            $i = array_search($after, $show, true);
            array_splice($show, $i === false ? count($show) : $i + 1, 0, [$k]);
        };
        $at('hero', 'lookup');
        $at('top5', 'findings');
        $at('fares', 'metrocard'); // just above the OMNY vs MetroCard chart
        $show[] = 'faq';
        $show[] = 'talk';
    }
    if (true) { // live: links to the other transit data pages
        array_splice($show, (int) array_search('talk', $show, true) ?: count($show), 0, ['more']);
    }
    $base = plugin_dir_url(__FILE__);
    $deps = ['chartjs'];
    if (in_array('map', $show, true)) { // Leaflet only when the map is on the page
        wp_enqueue_style('leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css', [], '1.9.4');
        wp_enqueue_script('leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js', [], '1.9.4', true);
        $deps[] = 'leaflet';
    }
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.16.0');
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_script('mta-ridership', $base . 'assets/mta-ridership.js', $deps, '1.16.0', true);
    $data['trends'] = get_option(MTA_RIDERSHIP_TRENDS_OPTION) ?: null;
    $data['extras'] = get_option(MTA_RIDERSHIP_EXTRAS_OPTION) ?: null;
    if ($data['extras'] && !in_array('lookup', $show, true)) {
        unset($data['extras']['lookup']); // only send the station list when the lookup is on the page
    }
    if (in_array('holidays', $show, true)) {
        $data['calendar'] = get_option(MTA_RIDERSHIP_CALENDAR_OPTION) ?: null;
    }
    // Live: fetch the big data blocks when they're needed instead of embedding them in the page.
    $payload = mta_ridership_lazy_payload($data, $show);
    wp_add_inline_script('mta-ridership', 'window.MTA_RIDERSHIP = ' . wp_json_encode($payload) . ';', 'before');

    $inner = '';
    if (mta_ridership_seo_enabled()) {
        // One slot per section, in order. The headline and top 5 are written here as plain HTML
        // so search engines can read them; the script fills in the other slots.
        foreach ($show as $k) {
            $ssr = ['hero' => 'mta_ridership_ssr_hero', 'top5' => 'mta_ridership_ssr_top5',
                    'findings' => 'mta_ridership_ssr_findings', 'faq' => 'mta_ridership_ssr_faq',
                    'metrocard' => 'mta_ridership_ssr_metrocard', 'talk' => 'mta_ridership_ssr_talk',
                    'more' => function () { return mta_ridership_ssr_more('ridership'); }];
            $html = isset($ssr[$k]) ? $ssr[$k]($data) : '';
            $inner .= '<div data-slot="' . esc_attr($k) . '"' . ($html ? ' data-ssr' : '') . '>' . $html . '</div>';
        }
        if (true) { // live; mta_ridership_preview() hides it behind ?mta_preview=1
            $inner .= mta_ridership_ssr_source($data); // the script skips its own source line when this exists
        }
    }
    return '<div class="mta-ridership" data-show="' . esc_attr(implode(',', $show)) . '">' . $inner . mta_ridership_disclaimer() . '</div>';
});

/* ---------- admin: Settings > MTA Ridership ---------- */

add_action('admin_menu', function () {
    add_options_page('MTA Ridership', 'MTA Ridership', 'manage_options', 'mta-ridership', 'mta_ridership_admin_page');
});

add_action('admin_post_mta_ridership_save', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Not allowed');
    }
    check_admin_referer('mta_ridership_save');
    update_option('mta_ridership_app_token', sanitize_text_field(wp_unslash($_POST['app_token'] ?? '')));
    $email = sanitize_email(wp_unslash($_POST['alert_email'] ?? ''));
    update_option('mta_ridership_alert_email', is_email($email) ? $email : '', false);
    update_option('mta_ridership_reconcile_days', min(365, max(0, (int) ($_POST['reconcile_days'] ?? 30))), false);
    if (!empty($_POST['refresh_now'])) {
        mta_ridership_run_step('refresh');
    }
    wp_safe_redirect(admin_url('options-general.php?page=mta-ridership&done=1'));
    exit;
});

function mta_ridership_admin_page()
{
    $data  = get_option(MTA_RIDERSHIP_OPTION);
    $error = get_option('mta_ridership_last_error');
    $next  = wp_next_scheduled(MTA_RIDERSHIP_HOOK);
    ?>
    <div class="wrap">
        <h1>MTA Subway Ridership</h1>
        <p>Add <code>[mta_ridership]</code> to any page or post. Limit sections with
            <code>[mta_ridership show="top5,monthly"]</code> (options: hero, lookup, map, top5, findings, yoy, spikes, monthly, hourly, dow, seasons, holidays, topstations, quiet, weekend, latenight, transfers, fares, metrocard, payment, faq, talk).</p>
        <p>Trends (unusual days, weekend/late-night/transfer stations):
            <?php echo esc_html(mta_ridership_trends_status()); ?></p>
        <table class="form-table">
            <tr><th>Last updated</th><td><?php echo $data ? esc_html($data['updated']) : 'Never'; ?></td></tr>
            <tr><th>Latest MTA data</th><td><?php echo $data ? esc_html($data['latest_day']) : '–'; ?></td></tr>
            <tr><th>Next scheduled refresh</th><td><?php echo $next ? esc_html(gmdate('c', $next)) : 'Not scheduled'; ?></td></tr>
            <?php if ($error) : ?>
                <tr><th>Last error</th><td style="color:#b32d2e"><?php echo esc_html($error); ?></td></tr>
            <?php endif; ?>
        </table>
        <h2>Data steps</h2>
        <p>"Data through" is the newest day the MTA has published. Delayed means older than the usual publishing
            lag for that dataset; "MTA" means our refreshes work and the MTA hasn't published newer data yet,
            "our refresh" means the step itself keeps failing.</p>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th>Step</th><th>State</th><th>Data through</th><th>Last success (UTC)</th><th>Last run</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach (mta_ridership_health() as $h) :
                $color = ['current' => '#1a7f37', 'delayed' => '#9a6700', 'unavailable' => '#b32d2e'][$h['state']]; ?>
                <tr>
                    <td><?php echo esc_html($h['label']); ?></td>
                    <td style="color:<?php echo esc_attr($color); ?>;font-weight:600"><?php echo esc_html(ucfirst($h['state']) .
                        ($h['state'] === 'delayed' ? ($h['reason'] === 'source' ? ' (MTA)' : ' (our refresh)') : '')); ?></td>
                    <td><?php echo esc_html(($h['through'] ?: '–') . ($h['age'] !== null ? " ({$h['age']} days ago, usual max {$h['max_age']})" : '')); ?></td>
                    <td><?php echo esc_html($h['last_success'] ?: '–'); ?></td>
                    <td><?php echo esc_html($h['seconds'] !== null ? $h['seconds'] . 's' . ($h['failures'] ? ", {$h['failures']} failure(s) in a row" : '') : '–'); ?></td>
                    <td style="color:#b32d2e"><?php echo esc_html($h['error']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="mta_ridership_save">
            <?php wp_nonce_field('mta_ridership_save'); ?>
            <p><label>Socrata app token (optional, avoids throttling):<br>
                <input type="text" name="app_token" class="regular-text" value="<?php echo esc_attr(get_option('mta_ridership_app_token')); ?>"></label></p>
            <p><label>Email alerts to (leave empty for no alerts; at most one email per problem per day):<br>
                <input type="email" name="alert_email" class="regular-text" value="<?php echo esc_attr(get_option('mta_ridership_alert_email')); ?>"></label></p>
            <p><label>Re-check the last <input type="number" name="reconcile_days" min="0" max="365" style="width:5em"
                value="<?php echo esc_attr(mta_ridership_reconcile_days()); ?>"> days of subway history on every run (picks up MTA revisions; 0 = off)</label></p>
            <p>
                <?php submit_button('Save', 'secondary', 'save', false); ?>
                <?php submit_button('Save and refresh data now (takes up to a few minutes)', 'primary', 'refresh_now', false); ?>
            </p>
        </form>
    </div>
    <?php
}
