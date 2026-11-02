<?php
/**
 * Bus lane blocking map on the Bus Routes page: where and when the cameras on MTA buses catch drivers blocking a bus
 * lane, a bus stop or double parking, by bus stop and hour of the day, over the last 12 full months.
 * Data (data.ny.gov): MTA Bus Automated Camera Enforcement Violations (kh8p-hcbm). Only "violation issued" rows are
 * counted: exempt vehicles (buses, emergency vehicles…), technical problems and missing details are left out.
 *
 * - The cameras ride on buses on some routes only, so more tickets can mean more cameras, not only more blocking.
 * - One small set of queries a month, saved to uploads/mta-buslanes/lanes.json (citywide) and routes/<route>.json
 *   (each route's own cameras only, for its route page).
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

/** The map on each route page is live (since 2026-11-02); return mta_ridership_preview() to put it back in review. */
function mta_buslanes_route_live()
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
 * Pure part, for tests. $stops: rows {s, r, name, lat, lon, k (lane/stop/dbl), n}; $hours: {s, r, h, b (wk/we), n}
 * (r = the route whose bus camera issued the tickets). Returns {routes: [labels], stops: [[lat, lon, name, [route indexes],
 * lane, stop, double], ...] (fewest tickets first, so the worst draw on top), wk/we: [[24 counts], ...] in the same order,
 * by: {label: the same shape for one route, counting only that route's tickets}}.
 */
function mta_buslanes_build(array $stops, array $hours, $min = MTA_BUSLANES_MIN)
{
    $s = [];
    // A camera on a bus that runs two routes ("Q10/Q80") counts on both route pages.
    $labels = function ($r) {
        return array_map(function ($x) { return mta_buses_label(strtoupper(trim($x))); }, array_filter(explode('/', (string) ($r['r'] ?? '')), 'strlen'));
    };
    foreach ($stops as $r) {
        if (!isset($r['s'], $r['lat'], $r['lon'], $r['k']) || !in_array($r['k'], ['lane', 'stop', 'dbl'], true)) {
            continue;
        }
        $id = (string) $r['s'];
        if (!isset($s[$id])) {
            $s[$id] = ['lat' => round((float) $r['lat'], 5), 'lon' => round((float) $r['lon'], 5), 'name' => mta_busspeeds_name($r['name'] ?? ''),
                       'lane' => 0, 'stop' => 0, 'dbl' => 0, 'r' => []];
        }
        $s[$id][$r['k']] += (int) $r['n'];
        foreach ($labels($r) as $l) {
            $s[$id]['r'][$l] = $s[$id]['r'][$l] ?? ['lane' => 0, 'stop' => 0, 'dbl' => 0];
            $s[$id]['r'][$l][$r['k']] += (int) $r['n'];
        }
    }
    $total = function ($x) { return $x['lane'] + $x['stop'] + $x['dbl']; };
    $s = array_filter($s, function ($x) use ($min, $total) { return $total($x) >= $min; });
    $all = [];
    foreach ($s as $x) {
        $all += array_fill_keys(array_keys($x['r']), true);
    }
    $all = array_keys($all);
    sort($all, SORT_NATURAL);
    $index = array_flip($all);
    $h = [];
    $hr = [];
    foreach ($hours as $r) {
        $id = (string) ($r['s'] ?? '');
        $b = $r['b'] ?? '';
        if (!isset($s[$id]) || !in_array($b, ['wk', 'we'], true)) {
            continue;
        }
        $hh = (int) $r['h'] % 24;
        $h[$id][$b][$hh] = ($h[$id][$b][$hh] ?? 0) + (int) $r['n'];
        foreach ($labels($r) as $l) {
            if (isset($s[$id]['r'][$l])) {
                $hr[$l][$id][$b][$hh] = ($hr[$l][$id][$b][$hh] ?? 0) + (int) $r['n'];
            }
        }
    }
    // One map's worth: $pick(id, stop) gives [lane, stop, dbl] for a stop (null to leave it out); $hrs holds its hours.
    $map = function ($pick, $hrs) use ($s, $index) {
        $rows = [];
        foreach ($s as $id => $x) {
            if ($k = $pick($id, $x)) {
                $rows[$id] = $k;
            }
        }
        uksort($rows, function ($a, $b) use ($rows) { return [array_sum($rows[$a]), $a] <=> [array_sum($rows[$b]), $b]; });
        $out = ['stops' => [], 'wk' => [], 'we' => []];
        foreach ($rows as $id => $k) {
            $x = $s[$id];
            $ri = array_map(function ($l) use ($index) { return $index[$l]; }, array_keys($x['r']));
            sort($ri);
            $out['stops'][] = [$x['lat'], $x['lon'], $x['name'], $ri, $k[0], $k[1], $k[2]];
            foreach (['wk', 'we'] as $b) {
                $row = [];
                for ($i = 0; $i < 24; $i++) {
                    $row[] = $hrs[$id][$b][$i] ?? 0;
                }
                $out[$b][] = $row;
            }
        }
        return $out;
    };
    $out = ['routes' => $all] + $map(function ($id, $x) { return [$x['lane'], $x['stop'], $x['dbl']]; }, $h) + ['by' => []];
    foreach ($all as $l) {
        $out['by'][$l] = $map(function ($id, $x) use ($l) {
            return isset($x['r'][$l]) ? [$x['r'][$l]['lane'], $x['r'][$l]['stop'], $x['r'][$l]['dbl']] : null;
        }, $hr[$l] ?? []);
    }
    return $out;
}

/** Where one route's ticket file lives ("M15-SBS" => routes/m15-sbs.json). */
function mta_buslanes_route_file($label)
{
    [$path, $url] = mta_buslanes_file();
    $name = 'routes/' . strtolower($label) . '.json';
    return [dirname($path) . '/' . $name, dirname($url) . '/' . $name];
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
            // Grouped by route too (r = the route whose bus camera issued the ticket), so each route page can show its own.
            $stops = $all(['$select' => "stop_id AS s, bus_route_id AS r, max(stop_name) AS name, avg(bus_stop_latitude) AS lat, avg(bus_stop_longitude) AS lon, " .
                                        "case(violation_type = 'MOBILE BUS LANE', 'lane', violation_type = 'MOBILE BUS STOP', 'stop', true, 'dbl') AS k, count(*) AS n",
                           '$where' => $where, '$group' => 's, r, k', '$order' => 's, r, k']);
            $hours = $all(['$select' => "stop_id AS s, bus_route_id AS r, date_extract_hh(first_occurrence) AS h, " .
                                        "case(date_extract_dow(first_occurrence) = 0, 'we', date_extract_dow(first_occurrence) = 6, 'we', true, 'wk') AS b, count(*) AS n",
                           '$where' => $where, '$group' => 's, r, h, b', '$order' => 's, r, h, b']);
            $data = mta_buslanes_build($stops, $hours);
            if (!$data['stops']) {
                throw new Exception('No stops with tickets');
            }
            $by = $data['by'];
            unset($data['by']);
            [$path] = mta_buslanes_file();
            wp_mkdir_p(dirname($path) . '/routes');
            $head = ['month' => $latest, 'from' => substr($from, 0, 7)];
            $json = wp_json_encode($head + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!$json || file_put_contents($path, $json) === false) {
                throw new Exception('Could not write lanes.json');
            }
            $count = function ($stops) { return array_sum(array_map(function ($x) { return $x[4] + $x[5] + $x[6]; }, $stops)); };
            $per = [];
            foreach ($by as $label => $one) {
                if (!$one['stops']) {
                    continue;
                }
                [$rpath] = mta_buslanes_route_file($label);
                $json = wp_json_encode($head + ['route' => $label, 'routes' => $data['routes']] + $one, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!$json || file_put_contents($rpath, $json) === false) {
                    throw new Exception("Could not write the $label ticket file");
                }
                $per[$label] = $count($one['stops']);
            }
            $st = ['month' => $latest, 'latest' => $latest, 'from' => substr($from, 0, 7), 'stops' => count($data['stops']),
                   'tickets' => $count($data['stops']), 'routes' => $per, 'updated' => gmdate('c')];
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

/** The bus lane map section for the Bus Routes page (or one route's page, with $r), or '' before its file exists. */
function mta_buslanes_section($sec, $r = null)
{
    $st = get_option(MTA_BUSLANES_OPTION);
    [$path, $url] = mta_buslanes_file();
    if (!mta_buslanes_live() || empty($st['month']) || !file_exists($path)) {
        return '';
    }
    $label = $r ? mta_buses_label($r['id']) : '';
    $tickets = $r ? ($st['routes'][$label] ?? 0) : ($st['tickets'] ?? 0);
    if ($r) {
        [$path, $url] = mta_buslanes_route_file($label);
        if (!mta_buslanes_route_live() || !$tickets || !file_exists($path)) {
            return ''; // no bus cameras on this route
        }
    }
    $note = esc_html(number_format($tickets)) . ' tickets issued from ' . esc_html(mta_lines_month($st['from']) . ' to ' . mta_lines_month($st['month'])) .
        ' (MTA Bus Automated Camera Enforcement). Exempt vehicles and rejected cases are left out. ';
    if ($r) {
        return $sec('Where drivers block the ' . esc_html($label) . ', hour by hour',
            '<p>Cameras on ' . esc_html($label) . ' buses ticket drivers who block a bus lane, park at a bus stop or double park on the route. Each circle is a stop, ' .
            'sized and coloured by the tickets these cameras issued nearby in that hour over a year: green for few, red for the most. ' .
            'Press play to watch a day; tap a stop for its numbers.</p>' .
            '<div data-slot="buslanes" data-route="' . esc_attr($label) . '" data-src="' . esc_url($url . '?v=' . $st['month']) . '"></div>',
            $note . 'Only tickets from cameras on ' . esc_html($label) . ' buses are counted; cameras on other routes that share a stop are left out. ' .
            'The cameras ride on some buses only, so more tickets can mean more cameras, not only more blocking.');
    }
    return $sec('Where drivers block NYC buses, hour by hour',
        '<p>Cameras on MTA buses ticket drivers who block a bus lane, park at a bus stop or double park on the route. Each circle is a bus stop, ' .
        'sized and coloured by the tickets issued nearby in that hour over a year: green for few, red for the most. Press play to watch a day; tap a stop for its numbers.</p>' .
        '<div data-slot="buslanes" data-src="' . esc_url($url . '?v=' . $st['month']) . '"></div>',
        $note . 'The cameras ride on buses on some routes only, so more tickets can mean more cameras, not only more blocking.');
}
