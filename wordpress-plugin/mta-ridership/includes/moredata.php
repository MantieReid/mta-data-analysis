<?php
/**
 * More MTA open data, fetched once a month after the crime step:
 *  - station amenities (restrooms, elevators, help points...) and the permanent art at each station, on station pages
 *    and a "stations with public restrooms" list;
 *  - for subway lines: end-to-end running times, delay-causing incidents, service alerts and rider complaints, the
 *    train car fleet and the MTA's rider survey; rider complaints for bus routes;
 *  - subway fires, customer accidents, vandalism and how safe riders feel, on the crime page;
 *  - LIRR and Metro-North pages, an MTA pay page and a bridges and tunnels traffic page.
 * Each part is stored in its own option and kept from last month if its fetch fails.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_MORE_HOOK = 'mta_ridership_more_step';
const MTA_MORE_PAGES = ['lirr' => 'lirr', 'mnr' => 'metro-north', 'pay' => 'mta-pay', 'bridges' => 'bridges-tunnels'];

add_action(MTA_MORE_HOOK, function () { mta_ridership_run_step('more'); });

add_action('init', function () {
    if (!get_option('mta_more_stations') && get_option(MTA_CRIME_OPTION)) {
        mta_more_schedule(300);
    }
});

/** Everything in this file is live (since 2026-11-03); return mta_ridership_preview() to put it back in review. */
function mta_more_live()
{
    return true;
}

function mta_more_url($key)
{
    return home_url('/' . MTA_MORE_PAGES[$key] . '/');
}

/** The new pages are a 404 and out of the sitemap until they go live, and noindex while in preview. */
add_action('template_redirect', function () {
    foreach (MTA_MORE_PAGES as $slug) {
        if (is_page($slug) && !mta_more_live()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
        }
    }
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    if (mta_more_live()) {
        return $ids;
    }
    foreach (MTA_MORE_PAGES as $slug) {
        if ($p = get_page_by_path($slug, OBJECT, 'page')) {
            $ids[] = $p->ID;
        }
    }
    return $ids;
});
add_filter('wp_robots', function ($r) {
    foreach (MTA_MORE_PAGES as $slug) {
        if (is_page($slug) && mta_ridership_preview()) {
            return ['noindex' => true, 'nofollow' => true] + $r;
        }
    }
    return $r;
});

function mta_more_schedule($delay)
{
    if (!wp_next_scheduled(MTA_MORE_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_MORE_HOOK);
    }
}

/** data.ny.gov query (the site's app token goes with it). */
function mta_more_api($id, array $p)
{
    return mta_ridership_api("https://data.ny.gov/resource/$id.json", $p + ['$limit' => 50000]);
}

/** "2026-09" from "2026-09-01T00:00:00.000", and the month $k months before. */
function mta_more_month($ts, $k = 0)
{
    $m = substr((string) $ts, 0, 7);
    return $k ? gmdate('Y-m', strtotime("$m-01 -$k month UTC")) : $m;
}

function mta_more_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }
    $parts = ['mta_more_stations' => 'mta_more_stations_fetch', 'mta_more_lines' => 'mta_more_lines_fetch', 'mta_more_safety' => 'mta_more_safety_fetch',
              'mta_more_rail' => 'mta_more_rail_fetch', 'mta_more_pay' => 'mta_more_pay_fetch', 'mta_more_bridges' => 'mta_more_bridges_fetch'];
    $errors = [];
    foreach ($parts as $opt => $fn) {
        try {
            $x = $fn();
            if ($x) {
                update_option($opt, ['updated' => gmdate('c')] + $x, false);
            }
        } catch (Throwable $e) {
            $errors[] = "$opt: " . $e->getMessage();
        }
    }
    if ($errors) {
        update_option('mta_ridership_more_error', gmdate('c') . ' ' . implode(' · ', $errors), false);
        mta_ridership_retry_later(MTA_MORE_HOOK, 'mta_more_schedule');
    } else {
        delete_option('mta_ridership_more_error');
        if (function_exists('mta_tm_schedule')) {
            mta_tm_schedule(600); // then the step after it
        }
    }
    if (mta_more_live() && function_exists('rocket_clean_domain')) { // station, line, bus and crime pages all show some of it
        rocket_clean_domain();
    }
}

/* ---------- fetching ---------- */

/** "Times Sq-42 St" → "timessq42st", for matching names across datasets. */
function mta_more_key($name)
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($name));
}

/** Station amenities by complex (latest month) and the permanent art at each complex. */
function mta_more_stations_fetch()
{
    $complex = [];
    $names = []; // [name key][] = [complex id, routes]
    foreach (mta_more_api('39hk-dx4f', ['$select' => 'station_id, complex_id, stop_name, daytime_routes']) as $r) {
        $complex[(string) (int) $r['station_id']] = (string) $r['complex_id'];
        $names[mta_more_key($r['stop_name'])][] = [(string) $r['complex_id'], preg_split('/\s+/', trim($r['daytime_routes'] ?? ''))];
    }
    $m = mta_more_api('6yjv-fk7g', ['$select' => 'max(month) AS m'])[0]['m'] ?? '';
    if (!$m) {
        throw new Exception('No station amenity data returned');
    }
    $am = [];
    foreach (mta_more_api('6yjv-fk7g', ['$select' => 'station_mrn AS s, amenity_type AS t, count(*) AS n', '$where' => "month = '" . substr($m, 0, 10) . "'", '$group' => 's, t']) as $r) {
        $c = $complex[(string) (int) $r['s']] ?? null;
        if ($c !== null) {
            $am[$c][$r['t']] = ($am[$c][$r['t']] ?? 0) + (int) $r['n'];
        }
    }
    return ['latest' => mta_more_month($m), 'amenities' => $am] + mta_more_art(mta_more_api('4y8j-9pkd', [
        '$select' => 'station_name, line, artist, art_title, art_date, art_material, art_image_link', '$where' => "agency = 'NYCT'"]), $names);
}

/** Art rows → [complex id => [art...]], matched by station name and a shared line; unmatched rows are counted. */
function mta_more_art(array $rows, array $names)
{
    $art = [];
    $unmatched = 0;
    foreach ($rows as $r) {
        $cands = $names[mta_more_key($r['station_name'] ?? '')] ?? [];
        $lines = array_filter(array_map('trim', preg_split('/[,\s]+/', $r['line'] ?? '')));
        $hit = null;
        foreach ($cands as [$c, $routes]) {
            if (array_intersect($lines, $routes)) {
                $hit = $c;
                break;
            }
        }
        if ($hit === null && count(array_unique(array_column($cands, 0))) === 1) {
            $hit = $cands[0][0];
        }
        if ($hit === null) {
            $unmatched++;
            continue;
        }
        $link = $r['art_image_link']['url'] ?? (is_string($r['art_image_link'] ?? null) ? $r['art_image_link'] : '');
        $art[$hit][] = ['title' => trim($r['art_title'] ?? ''), 'artist' => trim($r['artist'] ?? ''), 'year' => trim($r['art_date'] ?? ''),
                        'material' => trim($r['art_material'] ?? ''), 'url' => strpos($link, 'https://') === 0 ? $link : ''];
    }
    return ['art' => $art, 'art_unmatched' => $unmatched];
}

/** Our line codes from the MTA's: "J" → "JZ", the shuttles' GTFS ids → the line list's keys. */
function mta_more_line_code($l)
{
    $l = trim((string) $l);
    $map = ['J' => 'JZ', 'Z' => 'JZ', 'GS' => 'S 42nd', 'FS' => 'S Fkln', 'H' => 'S Rock', 'S-42 Street Shuttle' => 'S 42nd',
            'S-Franklin Av Shuttle' => 'S Fkln', 'S-Rockaway Park Shuttle' => 'S Rock', 'SI' => null, 'S' => null];
    return array_key_exists($l, $map) ? $map[$l] : $l;
}

/** Running times, incidents, alerts and complaints per subway line, complaints per bus route, the car fleet and the rider survey. */
function mta_more_lines_fetch()
{
    $out = [];
    // End-to-end running times on weekdays, latest month vs the same month in 2019, on each line's busiest pattern.
    $m = mta_more_month(mta_more_api('sp9g-mzjh', ['$select' => 'max(month) AS m'])[0]['m'] ?? '');
    if ($m) {
        $then = '2019' . substr($m, 4);
        $rows = [];
        foreach ([$m, $then] as $mm) {
            $rows[$mm] = mta_more_api('sp9g-mzjh', ['$select' => 'line, direction, origin_station_name AS o, destination_station_name AS d, number_of_stops AS s, distance, ' .
                'average_actual_runtime AS a, average_scheduled_runtime AS sch, actual_trains AS n', '$where' => "month = '$mm-01' AND schedule_day_type = 'Weekday'"]);
        }
        $out['runtimes'] = mta_more_runtimes($rows[$m], $rows[$then]);
        $out['runtimes_month'] = $m;
        $out['runtimes_then'] = $then;
    }
    // Delay-causing incidents, last 12 months.
    $m = mta_more_month(mta_more_api('g937-7k7c', ['$select' => 'max(month) AS m'])[0]['m'] ?? '');
    if ($m) {
        $inc = [];
        foreach (mta_more_api('g937-7k7c', ['$select' => 'line, reporting_category AS c, sum(incidents) AS n', '$where' => "month >= '" . mta_more_month($m . '-01', 11) . "-01'", '$group' => 'line, c']) as $r) {
            $code = mta_more_line_code($r['line'] ?? '');
            if ($code && !empty($r['c']) && (int) $r['n']) {
                $inc[$code][$r['c']] = ($inc[$code][$r['c']] ?? 0) + (int) $r['n'];
            }
        }
        foreach ($inc as &$x) {
            arsort($x);
        }
        unset($x);
        $out['incidents'] = $inc;
        $out['incidents_range'] = [mta_more_month($m . '-01', 11), $m];
    }
    // Service alerts (first posting of each), last 12 full months.
    $to = gmdate('Y-m-01');
    $from = gmdate('Y-m-01', strtotime("$to -12 month UTC"));
    $al = [];
    foreach (mta_more_api('7kct-peq7', ['$select' => 'affected, status_label AS s, count(*) AS n',
        '$where' => "agency = 'NYCT Subway' AND update_number = '0' AND date >= '$from' AND date < '$to'", '$group' => 'affected, s']) as $r) {
        foreach (array_unique(array_filter(array_map('mta_more_line_code', explode('|', $r['affected'] ?? '')))) as $code) {
            foreach (array_unique(array_filter(array_map('trim', explode('|', $r['s'] ?? '')))) as $label) { // "reroute | delays" counts for both
                $al[$code][$label] = ($al[$code][$label] ?? 0) + (int) $r['n'];
            }
        }
    }
    foreach ($al as &$x) {
        arsort($x);
    }
    unset($x);
    $out['alerts'] = $al;
    $out['alerts_range'] = [substr($from, 0, 7), mta_more_month(gmdate('Y-m-01', strtotime("$to -1 month UTC")))];
    // Rider complaints and other feedback, last 12 months of cases.
    $cm = mta_more_month(mta_more_api('nh7m-at3e', ['$select' => 'max(created_at) AS m'])[0]['m'] ?? '');
    if ($cm) {
        $from = mta_more_month($cm . '-01', 12); // the latest month is usually part-way through
        $last = mta_more_month($cm . '-01', 1);
        $sub = $bus = [];
        foreach (mta_more_api('nh7m-at3e', ['$select' => 'agency, line_route_branch AS l, category AS c, count(*) AS n',
            '$where' => "agency IN ('Subways', 'Buses') AND case_type = 'Complaint' AND created_at >= '$from-01' AND created_at < '$cm-01'", '$group' => 'agency, l, c']) as $r) {
            if (empty($r['l']) || empty($r['c'])) {
                continue;
            }
            if ($r['agency'] === 'Subways') {
                $code = mta_more_line_code($r['l']);
                if ($code) {
                    $sub[$code][$r['c']] = ($sub[$code][$r['c']] ?? 0) + (int) $r['n'];
                }
            } else {
                $id = str_replace('-SBS', '+', strtoupper(trim($r['l'])));
                $bus[$id][$r['c']] = ($bus[$id][$r['c']] ?? 0) + (int) $r['n'];
            }
        }
        foreach ([&$sub, &$bus] as &$set) {
            foreach ($set as &$x) {
                arsort($x);
            }
            unset($x);
        }
        unset($set);
        $out['complaints'] = $sub;
        $out['bus_complaints'] = $bus;
        $out['complaints_range'] = [$from, $last];
    }
    // The car fleet in service. Cars with no build year are left out: they include long-retired models still marked in service.
    $fleet = [];
    foreach (mta_more_api('kir5-i9xt', ['$select' => 'car_class AS c, count(*) AS n, min(object_year_built) AS a, max(object_year_built) AS b',
        '$where' => "retirement_date = 'In-Service' AND object_year_built > 0", '$group' => 'c']) as $r) {
        $fleet[] = ['class' => $r['c'], 'n' => (int) $r['n'], 'from' => (int) $r['a'], 'to' => (int) $r['b']];
    }
    usort($fleet, function ($a, $b) { return $a['from'] <=> $b['from']; });
    $out['fleet'] = $fleet;
    // The rider survey, latest month.
    $sm = mta_more_api('5754-yujh', ['$select' => 'max(month) AS m'])[0]['m'] ?? '';
    if ($sm) {
        $sv = [];
        foreach (mta_more_api('5754-yujh', ['$select' => 'mode, category, measure, score_grain, score, sample_size', '$where' => "month = '" . substr($sm, 0, 10) . "'"]) as $r) {
            $sv[$r['mode']][$r['measure']][$r['score_grain']] = round((float) $r['score'], 1);
            if (!empty($r['sample_size'])) {
                $sv[$r['mode']]['_n'] = max($sv[$r['mode']]['_n'] ?? 0, (int) $r['sample_size']);
            }
        }
        $out['survey'] = $sv;
        $out['survey_month'] = mta_more_month($sm);
    }
    return $out;
}

/**
 * Running-time rows → per line: the busiest weekday pattern in each direction (most trains), with its average actual
 * and scheduled minutes (weighted by trains) and the same trip's average in the earlier month, if it ran then.
 */
function mta_more_runtimes(array $now, array $then)
{
    $sum = function ($rows) {
        $g = [];
        foreach ($rows as $r) {
            $code = mta_more_line_code($r['line'] ?? '');
            $n = (int) ($r['n'] ?? 0);
            if (!$code || !$n || !isset($r['a'])) {
                continue;
            }
            $k = $code . '|' . $r['direction'] . '|' . $r['o'] . '|' . $r['d'] . '|' . $r['s'];
            $x = &$g[$k];
            $x['code'] = $code;
            $x['dir'] = $r['direction'];
            $x['from'] = $r['o'];
            $x['to'] = $r['d'];
            $x['stops'] = (int) $r['s'];
            $x['miles'] = (float) $r['distance'];
            $x['n'] = ($x['n'] ?? 0) + $n;
            $x['a'] = ($x['a'] ?? 0) + (float) $r['a'] * $n;
            if (isset($r['sch'])) {
                $x['sch'] = ($x['sch'] ?? 0) + (float) $r['sch'] * $n;
                $x['sch_n'] = ($x['sch_n'] ?? 0) + $n;
            }
            unset($x);
        }
        return $g;
    };
    $a = $sum($now);
    $b = $sum($then);
    $out = [];
    foreach ($a as $k => $x) {
        $cur = $out[$x['code']][$x['dir']] ?? null;
        if ($cur && $cur['trains'] >= $x['n']) {
            continue;
        }
        $out[$x['code']][$x['dir']] = ['from' => mta_more_station_name($x['from']), 'to' => mta_more_station_name($x['to']), 'stops' => $x['stops'], 'miles' => round($x['miles'], 1),
            'trains' => $x['n'], 'actual' => round($x['a'] / $x['n'], 1), 'scheduled' => !empty($x['sch_n']) ? round($x['sch'] / $x['sch_n'], 1) : null,
            'then' => isset($b[$k]) ? round($b[$k]['a'] / $b[$k]['n'], 1) : null];
    }
    ksort($out);
    return $out;
}

/** "207TH STREET-8AV" → "207th Street-8 Av". */
function mta_more_station_name($n)
{
    $n = ucwords(strtolower(trim($n)), " -/");
    $n = preg_replace_callback('/\b(\d+)(St|Nd|Rd|Th)\b/', function ($m) { return $m[1] . strtolower($m[2]); }, $n);
    return preg_replace('/(\d+)\s*(av|ave)\b/i', '$1 Av', $n);
}

/** Subway fires and vandalism (counts; the MTA's accident figures are rates, so they are left out). */
function mta_more_safety_fetch()
{
    $out = [];
    $m = mta_more_month(mta_more_api('uf7t-sfzu', ['$select' => 'max(month) AS m', '$where' => "department = 'Subway'"])[0]['m'] ?? '');
    if ($m) {
        $from = mta_more_month($m . '-01', 11);
        $prev = mta_more_month($m . '-01', 23);
        $s = [];
        foreach (mta_more_api('uf7t-sfzu', ['$select' => 'metric, month, value', '$where' => "department = 'Subway' AND metric = 'Subway Fires' AND month >= '$prev-01'"]) as $r) {
            $mm = mta_more_month($r['month']);
            $part = $mm >= $from ? 'year' : 'before';
            $s[$r['metric']][$part] = ($s[$r['metric']][$part] ?? 0) + (float) $r['value'];
            $s[$r['metric']]['latest'] = max($s[$r['metric']]['latest'] ?? '', $mm);
        }
        $out['safety'] = $s;
        $out['safety_from'] = $from;
    }
    $m = mta_more_month(mta_more_api('23fs-vfbd', ['$select' => 'max(month) AS m'])[0]['m'] ?? '');
    if ($m) {
        $from = mta_more_month($m . '-01', 11);
        $prev = mta_more_month($m . '-01', 23);
        $v = [];
        foreach (mta_more_api('23fs-vfbd', ['$select' => 'incident_type AS t, month, vandalism_incidents AS n', '$where' => "month >= '$prev-01'"]) as $r) {
            $part = mta_more_month($r['month']) >= $from ? 'year' : 'before';
            $v[$r['t']][$part] = ($v[$r['t']][$part] ?? 0) + (int) $r['n'];
        }
        uasort($v, function ($a, $b) { return ($b['year'] ?? 0) <=> ($a['year'] ?? 0); });
        $out['vandalism'] = $v;
        $out['vandalism_range'] = [$from, $m];
    }
    return $out;
}

/** LIRR and Metro-North: on-time rates, delays and cancellations by cause and branch, riders, and complaints. */
function mta_more_rail_fetch()
{
    $cfg = ['lirr' => ['otp' => '6kq9-5ikh', 'delays' => 'e32g-kbe9', 'mode' => 'LIRR', 'agency' => 'Long Island Rail Road', 'total' => 'System Total'],
            'mnr' => ['otp' => '83hw-i6xw', 'delays' => 'tgiq-jafi', 'mode' => 'MNR', 'agency' => 'Metro-North Railroad', 'total' => null]];
    $out = [];
    foreach ($cfg as $k => $c) {
        $m = mta_more_month(mta_more_api($c['otp'], ['$select' => 'max(month) AS m'])[0]['m'] ?? '');
        if (!$m) {
            continue;
        }
        $from = mta_more_month($m . '-01', 11);
        $prev = mta_more_month($m . '-01', 23);
        $x = ['latest' => $m, 'from' => $from];
        $otp = [];
        foreach (mta_more_api($c['otp'], ['$select' => 'month, branch_line AS b, otp, am_peak, pm_peak, off_peak', '$where' => "month >= '$prev-01'"]) as $r) {
            $otp[$r['b']][mta_more_month($r['month'])] = ['otp' => (float) $r['otp'] * 100, 'am' => isset($r['am_peak']) ? (float) $r['am_peak'] * 100 : null,
                                                          'pm' => isset($r['pm_peak']) ? (float) $r['pm_peak'] * 100 : null];
        }
        $x['otp'] = mta_more_rail_otp($otp, $from, $c['total']);
        // Train-level delays: late, cancelled or partly cancelled trains, last 12 months of the delay data.
        $dm = mta_more_month(mta_more_api($c['delays'], ['$select' => 'max(service_date) AS m'])[0]['m'] ?? '');
        if ($dm) {
            $df = mta_more_month($dm . '-01', 11);
            $x['delays_range'] = [$df, $dm];
            $x['causes'] = [];
            foreach (mta_more_api($c['delays'], ['$select' => 'delay_category AS c, status AS s, count(*) AS n, avg(minutes_late) AS late',
                '$where' => "service_date >= '$df-01'", '$group' => 'c, s']) as $r) {
                if (!empty($r['c'])) {
                    $x['causes'][$r['c']][$r['s']] = ['n' => (int) $r['n'], 'late' => isset($r['late']) ? round((float) $r['late']) : null];
                }
            }
            $x['branches'] = [];
            foreach (mta_more_api($c['delays'], ['$select' => 'branch AS b, status AS s, count(*) AS n', '$where' => "service_date >= '$df-01'", '$group' => 'b, s']) as $r) {
                if (!empty($r['b'])) {
                    $x['branches'][$r['b']][$r['s']] = (int) $r['n'];
                }
            }
        }
        // Riders, by month.
        $rid = [];
        foreach (mta_more_api('sayj-mze2', ['$select' => 'date_trunc_ym(date) AS m, sum(count) AS n, count(*) AS days',
            '$where' => "mode = '{$c['mode']}' AND date >= '" . mta_more_month(gmdate('Y-m-01'), 25) . "-01'", '$group' => 'm']) as $r) {
            $rid[mta_more_month($r['m'])] = ['n' => (int) $r['n'], 'days' => (int) $r['days']];
        }
        ksort($rid);
        $x['riders'] = $rid;
        // Complaints, last 12 full months.
        $cm = mta_more_month(mta_more_api('nh7m-at3e', ['$select' => 'max(created_at) AS m'])[0]['m'] ?? '');
        if ($cm) {
            $cf = mta_more_month($cm . '-01', 12);
            $x['complaints'] = [];
            foreach (mta_more_api('nh7m-at3e', ['$select' => 'category AS c, count(*) AS n', '$where' => "agency = '{$c['agency']}' AND case_type = 'Complaint' AND created_at >= '$cf-01' AND created_at < '$cm-01'",
                '$group' => 'c', '$order' => 'n DESC']) as $r) {
                if (!empty($r['c'])) {
                    $x['complaints'][$r['c']] = (int) $r['n'];
                }
            }
            $x['complaints_range'] = [$cf, mta_more_month($cm . '-01', 1)];
        }
        $out[$k] = $x;
    }
    if (!$out) {
        throw new Exception('No railroad data returned');
    }
    return $out;
}

/**
 * Monthly on-time rows by branch → per branch: the last 12 months' average (and the 12 before), rush-hour averages,
 * and the system's monthly series (the "System Total" row if there is one, else the average of the branches).
 */
function mta_more_rail_otp(array $by_branch, $from, $total_name)
{
    $avg = function ($xs) { return $xs ? round(array_sum($xs) / count($xs), 1) : null; };
    $out = ['branches' => [], 'series' => []];
    $skip = ['System Total', 'East of Hudson', 'West of Hudson'];
    foreach ($by_branch as $b => $months) {
        $y = $p = $am = $pm = [];
        foreach ($months as $m => $v) {
            if ($m >= $from) {
                $y[] = $v['otp'];
                if ($v['am'] !== null) {
                    $am[] = $v['am'];
                }
                if ($v['pm'] !== null) {
                    $pm[] = $v['pm'];
                }
            } else {
                $p[] = $v['otp'];
            }
        }
        $row = ['otp' => $avg($y), 'before' => $avg($p), 'am' => $avg($am), 'pm' => $avg($pm)];
        if (in_array($b, $skip, true)) {
            $out['groups'][$b] = $row;
        } else {
            $out['branches'][$b] = $row;
        }
    }
    uasort($out['branches'], function ($a, $b) { return ($b['otp'] ?? 0) <=> ($a['otp'] ?? 0); });
    $series = [];
    if ($total_name && isset($by_branch[$total_name])) {
        foreach ($by_branch[$total_name] as $m => $v) {
            $series[$m] = round($v['otp'], 1);
        }
    } else {
        $tmp = [];
        foreach ($by_branch as $b => $months) {
            if (!in_array($b, $skip, true)) {
                foreach ($months as $m => $v) {
                    $tmp[$m][] = $v['otp'];
                }
            }
        }
        foreach ($tmp as $m => $xs) {
            $series[$m] = $avg($xs);
        }
    }
    ksort($series);
    $out['series'] = $series;
    $out['system'] = $total_name && isset($out['groups'][$total_name]) ? $out['groups'][$total_name] : null;
    if (!$out['system']) {
        $ys = array_filter(array_column($out['branches'], 'otp'), 'is_numeric');
        $ps = array_filter(array_column($out['branches'], 'before'), 'is_numeric');
        $out['system'] = ['otp' => $avg($ys), 'before' => $avg($ps), 'am' => null, 'pm' => null];
    }
    return $out;
}

/**
 * MTA pay for the latest full year, without naming anyone: totals by agency, how many people earned over set amounts,
 * the best-paid job titles (10 or more people) and the titles with the most overtime pay.
 */
function mta_more_pay_fetch()
{
    $years = mta_more_api('kcjb-nf3e', ['$select' => 'balance_year AS y, max(balance_period) AS p', '$group' => 'y', '$order' => 'y DESC']);
    $year = null;
    foreach ($years as $r) {
        if ((int) $r['p'] >= 12) {
            $year = (string) $r['y'];
            break;
        }
    }
    if (!$year) {
        throw new Exception('No full year of payroll data');
    }
    $total = 'regular_pay + overtime_pay + overtime_bonus_pay + cash_outs + retro_pay + other_pay';
    $ot = 'overtime_pay + overtime_bonus_pay';
    $w = "balance_year = '$year'";
    $q = function ($soql) {
        $res = wp_remote_get(add_query_arg(['$query' => rawurlencode($soql)], 'https://data.ny.gov/resource/kcjb-nf3e.json'),
            ['timeout' => 300, 'headers' => ($t = get_option('mta_ridership_app_token')) ? ['X-App-Token' => $t] : []]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            throw new Exception('Payroll query failed: ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res)));
        }
        return json_decode(wp_remote_retrieve_body($res), true);
    };
    // One row per person (name and agency), then summed up.
    $people = "SELECT name, paying_agency AS a, working_title AS t, sum($total) AS pay, sum($ot) AS otpay WHERE $w GROUP BY name, a, t";
    $agencies = [];
    foreach ($q("$people |> SELECT a, count(*) AS people, sum(pay) AS total_pay, sum(otpay) AS total_ot, median(pay) AS med GROUP BY a ORDER BY total_pay DESC") as $r) {
        $agencies[$r['a']] = ['people' => (int) $r['people'], 'pay' => (float) $r['total_pay'], 'ot' => (float) $r['total_ot'], 'median' => round((float) ($r['med'] ?? 0))];
    }
    $bands = $q("$people |> SELECT count(*) AS people, sum(pay) AS total_pay, sum(otpay) AS total_ot, median(pay) AS med, sum(case(pay >= 100000, 1, true, 0)) AS k100, " .
        "sum(case(pay >= 200000, 1, true, 0)) AS k200, sum(case(pay >= 300000, 1, true, 0)) AS k300, sum(case(otpay >= 100000, 1, true, 0)) AS ot100")[0] ?? [];
    $titles = $q("$people |> SELECT t, a, count(*) AS people, avg(pay) AS avg_pay, avg(otpay) AS avg_ot GROUP BY t, a HAVING people >= 10 ORDER BY avg_pay DESC LIMIT 25");
    $ot_titles = $q("$people |> SELECT t, a, count(*) AS people, sum(otpay) AS total_ot, avg(otpay) AS avg_ot GROUP BY t, a HAVING people >= 10 ORDER BY total_ot DESC LIMIT 15");
    $big = $q("$people |> SELECT t, a, count(*) AS people, sum(pay) AS total_pay, avg(pay) AS avg_pay GROUP BY t, a ORDER BY people DESC LIMIT 15");
    $fix = function ($rows, $keys) {
        return array_map(function ($r) use ($keys) {
            $o = ['title' => $r['t'] ?? '', 'agency' => $r['a'] ?? ''];
            foreach ($keys as $k) {
                $o[$k] = round((float) ($r[$k] ?? 0));
            }
            return $o;
        }, $rows);
    };
    return ['year' => $year, 'agencies' => $agencies,
            'all' => array_map(function ($v) { return round((float) $v); }, $bands),
            'titles' => $fix($titles, ['people', 'avg_pay', 'avg_ot']), 'ot_titles' => $fix($ot_titles, ['people', 'total_ot', 'avg_ot']), 'big' => $fix($big, ['people', 'total_pay', 'avg_pay'])];
}

/** Bridge and tunnel crossings: last 12 full months by crossing, vehicle type, payment and hour, and monthly totals. */
function mta_more_bridges_fetch()
{
    $m = mta_more_month(mta_more_api('ebfx-2m7v', ['$select' => 'max(date) AS m'])[0]['m'] ?? '');
    if (!$m) {
        throw new Exception('No crossing data returned');
    }
    $last = mta_more_month($m . '-01', 1); // the latest month is usually part-way through
    $from = mta_more_month($m . '-01', 12);
    $w = "date >= '$from-01' AND date < '$m-01'";
    $sum = function ($group, $where = null) use ($w) {
        $o = [];
        foreach (mta_more_api('ebfx-2m7v', ['$select' => "$group AS k, sum(traffic_count) AS n", '$where' => $where ?: $w, '$group' => 'k']) as $r) {
            if (isset($r['k']) && $r['k'] !== '') {
                $o[$r['k']] = (int) $r['n'];
            }
        }
        arsort($o);
        return $o;
    };
    $fac = [];
    foreach ($sum('facility') as $f => $n) { // the RFK has three crossings in the data
        $name = preg_match('/^Robert F\. Kennedy Bridge/', $f) ? 'Robert F. Kennedy Bridge' : $f;
        $fac[$name] = ($fac[$name] ?? 0) + $n;
    }
    arsort($fac);
    $hours = array_fill(0, 24, 0);
    foreach ($sum('hour') as $h => $n) {
        $hours[(int) $h] = $n;
    }
    $months = $sum('date_trunc_ym(date)', "date >= '" . mta_more_month($m . '-01', 25) . "-01' AND date < '$m-01'");
    $mm = [];
    foreach ($months as $k => $n) {
        $mm[mta_more_month($k)] = $n;
    }
    ksort($mm);
    return ['from' => $from, 'latest' => $last, 'facilities' => $fac, 'types' => $sum('vehicle_class_category'), 'payment' => $sum('payment_method'),
            'hours' => $hours, 'months' => $mm, 'days' => (int) round((strtotime("$m-01") - strtotime("$from-01")) / 86400)];
}

/* ---------- shared pieces ---------- */

function mta_more_sec($title, $body, $note = '')
{
    return '<section class="mta-section"><' . mta_ridership_h() . '>' . esc_html($title) . '</' . mta_ridership_h() . '>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
}

function mta_more_tile($big, $small)
{
    return '<div class="mta-tile"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>';
}

function mta_more_h3($t)
{
    return '<' . mta_ridership_h(true) . '>' . esc_html($t) . '</' . mta_ridership_h(true) . '>';
}

/** Bar list: [name (text, or ['html' => ...]), bar value, number shown, small line]. */
function mta_more_bars(array $items)
{
    if (!$items) {
        return '';
    }
    $max = max(1, max(array_column($items, 1)));
    $o = '';
    foreach ($items as [$name, $val, $num, $sub]) {
        $o .= '<li><div class="mta-top-row"><span class="mta-top-name">' . (is_array($name) ? $name['html'] : esc_html($name)) . ($sub ? '<br><small class="mta-note">' . esc_html($sub) . '</small>' : '') .
            '</span><span class="mta-top-num">' . esc_html($num) . '</span></div><div class="mta-bar"><span style="width:' . round($val / $max * 100, 1) . '%"></span></div></li>';
    }
    return '<ul class="mta-top" style="list-style:none;padding-left:0">' . $o . '</ul>';
}

/** Table with escaped header cells; row cells are HTML (escape them before passing). */
function mta_more_table(array $head, array $rows)
{
    $o = '<div class="mta-table-wrap"><table class="mta-table"><thead><tr>';
    foreach ($head as $h) {
        $o .= '<th>' . esc_html($h) . '</th>';
    }
    $o .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $o .= '<tr><td>' . implode('</td><td>', $row) . '</td></tr>';
    }
    return $o . '</tbody></table></div>';
}

/** A small column chart of monthly values: [Y-m => n]. */
function mta_more_month_chart(array $vals, $label, $fmt = 'number_format')
{
    if (count($vals) < 2) {
        return '';
    }
    $max = max(1, max($vals));
    $w = 720 / count($vals);
    $svg = '';
    $i = 0;
    // Percentages that barely move (on-time rates around 95%) start the bars just under the lowest month, so the ups and
    // downs show; the top and the starting value are labelled. In review.
    $first = (string) call_user_func($fmt, reset($vals));
    $low = 0;
    if (substr($first, -1) === '%' && min($vals) > 0.5 * $max) { // zoomed % charts, live since 2026-11-04
        $low = max(0, floor(min($vals) - max(1, ($max - min($vals)) * 0.5)));
        $svg .= '<line x1="0" y1="35" x2="720" y2="35" stroke="#3a4558" stroke-dasharray="4 6"/>' .
            '<text x="720" y="27" text-anchor="end" fill="#a3adbd" font-size="20">' . esc_html(call_user_func($fmt, $max)) . '</text>' .
            '<text x="720" y="208" text-anchor="end" fill="#a3adbd" font-size="20">' . esc_html('bars start at ' . call_user_func($fmt, $low)) . '</text>';
    }
    // Tap or hover a bar to see its month and number (assets/mta-month-chart.js), live since 2026-11-03.
    $tap = true;
    if ($tap) {
        wp_enqueue_script('mta-month-chart', plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/mta-month-chart.js', [], '1.0.0', true);
    }
    foreach ($vals as $m => $c) {
        $hgt = round(max(2, ($c - $low) / max(1e-9, $max - $low) * ($low ? 140 : 160)), 1);
        $name = gmdate('M Y', strtotime("$m-01 UTC"));
        $svg .= '<rect x="' . round($i * $w + 1, 1) . '" y="' . (175 - $hgt) . '" width="' . round(max(1, $w - 2), 1) . '" height="' . $hgt . '" rx="1" fill="#8ab4f8"' .
            ($tap ? ' data-l="' . esc_attr($name) . '" data-v="' . esc_attr(call_user_func($fmt, $c)) . '"' : '') . '><title>' . esc_html($name . ': ' . call_user_func($fmt, $c)) . '</title></rect>';
        if (substr($m, 5, 2) === '01' || $i === 0) {
            $svg .= '<text x="' . round($i * $w + 1, 1) . '" y="208" fill="#a3adbd" font-size="24">' . esc_html(substr($m, 5, 2) === '01' ? substr($m, 0, 4) : gmdate('M', strtotime("$m-01 UTC"))) . '</text>';
        }
        $i++;
    }
    $svg = '<svg viewBox="0 0 720 215" width="100%" role="img" aria-label="' . esc_attr($label) . '">' . $svg . '</svg>';
    return $tap ? '<div class="mta-mchart">' . $svg . '</div>' : $svg;
}

function mta_more_range($a, $b)
{
    return gmdate('M Y', strtotime("$a-01 UTC")) . ' – ' . gmdate('M Y', strtotime("$b-01 UTC"));
}

/** "$8.6 billion", "$415 million", "$98,228". */
function mta_more_money($v)
{
    return $v >= 1e9 ? '$' . round($v / 1e9, 1) . ' billion' : ($v >= 1e7 ? '$' . round($v / 1e6) . ' million' : '$' . number_format($v));
}

/* ---------- station pages ---------- */

function mta_more_amenity_rows($a)
{
    $rows = [];
    $rows[] = ['Public restroom', !empty($a['PUBLIC_RESTROOM']) ? 'Yes' . ($a['PUBLIC_RESTROOM'] > 1 ? ' (' . $a['PUBLIC_RESTROOM'] . ')' : '') : 'No'];
    foreach (['ELEVATOR' => 'Elevators', 'ESCALATOR' => 'Escalators', 'TURNSTILE' => 'Turnstiles', 'HIGH_ENTRY_EXIT_TURNSTILE' => 'Full-height turnstiles',
              'PILOT_FARE_GATE' => 'New wide fare gates (pilot)', 'ADA_FARE_ACCESS_SYSTEM_ENTRANCE' => 'Accessible fare gates', 'OMNY_VENDING_MACHINE' => 'OMNY card vending machines',
              'HELP_POINT' => 'Help Point intercoms', 'COUNTDOWN_CLOCK' => 'Train arrival countdown clocks'] as $k => $label) {
        if (!empty($a[$k])) {
            $rows[] = [$label, number_format($a[$k])];
        }
    }
    return $rows;
}

/** "What's at this station" and "Art at this station" for a station page (in review). */
function mta_more_station_sections($st)
{
    $d = mta_more_live() ? get_option('mta_more_stations') : null;
    if (!$d) {
        return '';
    }
    $id = (string) $st['id'];
    $name = mta_stations_display($st['name']);
    $o = '';
    if (!empty($d['amenities'][$id])) {
        $a = $d['amenities'][$id];
        $rows = array_map(function ($r) { return [esc_html($r[0]), esc_html($r[1])]; }, mta_more_amenity_rows($a));
        $o .= mta_more_sec('What\'s at ' . $name . '?', '<p>' . esc_html(!empty($a['PUBLIC_RESTROOM']) ? $name . ' has a public restroom.' :
            $name . ' has no public restroom listed by the MTA.') . ' <a href="' . esc_url(mta_stations_url() . '#restrooms') . '">Stations with restrooms &rarr;</a></p>' .
            mta_more_table(['', 'Count'], $rows),
            'From the MTA\'s station amenities list, ' . esc_html(gmdate('F Y', strtotime($d['latest'] . '-01 UTC'))) . '. Restrooms may close at night or for cleaning.');
    }
    if (!empty($d['art'][$id])) {
        $li = '';
        foreach ($d['art'][$id] as $x) {
            $t = $x['url'] ? '<a href="' . esc_url($x['url']) . '" rel="noopener">' . esc_html($x['title']) . '</a>' : esc_html($x['title']);
            $li .= '<li><strong>' . $t . '</strong> by ' . esc_html($x['artist']) . ($x['year'] ? ' (' . esc_html($x['year']) . ')' : '') .
                ($x['material'] ? '<br><small class="mta-note">' . esc_html(ucfirst($x['material'])) . '</small>' : '') . '</li>';
        }
        $n = count($d['art'][$id]);
        $o .= mta_more_sec('Art at ' . $name, '<p>' . esc_html($name . ' has ' . ($n === 1 ? 'one permanent artwork' : $n . ' permanent artworks') . ' from MTA Arts & Design.') . '</p><ul>' . $li . '</ul>',
            'From the MTA\'s permanent art catalog. Titles link to the MTA\'s page for each work.');
    }
    return $o;
}

/** "NYC subway stations with public restrooms" for the stations page (in review). */
function mta_more_restrooms_section()
{
    $d = mta_more_live() ? get_option('mta_more_stations') : null;
    if (!$d) {
        return '';
    }
    $by = [];
    foreach (mta_stations_all() as $id => $s) {
        if (!empty($d['amenities'][(string) $id]['PUBLIC_RESTROOM'])) {
            $by[$s['borough']][] = $s;
        }
    }
    if (!$by) {
        return '';
    }
    $order = array_flip(['Manhattan', 'Brooklyn', 'Queens', 'Bronx', 'Staten Island']);
    uksort($by, function ($a, $b) use ($order) { return ($order[$a] ?? 9) <=> ($order[$b] ?? 9); });
    $n = array_sum(array_map('count', $by));
    $body = '<p>' . esc_html($n . ' of the ' . count(mta_stations_all()) . ' subway stations have a public restroom, according to the MTA.') . '</p>';
    foreach ($by as $boro => $list) {
        usort($list, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        $body .= mta_more_h3($boro . ' (' . count($list) . ')') . '<p class="mta-find-picks">';
        foreach ($list as $s) {
            $body .= '<a class="mta-find-pick" href="' . esc_url(mta_stations_url($s['slug'])) . '">' . esc_html(mta_stations_display($s['name'])) . '</a> ';
        }
        $body .= '</p>';
    }
    return '<div id="restrooms"></div>' . mta_more_sec('NYC subway stations with public restrooms', $body,
        'From the MTA\'s station amenities list, ' . esc_html(gmdate('F Y', strtotime($d['latest'] . '-01 UTC'))) . '. Restrooms may close at night or for cleaning.');
}

/* ---------- subway line pages ---------- */

function mta_more_alert_name($k)
{
    $names = ['delays' => 'Delays', 'severe-delays' => 'Severe delays', 'express-to-local' => 'Express running local', 'stops-skipped' => 'Stops skipped', 'reroute' => 'Reroutes',
              'part-suspended' => 'Part of the line suspended', 'suspended' => 'Line suspended', 'cancellations' => 'Cancellations', 'information-outage' => 'Information outages',
              'station-notice' => 'Station notices', 'boarding-change' => 'Boarding changes', 'planned-work' => 'Planned work', 'slow-speeds' => 'Slow speeds',
              'local-to-express' => 'Local running express', 'no-scheduled-service' => 'No scheduled service', 'extra-service' => 'Extra service'];
    return $names[$k] ?? ucfirst(str_replace('-', ' ', $k));
}

function mta_more_complaint_name($k)
{
    $names = ['Unauthorized or criminal activity' => 'Crime or rule-breaking', 'Delayed, cancelled or modified Service' => 'Delays, cancellations or service changes',
              'Appearance and cleanliness' => 'Cleanliness', 'Damaged or out of service equipment' => 'Broken equipment', 'Comfort' => 'Comfort (heat, crowding, noise)',
              'A MetroCard or Ticket refund' => 'Refunds', 'Policies, rules and Regulations' => 'Rules and policies', 'Schedules and service planning' => 'Schedules and service planning'];
    return $names[$k] ?? $k;
}

/** Sections for one line's page (in review): running time, incidents, alerts, complaints. */
function mta_more_line_sections($l)
{
    $d = mta_more_live() ? get_option('mta_more_lines') : null;
    if (!$d) {
        return '';
    }
    $c = $l['code'];
    $name = $l['name'];
    $o = '';
    if (!empty($d['runtimes'][$c])) {
        $rows = [];
        $txt = [];
        foreach ($d['runtimes'][$c] as $x) {
            $rows[] = [esc_html($x['from'] . ' → ' . $x['to']), esc_html(round($x['actual']) . ' min'), esc_html($x['scheduled'] !== null ? round($x['scheduled']) . ' min' : '–'),
                       esc_html($x['then'] !== null ? round($x['then']) . ' min' : '–'), esc_html($x['stops'] . ' · ' . $x['miles'] . ' mi')];
            $txt[] = $x['from'] . ' to ' . $x['to'] . ' took ' . round($x['actual']) . ' minutes on average';
        }
        $then = gmdate('M Y', strtotime($d['runtimes_then'] . '-01 UTC'));
        $o .= mta_more_sec('How long does the ' . $name . ' take end to end?', '<p>' . esc_html('On weekdays in ' . gmdate('F Y', strtotime($d['runtimes_month'] . '-01 UTC')) . ', ' .
            implode('; ', $txt) . '.') . '</p>' . mta_more_table(['Trip', 'Actual', 'Schedule', $then, 'Stops · distance'], $rows),
            'MTA end-to-end running times on weekdays (all times of day), on the route most of the line\'s trains run in each direction, with the same trip in ' . esc_html($then) . ' for comparison.');
    }
    if (!empty($d['incidents'][$c])) {
        $items = [];
        foreach (array_slice($d['incidents'][$c], 0, 6, true) as $k => $n) {
            $items[] = [$k === 'Planned ROW Work' ? 'Planned track work' : $k, $n, number_format($n), ''];
        }
        $tot = array_sum($d['incidents'][$c]);
        $o .= mta_more_sec('Delay-causing incidents on the ' . $name, '<p>' . esc_html('From ' . mta_more_range(...$d['incidents_range']) . ', the MTA logged ' . number_format($tot) .
            ' incidents that delayed the ' . $name . ', about ' . round($tot / 12) . ' a month.') . '</p>' . mta_more_bars($items),
            'Incidents are the events behind delays (one incident can delay many trains), counted by the MTA per line and cause.');
    }
    if (!empty($d['alerts'][$c])) {
        $items = [];
        foreach (array_slice($d['alerts'][$c], 0, 6, true) as $k => $n) {
            $items[] = [mta_more_alert_name($k), $n, number_format($n), ''];
        }
        $dl = $d['alerts'][$c]['delays'] ?? 0;
        $o .= mta_more_sec('Service alerts for the ' . $name, '<p>' . esc_html('From ' . mta_more_range(...$d['alerts_range']) . ', the MTA posted ' . number_format($dl) .
            ' delay alerts for the ' . $name . ', about ' . round($dl / 52) . ' a week.') . '</p>' . mta_more_bars($items),
            'Each alert the MTA posted (first posting only), by type; one alert can have several types. Live alerts: <a href="https://www.mta.info/alerts" rel="noopener">mta.info</a>.');
    }
    if (!empty($d['complaints'][$c])) {
        $items = [];
        foreach (array_slice($d['complaints'][$c], 0, 6, true) as $k => $n) {
            $items[] = [mta_more_complaint_name($k), $n, number_format($n), ''];
        }
        $tot = array_sum($d['complaints'][$c]);
        $top = array_key_first($d['complaints'][$c]);
        $o .= mta_more_sec('What do riders complain about on the ' . $name . '?', '<p>' . esc_html('From ' . mta_more_range(...$d['complaints_range']) . ', riders sent the MTA ' . number_format($tot) .
            ' complaints about the ' . $name . '. The most were about ' . lcfirst(mta_more_complaint_name($top)) . ' (' . number_format($d['complaints'][$c][$top]) . ').') . '</p>' . mta_more_bars($items),
            'Complaints riders sent the MTA (by phone, online or in person) that named this line.');
    }
    return $o;
}

/** Sections for the all-lines page (in review): trip times, complaints by line, the car fleet and the rider survey. */
function mta_more_lines_index_sections()
{
    $d = mta_more_live() ? get_option('mta_more_lines') : null;
    if (!$d) {
        return '';
    }
    $lines = function_exists('mta_lines_list') ? mta_lines_list() : [];
    $link = function ($code) use ($lines) {
        return isset($lines[$code]) ? '<a href="' . esc_url(mta_lines_url($lines[$code][0])) . '">' . esc_html($lines[$code][1]) . '</a>' : esc_html($code);
    };
    $o = '';
    if (!empty($d['runtimes'])) {
        $rows = [];
        foreach ($d['runtimes'] as $code => $dirs) {
            $x = reset($dirs);
            if (strpos($code, 'S ') === 0) {
                continue;
            }
            $rows[] = ['cells' => [$link($code), esc_html($x['from'] . ' → ' . $x['to']), esc_html(round($x['actual']) . ' min'),
                       esc_html($x['then'] !== null ? (($x['actual'] - $x['then']) > 0 ? '+' : '') . round($x['actual'] - $x['then'], 1) . ' min' : '–')], 'min' => $x['actual']];
        }
        usort($rows, function ($a, $b) { return $b['min'] <=> $a['min']; });
        $then = gmdate('Y', strtotime($d['runtimes_then'] . '-01 UTC'));
        $o .= mta_more_sec('How long does each line take end to end?', mta_more_table(['Line', 'Trip', 'Weekdays', 'vs ' . $then], array_column($rows, 'cells')),
            'Average weekday running time, ' . esc_html(gmdate('F Y', strtotime($d['runtimes_month'] . '-01 UTC'))) . ', on each line\'s main route in one direction, longest first, with the change since the same month in ' . esc_html($then) . '.');
    }
    if (!empty($d['complaints'])) {
        $items = [];
        foreach ($d['complaints'] as $code => $cats) {
            if (isset($lines[$code])) {
                $items[] = [['html' => $link($code)], array_sum($cats), number_format(array_sum($cats)), 'Most about ' . lcfirst(mta_more_complaint_name(array_key_first($cats)))];
            }
        }
        usort($items, function ($a, $b) { return $b[1] <=> $a[1]; });
        $o .= mta_more_sec('Which line gets the most complaints?', mta_more_bars($items),
            'Complaints riders sent the MTA that named each line, ' . esc_html(mta_more_range(...$d['complaints_range'])) . '. Busier lines get more.');
    }
    if (!empty($d['fleet'])) {
        $rows = [];
        foreach ($d['fleet'] as $f) {
            $rows[] = [esc_html($f['class']), number_format($f['n']), esc_html($f['from'] === $f['to'] ? $f['from'] : $f['from'] . '–' . $f['to'])];
        }
        $old = $d['fleet'][0];
        $new = end($d['fleet']);
        $o .= mta_more_sec('The subway\'s train cars', '<p>' . esc_html('The oldest cars still in service are the ' . $old['class'] . 's, built in ' . $old['from'] . '. The newest are the ' .
            $new['class'] . 's, built from ' . $new['from'] . '.') . '</p>' . mta_more_table(['Model', 'Cars in service', 'Built'], $rows),
            'From the MTA\'s subway car list. It doesn\'t say which line each car runs on. Cars with no build year are left out.');
    }
    $sv = $d['survey']['Subway'] ?? null;
    if ($sv && isset($sv['Overall Last Month'])) {
        $pct = function ($m) use ($sv) { return round(($sv[$m]['Satisfied'] ?? 0) + ($sv[$m]['Very Satisfied'] ?? 0)); };
        $rows = [];
        foreach (['Overall Last Month' => 'The subway overall', 'Wait Time' => 'Wait time', 'Travel Time' => 'Travel time', 'Crowding on Trains' => 'Crowding on trains',
                  'Station Cleanliness' => 'Station cleanliness', 'Train Cleanliness' => 'Train cleanliness'] as $m => $label) {
            if (isset($sv[$m])) {
                $rows[] = [$label, $pct($m), $pct($m) . '%', ''];
            }
        }
        $imp = $sv['Improve to Increase Satisfaction'] ?? [];
        arsort($imp);
        $items = [];
        $imp_names = ['Reduction in QOL Concerns' => 'Fewer quality-of-life problems', 'Personal Security Concerns' => 'Feeling more secure'];
        foreach (array_slice($imp, 0, 6, true) as $k => $v) {
            $items[] = [$imp_names[$k] ?? ucfirst(strtolower($k)), $v, round($v) . '%', ''];
        }
        $o .= mta_more_sec('How satisfied are subway riders?', '<p>' . esc_html($pct('Overall Last Month') . '% of riders said they were satisfied with the subway in ' .
            gmdate('F Y', strtotime($d['survey_month'] . '-01 UTC')) . ($imp ? '. What they most want improved: ' . strtolower((string) array_key_first($imp)) . '.' : '.')) . '</p>' .
            mta_more_h3('Satisfied or very satisfied') . mta_more_bars($rows) . ($items ? mta_more_h3('What riders want improved (pick up to three)') . mta_more_bars($items) : ''),
            'The MTA\'s monthly rider survey (the Pulse survey)' . (!empty($sv['_n']) ? ', about ' . number_format($sv['_n']) . ' subway riders' : '') . '.');
    }
    return $o;
}

/** "What do riders complain about?" for a bus route page (in review). */
function mta_more_bus_section($r)
{
    $d = mta_more_live() ? get_option('mta_more_lines') : null;
    $cats = $d['bus_complaints'][strtoupper($r['id'])] ?? null;
    if (!$cats) {
        return '';
    }
    $items = [];
    foreach (array_slice($cats, 0, 6, true) as $k => $n) {
        $items[] = [mta_more_complaint_name($k), $n, number_format($n), ''];
    }
    return mta_more_sec('What do riders complain about on the ' . $r['label'] . '?', '<p>' . esc_html('From ' . mta_more_range(...$d['complaints_range']) . ', riders sent the MTA ' .
        number_format(array_sum($cats)) . ' complaints about the ' . $r['label'] . '. The most were about ' . lcfirst(mta_more_complaint_name(array_key_first($cats))) . '.') . '</p>' . mta_more_bars($items),
        'Complaints riders sent the MTA that named this route.');
}

/* ---------- crime page ---------- */

/** How safe riders feel, fires and vandalism, for the crime page (in review). */
function mta_more_crime_sections()
{
    if (!mta_more_live()) {
        return '';
    }
    $o = '';
    $ld = get_option('mta_more_lines');
    $sv = $ld['survey']['Subway'] ?? null;
    if ($sv && isset($sv['Safety on Trains'])) {
        $safe = function ($m) use ($sv) { return round(($sv[$m]['Safe'] ?? 0) + ($sv[$m]['Very Safe'] ?? 0)); };
        $unsafe = function ($m) use ($sv) { return round(($sv[$m]['Unsafe'] ?? 0) + ($sv[$m]['Very Unsafe'] ?? 0)); };
        $items = [['On trains: safe or very safe', $safe('Safety on Trains'), $safe('Safety on Trains') . '%', ''], ['On trains: unsafe or very unsafe', $unsafe('Safety on Trains'), $unsafe('Safety on Trains') . '%', ''],
                  ['In stations: safe or very safe', $safe('Safety in Stations'), $safe('Safety in Stations') . '%', ''], ['In stations: unsafe or very unsafe', $unsafe('Safety in Stations'), $unsafe('Safety in Stations') . '%', '']];
        $o .= mta_more_sec('How safe do riders feel?', '<p>' . esc_html('In the MTA\'s rider survey for ' . gmdate('F Y', strtotime($ld['survey_month'] . '-01 UTC')) . ', ' . $safe('Safety on Trains') .
            '% of subway riders said they felt safe or very safe on trains, and ' . $safe('Safety in Stations') . '% in stations.') . '</p>' . mta_more_bars($items),
            'The MTA\'s monthly rider survey (the Pulse survey).');
    }
    $s = get_option('mta_more_safety');
    $fires = $s['safety']['Subway Fires'] ?? null;
    $van = $s['vandalism'] ?? [];
    if ($fires || $van) {
        $body = '';
        if ($fires) {
            $body .= '<p>' . esc_html('From ' . mta_more_range($s['safety_from'], $fires['latest']) . ' the MTA recorded ' . number_format($fires['year']) . ' fires in the subway, ' .
                'compared with ' . number_format($fires['before']) . ' in the 12 months before.') . '</p>';
        }
        if ($van) {
            $names = ['Subway Car Graffiti' => 'Graffiti on subway cars', 'Train Glass' => 'Broken train windows', 'Bus Glass' => 'Broken bus windows', 'Liquid Crystal Display Screen' => 'Damaged screens',
                      'One Metro New York Reader' => 'Damaged OMNY readers', 'Bus Graffiti' => 'Graffiti on buses', 'MetroCard Vending Machine' => 'Damaged MetroCard machines'];
            $items = [];
            foreach ($van as $k => $x) {
                $items[] = [$names[$k] ?? $k, $x['year'] ?? 0, number_format($x['year'] ?? 0), 'Before: ' . number_format($x['before'] ?? 0)];
            }
            $body .= mta_more_h3('Vandalism, ' . mta_more_range(...$s['vandalism_range'])) . mta_more_bars($items);
        }
        $o .= mta_more_sec('Fires and vandalism', $body, 'MTA safety and vandalism counts, with the 12 months before for comparison.');
    }
    return $o;
}

/* ---------- LIRR and Metro-North pages ---------- */

/** "the LIRR", but just "Metro-North". */
function mta_more_the($short)
{
    return $short === 'LIRR' ? 'the LIRR' : $short;
}

function mta_more_rail_names()
{
    return ['lirr' => ['LIRR', 'Long Island Rail Road'], 'mnr' => ['Metro-North', 'Metro-North Railroad']];
}

/** The numbers a railroad page, its title and its FAQ share, or null. */
function mta_more_rail_facts($k)
{
    $all = get_option('mta_more_rail');
    $x = $all[$k] ?? null;
    if (!$x || empty($x['otp']['system']['otp'])) {
        return null;
    }
    // Riders: full months only (the latest month is usually part-way through).
    $full = array_filter($x['riders'] ?? [], function ($v, $m) { return $v['days'] >= (int) gmdate('t', strtotime("$m-01 UTC")); }, ARRAY_FILTER_USE_BOTH);
    $last12 = array_slice($full, -12, 12, true);
    $prev12 = array_slice($full, -24, 12, true);
    $sum = function ($xs) { return array_sum(array_column($xs, 'n')); };
    $days = function ($xs) { return max(1, array_sum(array_column($xs, 'days'))); };
    $late = $cancel = 0;
    foreach ($x['causes'] ?? [] as $c => $s) {
        $late += $s['Late']['n'] ?? 0;
        $cancel += ($s['Cancelled']['n'] ?? 0) + ($s['Partial Cancel']['n'] ?? 0);
    }
    return ['x' => $x, 'names' => mta_more_rail_names()[$k], 'otp' => $x['otp']['system'], 'late' => $late, 'cancel' => $cancel,
            'per_day' => count($last12) === 12 ? round($sum($last12) / $days($last12)) : null,
            'riders_chg' => count($prev12) === 12 && $sum($prev12) ? round(($sum($last12) / $sum($prev12) - 1) * 100, 1) : null,
            'months' => array_map(function ($v) { return round($v['n'] / max(1, $v['days'])); }, $full),
            'range' => mta_more_range($x['from'], $x['latest'])];
}

function mta_more_rail_faq($k, $f)
{
    [$short, $long] = $f['names'];
    $br = $f['x']['otp']['branches'];
    $best = array_key_first($br);
    $worst = array_key_last($br);
    $top = $f['x']['causes'] ? array_keys($f['x']['causes'])[0] : null;
    $causes = [];
    foreach ($f['x']['causes'] ?? [] as $c => $s) {
        $causes[$c] = $s['Late']['n'] ?? 0;
    }
    arsort($causes);
    $out = [['Is ' . mta_more_the($short) . ' on time?', 'From ' . $f['range'] . ', ' . $f['otp']['otp'] . '% of ' . $long . ' trains were on time (the ' . $f['otp']['before'] . '% the year before). ' .
        'The railroad counts a train as on time if it arrives within 5 minutes 59 seconds of schedule.']];
    if ($best && $worst && $best !== $worst) {
        $out[] = ['Which ' . $short . ' branch is the most on time?', $best . ' (' . $br[$best]['otp'] . '% on time). The least on time was ' . $worst . ' (' . $br[$worst]['otp'] . '%).'];
    }
    if ($causes) {
        $c = array_key_first($causes);
        $out[] = ['Why are ' . $short . ' trains late?', 'The most common cause was ' . strtolower($c) . ' (' . number_format($causes[$c]) . ' late trains), out of ' .
            number_format($f['late']) . ' late trains and ' . number_format($f['cancel']) . ' cancelled or partly cancelled trains in 12 months.'];
    }
    if ($f['per_day']) {
        $out[] = ['How many people ride ' . mta_more_the($short) . '?', 'About ' . number_format($f['per_day']) . ' rides a day over the last 12 full months' .
            ($f['riders_chg'] !== null ? ', ' . abs($f['riders_chg']) . '% ' . ($f['riders_chg'] >= 0 ? 'more' : 'fewer') . ' than the 12 months before.' : '.')];
    }
    return $out;
}

function mta_more_rail_page($k)
{
    if (!mta_more_live()) {
        return '';
    }
    if (function_exists('mta_tm_rail_station_current') && ($st = mta_tm_rail_station_current($k))) { // /lirr/<station>/
        return mta_tm_rail_station_page($k, $st);
    }
    $f = mta_more_rail_facts($k);
    if (!$f) {
        return '<p>Railroad data is loading. Check back in a few minutes.</p>';
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.16.12');
    [$short, $long] = $f['names'];
    $x = $f['x'];
    $o = '<header class="mta-hero"><h1>' . esc_html($short === 'LIRR' ? 'LIRR On-Time Performance, Delays & Ridership' : 'Metro-North On-Time Performance, Delays & Ridership') . '</h1>' .
        '<p class="mta-hero-links">' . esc_html($long) . ' report card from MTA open data, ' . esc_html($f['range']) . ', updated monthly · Not affiliated with the MTA</p><div class="mta-tiles">' .
        mta_more_tile($f['otp']['otp'] . '%', 'trains on time') . mta_more_tile(number_format($f['late']), 'late trains in 12 months') .
        mta_more_tile(number_format($f['cancel']), 'cancelled or partly cancelled') . ($f['per_day'] ? mta_more_tile(number_format($f['per_day']), 'rides a day') : '') . '</div></header>';

    $series = $x['otp']['series'];
    $o .= mta_more_sec('Is ' . mta_more_the($short) . ' on time?', '<p>' . esc_html('From ' . $f['range'] . ', ' . $f['otp']['otp'] . '% of ' . $long . ' trains were on time, compared with ' . $f['otp']['before'] .
        '% in the 12 months before.' . ($f['otp']['am'] ? ' At morning rush hour it was ' . $f['otp']['am'] . '%, and ' . $f['otp']['pm'] . '% at evening rush hour.' : '')) . '</p>' .
        mta_more_h3('On time, by month') . mta_more_month_chart($series, 'On-time rate by month', function ($v) { return $v . '%'; }),
        'A train is on time if it arrives at its last stop within 5 minutes 59 seconds of schedule (the railroad\'s own measure).');

    $rows = [];
    foreach ($x['otp']['branches'] as $b => $v) {
        $late = isset($x['branches'][$b]) ? ($x['branches'][$b]['Late'] ?? 0) : null;
        $can = isset($x['branches'][$b]) ? ($x['branches'][$b]['Cancelled'] ?? 0) + ($x['branches'][$b]['Partial Cancel'] ?? 0) : null;
        $rows[] = [esc_html($b), esc_html($v['otp'] . '%'), esc_html($v['before'] !== null ? $v['before'] . '%' : '–'), $late === null ? '–' : number_format($late), $can === null ? '–' : number_format($can)];
    }
    $o .= mta_more_sec('Which ' . $short . ' branch is the most on time?', mta_more_table(['Branch', 'On time', 'Year before', 'Late trains', 'Cancelled'], $rows),
        esc_html($f['range']) . ', most on time first. Late and cancelled trains come from the railroad\'s train-by-train delay records' .
        (!empty($x['delays_range']) ? ', ' . esc_html(mta_more_range(...$x['delays_range'])) : '') . '.');

    if (!empty($x['causes'])) {
        $rows = [];
        $order = $x['causes'];
        uasort($order, function ($a, $b) { return ($b['Late']['n'] ?? 0) <=> ($a['Late']['n'] ?? 0); });
        foreach ($order as $c => $s) {
            $rows[] = [esc_html($c), number_format($s['Late']['n'] ?? 0), esc_html(isset($s['Late']['late']) ? $s['Late']['late'] . ' min' : '–'),
                       number_format(($s['Cancelled']['n'] ?? 0) + ($s['Partial Cancel']['n'] ?? 0))];
        }
        $o .= mta_more_sec('Why are ' . $short . ' trains late?', mta_more_table(['Cause', 'Late trains', 'Average delay', 'Cancelled'], $rows),
            'The railroad\'s train-by-train delay records, ' . esc_html(mta_more_range(...$x['delays_range'])) . '.');
    }

    if (count($f['months']) > 1) {
        $o .= mta_more_sec('How many people ride ' . mta_more_the($short) . '?', '<p>' . esc_html('About ' . number_format($f['per_day'] ?? end($f['months'])) . ' rides a day over the last 12 full months' .
            ($f['riders_chg'] !== null ? ', ' . abs($f['riders_chg']) . '% ' . ($f['riders_chg'] >= 0 ? 'more' : 'fewer') . ' than the 12 months before.' : '.')) . '</p>' .
            mta_more_h3('Rides a day, by month') . mta_more_month_chart($f['months'], 'Rides a day by month'),
            'The MTA\'s daily ridership counts, averaged over each month.');
    }

    if (!empty($x['complaints'])) {
        $items = [];
        foreach (array_slice($x['complaints'], 0, 8, true) as $c => $n) {
            $items[] = [mta_more_complaint_name($c), $n, number_format($n), ''];
        }
        $o .= mta_more_sec('What do riders complain about?', mta_more_bars($items), 'Complaints riders sent the MTA about the ' . esc_html($long) . ', ' . esc_html(mta_more_range(...$x['complaints_range'])) . '.');
    }

    $crime = get_option(MTA_CRIME_OPTION)['agencies'][$k === 'lirr' ? 'LIRR' : 'MNR'] ?? null;
    if ($crime) {
        $o .= mta_more_sec('Crime on ' . mta_more_the($short), '<p>' . esc_html('The MTA counted ' . number_format($crime['year']['n']) . ' major felonies on the ' . $long . ' in the last 12 months, compared with ' .
            number_format($crime['before']['n']) . ' the year before.') . ' <a href="' . esc_url(mta_crime_url()) . '">Subway and railroad crime &rarr;</a></p>',
            'The seven major felonies, which the MTA Police patrol on the railroads.');
    }

    if (function_exists('mta_tm_rail_station_list')) {
        $o .= mta_tm_rail_station_list($k);
    }

    $faq = '';
    foreach (mta_more_rail_faq($k, $f) as [$q, $a]) {
        $faq .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($a) . '</p></details>';
    }
    $o .= '<section class="mta-section mta-faq"><' . mta_ridership_h() . '>Questions</' . mta_ridership_h() . '>' . $faq . '</section>';
    $o .= mta_ridership_ssr_more($k);
    $ids = $k === 'lirr' ? ['6kq9-5ikh' => 'LIRR On-Time Performance', 'e32g-kbe9' => 'LIRR Delays'] : ['83hw-i6xw' => 'Metro-North On-Time Performance', 'tgiq-jafi' => 'Metro-North Delays'];
    $links = [];
    foreach ($ids + ['sayj-mze2' => 'MTA Daily Ridership', 'nh7m-at3e' => 'MTA Customer Feedback'] as $id => $t) {
        $links[] = '<a href="https://data.ny.gov/d/' . $id . '" rel="noopener">' . esc_html($t) . '</a>';
    }
    $o .= '<p class="mta-source mta-source-ssr">Data (data.ny.gov): ' . implode(', ', $links) . '. Updated ' . esc_html(gmdate('M j, Y', strtotime(get_option('mta_more_rail')['updated']))) .
        '.<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

add_shortcode('mta_lirr', function () { return mta_more_rail_page('lirr'); });
add_shortcode('mta_metro_north', function () { return mta_more_rail_page('mnr'); });

/* ---------- MTA pay page ---------- */

function mta_more_agency_name($a)
{
    $names = ['NYCTA' => 'New York City Transit', 'LIRRD' => 'Long Island Rail Road', 'MNCRR' => 'Metro-North Railroad', 'MABST' => 'Manhattan and Bronx Surface Transit (buses)',
              'MTABC' => 'MTA Bus Company', 'MTAHQ' => 'MTA Headquarters and MTA Police', 'BRTUN' => 'Bridges and Tunnels', 'CAPCO' => 'Construction and Development',
              'SIRTA' => 'Staten Island Railway', 'INGEN' => 'Inspector General'];
    return $names[$a] ?? $a;
}

/** A job title with its agency underneath, for the pay tables. */
function mta_more_job($t)
{
    return esc_html($t['title']) . '<br><small class="mta-note">' . esc_html(mta_more_agency_name($t['agency'])) . '</small>';
}

function mta_more_pay_page()
{
    if (!mta_more_live()) {
        return '';
    }
    $p = get_option('mta_more_pay');
    if (!$p) {
        return '<p>Pay data is loading. Check back in a few minutes.</p>';
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.16.12');
    $a = $p['all'];
    $y = $p['year'];
    $o = '<header class="mta-hero"><h1>MTA Salaries and Overtime (' . esc_html($y) . ')</h1>' .
        '<p class="mta-hero-links">What the MTA paid its workers in ' . esc_html($y) . ', from MTA open data · No names shown · Not affiliated with the MTA</p><div class="mta-tiles">' .
        mta_more_tile(mta_more_money($a['total_pay']), 'paid in ' . $y) . mta_more_tile('$' . number_format($a['med']), 'typical (median) pay') .
        mta_more_tile(mta_more_money($a['total_ot']), 'of it overtime') . mta_more_tile(number_format($a['k200']), 'earned $200,000 or more') . '</div></header>';

    $o .= mta_more_sec('How much do MTA workers make?', '<p>' . esc_html('In ' . $y . ' the MTA paid ' . mta_more_money($a['total_pay']) . ' to about ' . number_format($a['people']) .
        ' workers. The typical (median) worker made $' . number_format($a['med']) . '. ' . number_format($a['k100']) . ' made $100,000 or more, ' . number_format($a['k200']) . ' made $200,000 or more and ' .
        number_format($a['k300']) . ' made $300,000 or more.') . '</p>' .
        mta_more_bars([['$100,000 or more', $a['k100'], number_format($a['k100']), round($a['k100'] / max(1, $a['people']) * 100) . '% of workers'],
                       ['$200,000 or more', $a['k200'], number_format($a['k200']), round($a['k200'] / max(1, $a['people']) * 100, 1) . '% of workers'],
                       ['$300,000 or more', $a['k300'], number_format($a['k300']), round($a['k300'] / max(1, $a['people']) * 100, 1) . '% of workers']]),
        'Total pay: regular pay, overtime, cash-outs of unused leave, back pay and other pay. A worker who changed jobs in the year counts once per job.');

    $o .= mta_more_sec('How much overtime does the MTA pay?', '<p>' . esc_html('Overtime came to ' . mta_more_money($a['total_ot']) . ', ' . round($a['total_ot'] / max(1, $a['total_pay']) * 100) .
        '% of all pay. ' . number_format($a['ot100']) . ' workers made $100,000 or more in overtime alone.') . '</p>' .
        mta_more_h3('Job titles with the most overtime') . mta_more_table(['Job title', 'Workers', 'Total overtime', 'Average each'], array_map(function ($t) {
            return [mta_more_job($t), number_format($t['people']), esc_html(mta_more_money($t['total_ot'])), '$' . number_format($t['avg_ot'])];
        }, $p['ot_titles'])), 'Job titles with 10 or more workers. Titles are written as in the MTA\'s payroll.');

    $rows = [];
    foreach ($p['agencies'] as $k => $v) {
        $rows[] = [esc_html(mta_more_agency_name($k)), number_format($v['people']), esc_html(mta_more_money($v['pay'])), '$' . number_format($v['median']), round($v['ot'] / max(1, $v['pay']) * 100) . '%'];
    }
    $o .= mta_more_sec('Pay by MTA agency', mta_more_table(['Agency', 'Workers', 'Total pay', 'Median pay', 'Overtime share'], $rows), 'By the agency that paid the worker.');

    $o .= mta_more_sec('Best-paid MTA jobs', mta_more_table(['Job title', 'Workers', 'Average pay', 'Average overtime'], array_map(function ($t) {
        return [mta_more_job($t), number_format($t['people']), '$' . number_format($t['avg_pay']), '$' . number_format($t['avg_ot'])];
    }, $p['titles'])), 'Average total pay for job titles with 10 or more workers. Individual pay is not shown.');

    $o .= mta_more_sec('The biggest MTA jobs', mta_more_table(['Job title', 'Workers', 'Average pay'], array_map(function ($t) {
        return [mta_more_job($t), number_format($t['people']), '$' . number_format($t['avg_pay'])];
    }, $p['big'])), 'The job titles with the most workers.');

    $faq = '';
    foreach (mta_more_pay_faq($p) as [$q, $ans]) {
        $faq .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($ans) . '</p></details>';
    }
    $o .= '<section class="mta-section mta-faq"><' . mta_ridership_h() . '>Questions</' . mta_ridership_h() . '>' . $faq . '</section>';
    $o .= mta_ridership_ssr_more('pay');
    $o .= '<p class="mta-source mta-source-ssr">Data (data.ny.gov): <a href="https://data.ny.gov/d/kcjb-nf3e" rel="noopener">MTA Employee Payroll</a>, ' . esc_html($y) .
        '. This page shows totals and averages only, not anyone\'s name or individual pay. Updated ' . esc_html(gmdate('M j, Y', strtotime($p['updated']))) .
        '.<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

function mta_more_pay_faq($p)
{
    $a = $p['all'];
    $big = $p['big'][0] ?? null;
    $out = [['How much do MTA workers make?', 'In ' . $p['year'] . ' the typical (median) MTA worker made $' . number_format($a['med']) . ' in total pay, including overtime.']];
    if ($big) {
        $out[] = ['How much does an MTA ' . strtolower(preg_replace('/\s*\(.*$/', '', $big['title'])) . ' make?', 'The MTA\'s largest job title, ' . $big['title'] . ', had ' . number_format($big['people']) .
            ' workers in ' . $p['year'] . ', who made $' . number_format($big['avg_pay']) . ' on average.'];
    }
    $out[] = ['How much overtime does the MTA pay?', mta_more_money($a['total_ot']) . ' in ' . $p['year'] . ', ' . round($a['total_ot'] / max(1, $a['total_pay']) * 100) . '% of all pay. ' .
        number_format($a['ot100']) . ' workers made $100,000 or more in overtime.'];
    $out[] = ['How many MTA workers make over $200,000?', number_format($a['k200']) . ' in ' . $p['year'] . ', about ' . round($a['k200'] / max(1, $a['people']) * 100, 1) . '% of workers.'];
    return $out;
}

add_shortcode('mta_pay', 'mta_more_pay_page');

/* ---------- bridges and tunnels page ---------- */

function mta_more_bridges_page()
{
    if (!mta_more_live()) {
        return '';
    }
    $b = get_option('mta_more_bridges');
    if (!$b) {
        return '<p>Bridge and tunnel data is loading. Check back in a few minutes.</p>';
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.16.12');
    $tot = array_sum($b['facilities']);
    $days = max(1, $b['days']);
    $top = array_key_first($b['facilities']);
    $range = mta_more_range($b['from'], $b['latest']);
    $o = '<header class="mta-hero"><h1>NYC Bridge and Tunnel Traffic: MTA Crossings</h1>' .
        '<p class="mta-hero-links">Vehicles crossing the MTA\'s 9 bridges and tunnels, from MTA open data, ' . esc_html($range) . ' · Not affiliated with the MTA</p><div class="mta-tiles">' .
        mta_more_tile(number_format(round($tot / $days)), 'crossings a day') . mta_more_tile(round($tot / 1e6) . ' million', 'crossings in 12 months') .
        mta_more_tile(round(($b['payment']['E-ZPass'] ?? 0) / max(1, array_sum($b['payment'])) * 100) . '%', 'paid with E-ZPass') . '</div></header>';

    $items = [];
    foreach ($b['facilities'] as $f => $n) {
        $items[] = [$f, $n, number_format(round($n / $days)) . ' a day', number_format($n) . ' in 12 months'];
    }
    $o .= mta_more_sec('Which MTA bridge is the busiest?', '<p>' . esc_html('The ' . $top . ' is the busiest, with about ' . number_format(round($b['facilities'][$top] / $days)) . ' crossings a day.') . '</p>' . mta_more_bars($items),
        esc_html($range) . ', both directions. The Robert F. Kennedy Bridge counts its Manhattan, Bronx and Queens crossings together.');

    $hours = $b['hours'];
    $items = [];
    foreach ($hours as $h => $n) {
        $items[] = [mta_ridership_hour_name($h), $n, number_format(round($n / $days)), ''];
    }
    $peak = array_search(max($hours), $hours, true);
    $o .= mta_more_sec('When is bridge and tunnel traffic heaviest?', '<p>' . esc_html('The busiest hour is ' . mta_ridership_hour_name($peak) . ', with about ' . number_format(round($hours[$peak] / $days)) .
        ' crossings on an average day across all 9.') . '</p>' . mta_more_bars($items), 'Average crossings per hour on an average day, all bridges and tunnels.');

    $items = [];
    foreach ($b['types'] as $t => $n) {
        $items[] = [$t === 'Bus' ? 'Buses' : $t . 's', $n, number_format(round($n / $days)) . ' a day', round($n / max(1, $tot) * 100, 1) . '% of crossings'];
    }
    $pay = [];
    foreach ($b['payment'] as $t => $n) {
        $pay[] = [$t, $n, round($n / max(1, array_sum($b['payment'])) * 100) . '%', ''];
    }
    $o .= mta_more_sec('What crosses, and how it pays', mta_more_bars($items) . mta_more_h3('How tolls are paid') . mta_more_bars($pay), esc_html($range) . '.');

    $months = $b['months'];
    if (count($months) > 1) {
        $per = [];
        foreach ($months as $m => $n) {
            $per[$m] = round($n / (int) gmdate('t', strtotime("$m-01 UTC")));
        }
        $o .= mta_more_sec('Bridge and tunnel traffic by month', mta_more_month_chart($per, 'Crossings a day by month'), 'Average crossings a day each month, all 9 bridges and tunnels.');
    }
    $o .= mta_ridership_ssr_more('bridges');
    $o .= '<p class="mta-source mta-source-ssr">Data (data.ny.gov): <a href="https://data.ny.gov/d/ebfx-2m7v" rel="noopener">MTA Bridges and Tunnels Hourly Crossings</a>. Updated ' .
        esc_html(gmdate('M j, Y', strtotime($b['updated']))) . '. For tolls, see <a href="https://new.mta.info/fares-and-tolls/bridges-and-tunnels" rel="noopener">mta.info</a>.' .
        '<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

add_shortcode('mta_bridges', 'mta_more_bridges_page');

/* ---------- search titles, descriptions and FAQ for the new pages ---------- */

function mta_more_seo()
{
    if (!mta_more_live()) {
        return null;
    }
    if (get_query_var('mta_rail_station')) { // a station page sets its own (includes/transitmore.php)
        return null;
    }
    foreach (['lirr', 'mnr'] as $k) {
        if (is_page(MTA_MORE_PAGES[$k]) && ($f = mta_more_rail_facts($k))) {
            [$short, $long] = $f['names'];
            return ['title' => $short . ' On-Time Performance & Delays (' . substr($f['x']['latest'], 0, 4) . ')',
                    'description' => $long . ' on-time rate (' . $f['otp']['otp'] . '%), late and cancelled trains by branch and cause, and ridership, from MTA open data.',
                    'faq' => mta_more_rail_faq($k, $f)];
        }
    }
    if (is_page(MTA_MORE_PAGES['pay']) && ($p = get_option('mta_more_pay'))) {
        return ['title' => 'MTA Salaries & Overtime (' . $p['year'] . '): How Much MTA Workers Make',
                'description' => 'MTA pay in ' . $p['year'] . ': median $' . number_format($p['all']['med']) . ', ' . mta_more_money($p['all']['total_ot']) . ' in overtime, best-paid jobs and pay by agency. No names.',
                'faq' => mta_more_pay_faq($p)];
    }
    if (is_page(MTA_MORE_PAGES['bridges']) && ($b = get_option('mta_more_bridges'))) {
        return ['title' => 'NYC Bridge & Tunnel Traffic: Busiest MTA Crossings',
                'description' => 'Traffic on the MTA\'s 9 bridges and tunnels: about ' . number_format(round(array_sum($b['facilities']) / max(1, $b['days']))) . ' crossings a day, the busiest crossing, busiest hours and how tolls are paid.',
                'faq' => []];
    }
    return null;
}

add_filter('wpseo_title', function ($t) { return ($s = mta_more_seo()) ? mta_ridership_seo_title($s['title']) : $t; });
add_filter('wpseo_opengraph_title', function ($t) { return ($s = mta_more_seo()) ? $s['title'] : $t; });
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($t) { return ($s = mta_more_seo()) ? mta_ridership_seo_desc($s['description']) : $t; });
}
add_action('wp_head', function () {
    if (!($s = mta_more_seo()) || !$s['faq']) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($i) {
            return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
        }, $s['faq']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});
