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
const MTA_BUSLANES_MORE   = 'mta_ridership_buslanes_more'; // why sightings were dropped, and repeat cars
const MTA_BUSLANES_MORE_MIN = 100; // route pages need this many sightings a year for the two charts

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

/** The two charts under the map (why sightings were dropped, repeat cars) are live (since 2026-11-02); return mta_ridership_preview() to put them back in review. */
function mta_buslanes_more_live()
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

/**
 * Pure part, for tests. The MTA adds a month's issued tickets some weeks after its other sightings, so the newest month
 * can look as if nearly every sighting was dropped. $to: the first day after the year ('Y-m-01'); $months: {m, n} issued
 * tickets by month. Steps $to back past up to 3 trailing months with under a quarter of the busiest month's tickets.
 */
function mta_buslanes_ready_to($to, array $months)
{
    $n = [];
    foreach ($months as $r) {
        $n[substr((string) ($r['m'] ?? ''), 0, 7)] = (int) ($r['n'] ?? 0);
    }
    $max = $n ? max($n) : 0;
    for ($i = 0; $i < 3; $i++) {
        $m = gmdate('Y-m', strtotime("$to -1 month UTC"));
        if (!$max || ($n[$m] ?? 0) >= $max / 4) {
            break;
        }
        $to = $m . '-01';
    }
    return $to;
}

/** What happened to the cameras' sightings, in the order shown, with the MTA's status for each. */
function mta_buslanes_outcomes()
{
    return [
        'issued' => ['VIOLATION ISSUED', 'Ticket issued'],
        'tech'   => ['TECHNICAL ISSUE/OTHER', 'Dropped: technical problem or other reason'],
        'info'   => ['DRIVER/VEHICLE INFO MISSING', 'Dropped: plate or owner details missing'],
        'comm'   => ['EXEMPT - COMMERCIAL UNDER 20', 'Exempt: commercial vehicle, under 20 minutes'],
        'emerg'  => ['EXEMPT - EMERGENCY VEHICLE', 'Exempt: emergency vehicle'],
        'bus'    => ['EXEMPT - BUS/PARATRANSIT', 'Exempt: bus or paratransit van'],
        'other'  => ['EXEMPT - OTHER', 'Exempt: other reason'],
    ];
}

/**
 * Pure part, for tests. $status: {r, v, n} sightings by route and MTA status; $repeat: {r, n, cars} cars that got n tickets
 * on route r; $repeat_all: {n, cars} the same citywide (a car ticketed on two routes counts once). Returns
 * {all: x, by: {label: x}} where x = {out: {issued, tech, ...}, cars, tickets, top (most tickets on one car),
 * b: [[cars, tickets] for 1, 2-4, 5-9 and 10+ tickets]}.
 */
function mta_buslanes_more(array $status, array $repeat, array $repeat_all)
{
    $keys = [];
    foreach (mta_buslanes_outcomes() as $k => $o) {
        $keys[$o[0]] = $k;
    }
    $blank = ['out' => array_fill_keys(array_keys(mta_buslanes_outcomes()), 0), 'cars' => 0, 'tickets' => 0, 'top' => 0, 'b' => [[0, 0], [0, 0], [0, 0], [0, 0]]];
    $labels = function ($r) {
        return array_map(function ($x) { return mta_buses_label(strtoupper(trim($x))); }, array_filter(explode('/', (string) ($r['r'] ?? '')), 'strlen'));
    };
    $add_cars = function (&$x, $n, $cars) {
        $b = $n >= 10 ? 3 : ($n >= 5 ? 2 : ($n >= 2 ? 1 : 0));
        $x['b'][$b][0] += $cars;
        $x['b'][$b][1] += $n * $cars;
        $x['cars'] += $cars;
        $x['tickets'] += $n * $cars;
        $x['top'] = max($x['top'], $n);
    };
    $out = ['all' => $blank, 'by' => []];
    foreach ($status as $r) {
        $k = $keys[$r['v'] ?? ''] ?? null;
        if (!$k) {
            continue; // no status yet
        }
        $out['all']['out'][$k] += (int) $r['n'];
        foreach ($labels($r) as $l) {
            $out['by'][$l] = $out['by'][$l] ?? $blank;
            $out['by'][$l]['out'][$k] += (int) $r['n'];
        }
    }
    foreach ($repeat as $r) {
        if (($n = (int) ($r['n'] ?? 0)) > 0) {
            foreach ($labels($r) as $l) {
                $out['by'][$l] = $out['by'][$l] ?? $blank;
                $add_cars($out['by'][$l], $n, (int) $r['cars']);
            }
        }
    }
    foreach ($repeat_all as $r) {
        if (($n = (int) ($r['n'] ?? 0)) > 0) {
            $add_cars($out['all'], $n, (int) $r['cars']);
        }
    }
    ksort($out['by'], SORT_NATURAL);
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
        // ...then back to the last month whose issued tickets are in (see mta_buslanes_ready_to).
        $months = $api(['$select' => 'date_trunc_ym(first_occurrence) AS m, count(*) AS n', '$group' => 'm',
                        '$where' => "violation_status = 'VIOLATION ISSUED' AND first_occurrence >= '" . gmdate('Y-m-01', strtotime("$to -6 months UTC")) . "' AND first_occurrence < '$to'"]);
        $to = mta_buslanes_ready_to($to, $months);
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
            if (function_exists('mta_ridership_clean_urls')) {
                mta_ridership_clean_urls([mta_buses_url()]); // the bus route pages
            }
        }
        // The two charts, over the same year as the map.
        $more = get_option(MTA_BUSLANES_MORE) ?: [];
        if (($more['to'] ?? '') !== $to) {
            $w = "first_occurrence >= '$from' AND first_occurrence < '$to'";
            $q = function ($query) { return mta_ridership_api('https://data.ny.gov/resource/kh8p-hcbm.json', ['$query' => $query . ' LIMIT 50000']); };
            $wi = "$w AND violation_status = 'VIOLATION ISSUED' AND vehicle_id IS NOT NULL";
            $x = mta_buslanes_more(
                $q("SELECT bus_route_id AS r, violation_status AS v, count(*) AS n WHERE $w GROUP BY r, v"),
                $q("SELECT vehicle_id, bus_route_id AS r, count(*) AS n WHERE $wi GROUP BY vehicle_id, r |> SELECT r, n, count(*) AS cars GROUP BY r, n"),
                $q("SELECT vehicle_id, count(*) AS n WHERE $wi GROUP BY vehicle_id |> SELECT n, count(*) AS cars GROUP BY n"));
            if (!$x['all']['cars']) {
                throw new Exception('No repeat-car counts returned');
            }
            update_option(MTA_BUSLANES_MORE, ['to' => $to, 'from' => substr($from, 0, 7), 'month' => $latest, 'updated' => gmdate('c')] + $x, false);
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

/**
 * Two charts for the Bus Routes page (or one route's page, with $r): what happened to the cameras' sightings, and how
 * many vehicles got many tickets. '' before the data exists or for routes with few sightings.
 */
function mta_buslanes_more_section($sec, $r = null)
{
    $m = get_option(MTA_BUSLANES_MORE);
    if (!mta_buslanes_more_live() || empty($m['month'])) {
        return '';
    }
    $label = $r ? mta_buses_label($r['id']) : '';
    $x = $r ? ($m['by'][$label] ?? null) : $m['all'];
    $seen = $x ? array_sum($x['out']) : 0;
    if (!$x || $seen < ($r ? MTA_BUSLANES_MORE_MIN : 1) || !$x['cars']) {
        return '';
    }
    $f = function ($n) { return number_format($n); };
    $pct = function ($a, $b) { return $b ? (100 * $a / $b < 1 ? round(100 * $a / $b, 1) : round(100 * $a / $b)) . '%' : '0%'; };
    $bars = function ($rows) {
        $max = max(1, max(array_column($rows, 2)));
        $o = '';
        foreach ($rows as [$name, $num, $v]) {
            $o .= '<li><div class="mta-top-row"><span class="mta-top-name">' . esc_html($name) . '</span><span class="mta-top-num">' . esc_html($num) .
                '</span></div><div class="mta-bar"><span style="width:' . round($v / $max * 100, 1) . '%"></span></div></li>';
        }
        return '<ul class="mta-top" style="list-style:none;padding-left:0">' . $o . '</ul>';
    };
    $on = $r ? ' on the ' . $label : '';
    $year = mta_lines_month($m['from']) . ' to ' . mta_lines_month($m['month']);

    $rows = [];
    foreach (mta_buslanes_outcomes() as $k => $o) {
        if ($x['out'][$k]) {
            $rows[] = [$o[1], $f($x['out'][$k]) . ' (' . $pct($x['out'][$k], $seen) . ')', $x['out'][$k]];
        }
    }
    $o = $sec('Why most bus camera sightings' . $on . ' never become tickets',
        '<p>The cameras' . ($r ? ' on ' . esc_html($label) . ' buses' : ' on MTA buses') . ' caught ' . $f($seen) . ' vehicles blocking a bus lane, a bus stop or double parking. ' .
        $f($x['out']['issued']) . ' of them (' . $pct($x['out']['issued'], $seen) . ') got a ticket. The rest were dropped after review or were allowed to be there.</p>' . $bars($rows),
        'Every sighting from ' . esc_html($year) . ', with the reason the MTA gives for each (MTA Bus Automated Camera Enforcement). ' .
        'Commercial vehicles may stop for up to 20 minutes, so shorter stops are exempt.');

    $names = ['1 ticket', '2 to 4 tickets', '5 to 9 tickets', '10 or more tickets'];
    $rows = [];
    foreach ($x['b'] as $i => [$cars, $t]) {
        if ($cars) {
            $rows[] = [$names[$i], $f($cars) . ' vehicles · ' . $pct($t, $x['tickets']) . ' of tickets', $t];
        }
    }
    [$cars10, $t10] = $x['b'][3];
    $o .= $sec('Repeat offenders' . $on,
        '<p>' . $f($x['cars']) . ' different vehicles got tickets' . esc_html($on) . '. ' .
        ($cars10 ? $f($cars10) . ' of them (' . $pct($cars10, $x['cars']) . ') got 10 or more each and ran up ' . $pct($t10, $x['tickets']) . ' of all the tickets. ' : '') .
        'The most any one vehicle got was ' . $f($x['top']) . '.</p>' . $bars($rows),
        'Tickets issued from ' . esc_html($year) . ', grouped by how many each vehicle got' . ($r ? ' from cameras on this route' : '') . '. ' .
        'The MTA scrambles plate numbers before publishing, so a vehicle can be counted but not identified.');
    return $o;
}
