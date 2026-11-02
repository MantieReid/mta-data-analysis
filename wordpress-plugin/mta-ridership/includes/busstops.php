<?php
/**
 * Bus stop heat map on each bus route page: how many people get on at every stop, hour by hour, on an average
 * weekday, Saturday and Sunday, in each direction.
 * Data (data.ny.gov): Bus Stop Ridership Estimates (fvdm-uavx), from passenger counters above the bus doors;
 * buses without counters are filled in by the MTA from similar trips. Stop names: Bus Stops (2ucp-7wg5).
 *
 * - One small file per route, uploads/mta-busstops/<slug>.json, loaded by the page only when the chart is
 *   near the screen (assets/mta-buses.js).
 * - Re-built only when the MTA posts a new month. One query per route (~1-3 s), so a build runs in batches of
 *   ~4 minutes, each picking up where the last one stopped, instead of one long run.
 * Live since 2026-11-02; mta_busstops_live() switches it off again if needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_BUSSTOPS_HOOK   = 'mta_ridership_busstops_step';
const MTA_BUSSTOPS_OPTION = 'mta_ridership_busstops';

add_action(MTA_BUSSTOPS_HOOK, function () { mta_ridership_run_step('busstops'); });

add_action('init', function () {
    if (!get_option(MTA_BUSSTOPS_OPTION) && get_option(MTA_BUSES_OPTION)) {
        mta_busstops_schedule(60);
    }
});

/** Live for everyone since 2026-11-02 (was shown only with ?mta_preview=1 while in review). */
function mta_busstops_live()
{
    return true;
}

function mta_busstops_schedule($delay)
{
    if (!wp_next_scheduled(MTA_BUSSTOPS_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_BUSSTOPS_HOOK);
    }
}

/** Where a route's file lives: [path on disk, URL]. */
function mta_busstops_file($slug)
{
    $u = wp_upload_dir(null, false);
    return [$u['basedir'] . "/mta-busstops/$slug.json", $u['baseurl'] . "/mta-busstops/$slug.json"];
}

/** How many weekdays, Saturdays and Sundays a month ("2026-09") has. */
function mta_busstops_days($month)
{
    $n = ['wk' => 0, 'sa' => 0, 'su' => 0];
    for ($d = "$month-01"; substr($d, 0, 7) === $month; $d = gmdate('Y-m-d', strtotime("$d +1 day UTC"))) {
        $w = (int) gmdate('N', strtotime("$d UTC"));
        $n[$w === 6 ? 'sa' : ($w === 7 ? 'su' : 'wk')]++;
    }
    return $n;
}

/**
 * One route's rows (r, d = direction, s = stop, h = hour, b = wk/sa/su, n = boardings summed over the month,
 * q = stop's lowest position on the route) into the file the page reads:
 * {dirs: [{to: last stop, stops: [[name, {wk: [24 hours], sa: [...], su: [...]}, lat, lon], ...]}]}, stops in route order
 * ($names: stop ID => [name, lat, lon]; a stop without a known place has no lat/lon and is left off the map).
 * Values are people getting on during that hour on an average day of that kind; null = no MTA estimate.
 * A stop can have several positions (limited and local trips); the MTA says the lowest is its true place.
 */
function mta_busstops_build(array $rows, array $names, array $days)
{
    $g = [];
    foreach ($rows as $r) {
        $d = (string) $r['d'];
        $s = (string) $r['s'];
        $q = isset($r['q']) ? (float) $r['q'] : INF;
        if (!isset($g[$d][$s])) {
            $g[$d][$s] = ['q' => $q, 'v' => []];
        }
        $g[$d][$s]['q'] = min($g[$d][$s]['q'], $q);
        if (isset($r['n']) && isset($days[$r['b']]) && $days[$r['b']] > 0) {
            $g[$d][$s]['v'][$r['b']][(int) $r['h'] % 24] = $r['n'] / $days[$r['b']];
        }
    }
    ksort($g, SORT_STRING);
    $dirs = [];
    foreach ($g as $stops) {
        $stops = array_filter($stops, function ($x) { return $x['v'] !== []; }); // stops with no estimate at all
        if (!$stops) {
            continue;
        }
        uksort($stops, function ($a, $b) use ($stops) { return [$stops[$a]['q'], $a] <=> [$stops[$b]['q'], $b]; });
        $list = [];
        foreach ($stops as $s => $x) {
            $hours = [];
            foreach (['wk', 'sa', 'su'] as $b) {
                $hours[$b] = [];
                for ($h = 0; $h < 24; $h++) {
                    $v = $x['v'][$b][$h] ?? null;
                    $hours[$b][] = $v === null ? null : round($v, $v < 10 ? 1 : 0);
                }
            }
            $n = $names[$s] ?? null;
            $list[] = $n && isset($n[1]) ? [$n[0], $hours, $n[1], $n[2]] : [$n[0] ?? ('Stop ' . $s), $hours];
        }
        $dirs[] = ['to' => end($list)[0], 'stops' => $list];
    }
    return ['dirs' => $dirs];
}

/**
 * Bus stops by stop ID: [name the way the bus pages write it ("KNAPP ST/SHORE PKY" => "Knapp St/Shore Pky"), lat, lon].
 * The list keeps every past version of each stop; their places differ by a few metres at most, so take the average.
 */
function mta_busstops_names($api)
{
    $names = [];
    foreach ($api('2ucp-7wg5', ['$select' => 'stop_id, max(stop_name) AS n, avg(latitude) AS lat, avg(longitude) AS lon', '$group' => 'stop_id']) as $r) {
        $names[$r['stop_id']] = isset($r['lat'], $r['lon']) && (float) $r['lat'] != 0
            ? [ucwords(strtolower($r['n']), " /-"), round((float) $r['lat'], 5), round((float) $r['lon'], 5)]
            : [ucwords(strtolower($r['n']), " /-")];
    }
    return $names;
}

/**
 * Daily step: when the MTA has a new month, re-build every route page's file, a batch at a time.
 * Progress is saved after each route, so a cut-off run loses at most one route.
 */
function mta_busstops_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }
    $deadline = time() + 240;
    try {
        $api = function ($id, $params) { return mta_ridership_api("https://data.ny.gov/resource/$id.json", $params + ['$limit' => 50000]); };
        $st = get_option(MTA_BUSSTOPS_OPTION) ?: [];
        $max = $api('fvdm-uavx', ['$select' => 'max(month) AS m']);
        $latest = substr($max[0]['m'] ?? '', 0, 7);
        if (!$latest) {
            throw new Exception('No bus stop estimates returned');
        }
        if (($st['building'] ?? $st['month'] ?? '') !== $latest) {
            $buses = get_option(MTA_BUSES_OPTION);
            if (empty($buses['routes'])) {
                throw new Exception('Bus routes not loaded yet');
            }
            $st['building'] = $latest;
            $st['todo'] = array_keys($buses['routes']);
            $st['done'] = 0;
        }
        if (!empty($st['todo'])) {
            $days = mta_busstops_days($latest);
            $names = mta_busstops_names($api);
            while ($st['todo'] && time() < $deadline) {
                $id = $st['todo'][0];
                $rows = $api('fvdm-uavx', [
                    '$select' => "route_id AS r, direction_id AS d, stop_id AS s, hour AS h, case(weekday = 'Saturday', 'sa', weekday = 'Sunday', 'su', true, 'wk') AS b, " .
                                 'sum(estimated_ridership) AS n, min(stop_sequence::number) AS q',
                    '$where'  => "month = '$latest-01' AND route_id = '" . str_replace("'", "''", $id) . "'",
                    '$group'  => 'r, d, s, h, b',
                ]);
                $data = mta_busstops_build($rows, $names, $days);
                [$path] = mta_busstops_file(mta_buses_slug($id));
                if ($data['dirs']) {
                    wp_mkdir_p(dirname($path));
                    $json = wp_json_encode(['route' => mta_buses_label($id), 'month' => $latest, 'days' => $days] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (!$json || file_put_contents($path, $json) === false) {
                        throw new Exception("Could not write the file for $id");
                    }
                    $st['done']++;
                } elseif (file_exists($path)) {
                    @unlink($path); // no estimates this month: the page shows no heat map rather than an old one
                }
                array_shift($st['todo']);
                update_option(MTA_BUSSTOPS_OPTION, $st, false);
            }
        }
        if (!empty($st['todo'])) {
            wp_schedule_single_event(time() + 60, MTA_BUSSTOPS_HOOK); // next batch
        } elseif (($st['month'] ?? '') !== $latest) {
            $st['month'] = $latest;
            $st['latest'] = $latest; // the status page reads it as a month
            $st['routes'] = $st['done'];
            $st['updated'] = gmdate('c');
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
            }
        }
        $st['checked'] = gmdate('c');
        update_option(MTA_BUSSTOPS_OPTION, $st, false);
        delete_option('mta_ridership_busstops_error');
    } catch (Throwable $e) {
        update_option('mta_ridership_busstops_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_BUSSTOPS_HOOK, 'mta_busstops_schedule');
    }
}

/** The heat map section for a route page, or '' when there's no file for it yet. */
function mta_busstops_section($r, $sec)
{
    $st = get_option(MTA_BUSSTOPS_OPTION);
    [$path, $url] = mta_busstops_file($r['slug']);
    if (!mta_busstops_live() || empty($st['month']) || !file_exists($path)) {
        return '';
    }
    return $sec('Where and when people get on the ' . $r['label'],
        '<p>Each circle on the map is a stop, coloured and sized by how many people get on there in that hour: green for the fewest, yellow in between, ' .
        'red for the most. Press play to watch a day go by, or tap a stop for its numbers. The grid below shows every stop and hour at once.</p><div data-slot="stopheat" data-src="' . esc_url($url . '?v=' . $st['month']) . '"></div>',
        'MTA estimates for ' . esc_html(mta_lines_month($st['month'])) . ', people getting on during each hour on an average day. ' .
        'They come from passenger counters above the bus doors; the MTA fills in buses without counters from similar trips. They show how busy ' .
        'each stop is, not exact counts, and won\'t add up to the route\'s riders a day (paid entries). Grey: no estimate for that hour.');
}
