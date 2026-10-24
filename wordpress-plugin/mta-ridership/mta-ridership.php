<?php
/**
 * Plugin Name: MTA Subway Ridership
 * Description: Daily-updated NYC subway ridership charts (top stations, monthly, hourly, day of week, seasons) from data.ny.gov. Use the shortcode [mta_ridership].
 * Version: 1.1.1
 * Author: Mantie Reid
 * License: MIT
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
});

add_action(MTA_RIDERSHIP_HOOK, 'mta_ridership_refresh');

/* ---------- data.ny.gov queries (all sums done server-side) ---------- */

function mta_ridership_query(array $params)
{
    $params['$where'] = isset($params['$where'])
        ? MTA_RIDERSHIP_WHERE . ' AND ' . $params['$where']
        : MTA_RIDERSHIP_WHERE;
    $args = ['timeout' => 180];
    $token = get_option('mta_ridership_app_token');
    if ($token) {
        $args['headers'] = ['X-App-Token' => $token];
    }
    $res = wp_remote_get(add_query_arg(array_map('rawurlencode', $params), MTA_RIDERSHIP_API), $args);
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
        // Trends run as a separate cron request so each stays within the host's time limit.
        mta_ridership_schedule_trends(60);
    } catch (Throwable $e) {
        update_option('mta_ridership_last_error', gmdate('c') . ' ' . $e->getMessage(), false);
    }
}

/* ---------- shortcode ---------- */

add_shortcode('mta_ridership', function ($atts) {
    $atts = shortcode_atts(['show' => 'top5,spikes,monthly,hourly,dow,seasons,topstations,quiet,weekend,latenight,transfers'], $atts, 'mta_ridership');
    $data = get_option(MTA_RIDERSHIP_OPTION);
    if (!$data) {
        if (!wp_next_scheduled(MTA_RIDERSHIP_HOOK)) {
            wp_schedule_single_event(time(), MTA_RIDERSHIP_HOOK);
        }
        return '<p class="mta-ridership-empty">Subway ridership data is loading. Check back in a few minutes.</p>';
    }
    $base = plugin_dir_url(__FILE__);
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.1.1');
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_script('mta-ridership', $base . 'assets/mta-ridership.js', ['chartjs'], '1.1.1', true);
    $data['trends'] = get_option(MTA_RIDERSHIP_TRENDS_OPTION) ?: null;
    wp_add_inline_script('mta-ridership', 'window.MTA_RIDERSHIP = ' . wp_json_encode($data) . ';', 'before');

    $show = array_map('trim', explode(',', $atts['show']));
    return '<div class="mta-ridership" data-show="' . esc_attr(implode(',', $show)) . '"></div>';
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
    if (!empty($_POST['refresh_now'])) {
        mta_ridership_refresh();
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
            <code>[mta_ridership show="top5,monthly"]</code> (options: top5, spikes, monthly, hourly, dow, seasons, topstations, quiet, weekend, latenight, transfers).</p>
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
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="mta_ridership_save">
            <?php wp_nonce_field('mta_ridership_save'); ?>
            <p><label>Socrata app token (optional, avoids throttling):<br>
                <input type="text" name="app_token" class="regular-text" value="<?php echo esc_attr(get_option('mta_ridership_app_token')); ?>"></label></p>
            <p>
                <?php submit_button('Save', 'secondary', 'save', false); ?>
                <?php submit_button('Save and refresh data now (takes up to a few minutes)', 'primary', 'refresh_now', false); ?>
            </p>
        </form>
    </div>
    <?php
}
