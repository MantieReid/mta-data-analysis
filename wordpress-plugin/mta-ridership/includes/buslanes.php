<?php
/**
 * Bus lane blocking map on the Bus Routes page: where and when the cameras on MTA buses catch drivers blocking a bus
 * lane, a bus stop or double parking, by bus stop and hour of the day, over the last 12 full months.
 * Data (data.ny.gov): MTA Bus Automated Camera Enforcement Violations (kh8p-hcbm). Only "violation issued" rows are
 * counted: exempt vehicles (buses, emergency vehicles…), technical problems and missing details are left out.
 *
 * - The cameras ride on buses on some routes only, so more tickets can mean more cameras, not only more blocking.
 * - One small set of queries a month (~10-30 s in all), saved to uploads/mta-buslanes/lanes.json.
 * Live since 2026-11-02; mta_buslanes_live() switches it off again if needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_BUSLANES_HOOK   = 'mta_ridership_buslanes_step';
const MTA_BUSLANES_OPTION = 'mta_ridership_buslanes';
const MTA_BUSLANES_MIN    = 10; // stops with fewer tickets a year are left off the map

add_action(MTA_BUSLANES_HOOK, function () { mta_ridership_run_step('buslanes'); });

add_action('init', function () {
    if (!get_option(MTA_BUSLANES_OPTION) && get_option(MTA_BUSES_OPTION)) {
        mta_buslanes_schedule(240);
    }
});

/** The bus lane map is live (since 2026-11-02); return mta_ridership_preview() to put it back in review. */
function mta_buslanes_live()
{
    return true;
}

function mta_buslanes_schedule($delay)
{
    if (!wp_next_scheduled(MTA_BUSLANES_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_BUSLANES_HOOK);
    }
}

function mta_buslanes_file()
{
    $u = wp_upload_dir(null, false);
    return [$u['basedir'] . '/mta-buslanes/lanes.json', $u['baseurl'] . '/mta-buslanes/lanes.json'];
}

/**
 * Pure part, for tests. $stops: rows {s, name, lat, lon, k (lane/stop/dbl), n}; $hours: {s, h, b (wk/we), n};
 * $routes: {s, r}. Returns {routes: [labels], stops: [[lat, lon, name, [route indexes], lane, stop, double], ...]
 * (fewest tickets first, so the worst draw on top), wk/we: [[24 counts], ...] in the same order}.
 */
function mta_buslanes_build(array $stops, array $hours, array $routes, $min = MTA_BUSLANES_MIN)
{
    $s = [];
    foreach ($stops as $r) {
        if (!isset($r['s'], $r['lat'], $r['lon'], $r['k'])) {
            continue;
        }
        $id = (string) $r['s'];
        if (!isset($s[$id])) {
            $s[$id] = ['lat' => round((float) $r['lat'], 5), 'lon' => round((float) $r['lon'], 5), 'name' => mta_busspeeds_name($r['name'] ?? ''),
                       'lane' => 0, 'stop' => 0, 'dbl' => 0, 'r' => []];
        }
        if (isset($s[$id][$r['k']])) {
            $s[$id][$r['k']] += (int) $r['n'];
        }
    }
    $s = array_filter($s, function ($x) use ($min) { return $x['lane'] + $x['stop'] + $x['dbl'] >= $min; });
    $labels = [];
    foreach ($routes as $r) {
        $id = (string) ($r['s'] ?? '');
        if (isset($s[$id], $r['r'])) {
            $label = mta_buses_label(strtoupper($r['r']));
            if (!isset($labels[$label])) {
                $labels[$label] = count($labels);
            }
            $s[$id]['r'][$labels[$label]] = $labels[$label];
        }
    }
    $h = [];
    foreach ($hours as $r) {
        $id = (string) ($r['s'] ?? '');
        if (isset($s[$id]) && in_array($r['b'] ?? '', ['wk', 'we'], true)) {
            $h[$id][$r['b']][(int) $r['h'] % 24] = ($h[$id][$r['b']][(int) $r['h'] % 24] ?? 0) + (int) $r['n'];
        }
    }
    $total = function ($x) { return $x['lane'] + $x['stop'] + $x['dbl']; };
    uksort($s, function ($a, $b) use ($s, $total) { return [$total($s[$a]), $a] <=> [$total($s[$b]), $b]; });
    $out = ['routes' => array_keys($labels), 'stops' => [], 'wk' => [], 'we' => []];
    foreach ($s as $id => $x) {
        $r = array_values($x['r']);
        sort($r);
        $out['stops'][] = [$x['lat'], $x['lon'], $x['name'], $r, $x['lane'], $x['stop'], $x['dbl']];
        foreach (['wk', 'we'] as $b) {
            $row = [];
            for ($i = 0; $i < 24; $i++) {
                $row[] = $h[$id][$b][$i] ?? 0;
            }
            $out[$b][] = $row;
        }
    }
    return $out;
}

/** Monthly step: the last 12 full months of tickets, by stop and hour. */
function mta_buslanes_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }
    try {
        $api = function ($params) { return mta_ridership_api('https://data.ny.gov/resource/kh8p-hcbm.json', $params + ['$limit' => 50000]); };
        $all = function ($params) use ($api) { // every page of a big grouped query
            $rows = [];
            for ($off = 0; ; $off += 50000) {
                $page = $api($params + ['$offset' => $off]);
                $rows = array_merge($rows, $page);
                if (count($page) < 50000) {
                    return $rows;
                }
            }
        };
        $st = get_option(MTA_BUSLANES_OPTION) ?: [];
        $max = $api(['$select' => 'max(first_occurrence) AS m']);
        $last = substr($max[0]['m'] ?? '', 0, 10);
        if (!$last) {
            throw new Exception('No camera tickets returned');
        }
        // The last full month: data through Oct 17 means the year ends with September.
        $to = gmdate('Y-m-01', strtotime(substr($last, 0, 7) . '-01 UTC' . (substr($last, 8, 2) === gmdate('t', strtotime("$last UTC")) ? ' +1 month' : '')));
        $from = gmdate('Y-m-01', strtotime("$to -12 months UTC"));
        $latest = gmdate('Y-m', strtotime("$to -1 month UTC"));
        if (($st['month'] ?? '') !== $latest) {
            $where = "first_occurrence >= '$from' AND first_occurrence < '$to' AND violation_status = 'VIOLATION ISSUED'";
            $stops = $all(['$select' => "stop_id AS s, max(stop_name) AS name, avg(bus_stop_latitude) AS lat, avg(bus_stop_longitude) AS lon, " .
                                        "case(violation_type = 'MOBILE BUS LANE', 'lane', violation_type = 'MOBILE BUS STOP', 'stop', true, 'dbl') AS k, count(*) AS n",
                           '$where' => $where, '$group' => 's, k', '$order' => 's, k']);
            $hours = $all(['$select' => "stop_id AS s, date_extract_hh(first_occurrence) AS h, " .
                                        "case(date_extract_dow(first_occurrence) = 0, 'we', date_extract_dow(first_occurrence) = 6, 'we', true, 'wk') AS b, count(*) AS n",
                           '$where' => $where, '$group' => 's, h, b', '$order' => 's, h, b']);
            $routes = $all(['$select' => 'stop_id AS s, bus_route_id AS r, count(*) AS n', '$where' => $where, '$group' => 's, r', '$order' => 's, r']);
            $data = mta_buslanes_build($stops, $hours, $routes);
            if (!$data['stops']) {
                throw new Exception('No stops with tickets');
            }
            [$path] = mta_buslanes_file();
            wp_mkdir_p(dirname($path));
            $json = wp_json_encode(['month' => $latest, 'from' => substr($from, 0, 7)] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$json || file_put_contents($path, $json) === false) {
                throw new Exception('Could not write lanes.json');
            }
            $st = ['month' => $latest, 'latest' => $latest, 'from' => substr($from, 0, 7), 'stops' => count($data['stops']),
                   'tickets' => array_sum(array_map(function ($x) { return $x[4] + $x[5] + $x[6]; }, $data['stops'])), 'updated' => gmdate('c')];
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
            }
        }
        $st['checked'] = gmdate('c');
        update_option(MTA_BUSLANES_OPTION, $st, false);
        delete_option('mta_ridership_buslanes_error');
    } catch (Throwable $e) {
        update_option('mta_ridership_buslanes_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_BUSLANES_HOOK, 'mta_buslanes_schedule');
    }
}

/** The bus lane map section for the Bus Routes page, or '' before its file exists. */
function mta_buslanes_section($sec)
{
    $st = get_option(MTA_BUSLANES_OPTION);
    [$path, $url] = mta_buslanes_file();
    if (!mta_buslanes_live() || empty($st['month']) || !file_exists($path)) {
        return '';
    }
    return $sec('Where drivers block NYC buses, hour by hour',
        '<p>Cameras on MTA buses ticket drivers who block a bus lane, park at a bus stop or double park on the route. Each circle is a bus stop, ' .
        'sized and coloured by the tickets issued nearby in that hour over a year: green for few, red for the most. Press play to watch a day; tap a stop for its numbers.</p>' .
        '<div data-slot="buslanes" data-src="' . esc_url($url . '?v=' . $st['month']) . '"></div>',
        esc_html(number_format($st['tickets'])) . ' tickets issued from ' . esc_html(mta_lines_month($st['from']) . ' to ' . mta_lines_month($st['month'])) .
        ' (MTA Bus Automated Camera Enforcement). Exempt vehicles and rejected cases are left out. The cameras ride on buses on some routes only, ' .
        'so more tickets can mean more cameras, not only more blocking.');
}
