<?php
/**
 * Still more MTA open data, fetched once a month after the "more" step:
 *  - an Access-A-Ride (paratransit) page: trips, on-time pickups and drop-offs, ride times, no-shows, complaints,
 *    phone answering and registrants;
 *  - a page for every LIRR and Metro-North station: tickets sold there, where riders go, ticket types, how full LIRR
 *    trains are by hour, its branch's on-time rate and elevators (/lirr/<station>/, /metro-north/<station>/);
 *  - an MTA jobs page: how many people work there, what the common jobs pay, hiring and overtime hours;
 *  - an E-ZPass retailer map, a Staten Island Railway page, and more congestion pricing data (miles driven,
 *    greenhouse gas, taxi and Uber/Lyft trips, bus and traffic speeds in the zone).
 * Each part is stored in its own option and kept from last month if its fetch fails.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_TM_HOOK = 'mta_ridership_tm_step';
const MTA_TM_PAGES = ['aar' => 'access-a-ride', 'jobs' => 'mta-jobs', 'ezpass' => 'ezpass-locations', 'sir' => 'staten-island-railway'];
const MTA_TM_RAIL_SLUGS = ['lirr' => 'lirr', 'mnr' => 'metro-north']; // same as MTA_MORE_PAGES

add_action(MTA_TM_HOOK, function () { mta_ridership_run_step('tm'); });

add_action('init', function () {
    foreach (MTA_TM_RAIL_SLUGS as $slug) { // /lirr/<station>/ shows one station on the railroad page
        add_rewrite_rule('^' . $slug . '/([a-z0-9-]+)/?$', 'index.php?pagename=' . $slug . '&mta_rail_station=$matches[1]', 'top');
    }
    if (get_option('mta_tm_rewrite') !== '1') {
        flush_rewrite_rules(false);
        update_option('mta_tm_rewrite', '1');
    }
    if (!get_option('mta_tm_aar') && get_option('mta_more_stations')) {
        mta_tm_schedule(300);
    }
});
add_filter('query_vars', function ($v) {
    $v[] = 'mta_rail_station';
    return $v;
});

/** Everything in this file is in review: preview only (?mta_preview=1) until the owner says it can go live. */
function mta_tm_live()
{
    return true; // live since 2026-11-03; return mta_ridership_preview() to put it back in review
}

function mta_tm_url($key)
{
    return home_url('/' . MTA_TM_PAGES[$key] . '/');
}

function mta_tm_rail_url($k, $slug = '')
{
    return home_url('/' . MTA_TM_RAIL_SLUGS[$k] . '/' . ($slug ? $slug . '/' : ''));
}

/** The new pages (and station pages) are a 404 and out of the sitemap until they go live, and noindex while in preview. */
add_action('template_redirect', function () {
    $hide = false;
    foreach (MTA_TM_PAGES as $slug) {
        $hide = $hide || (is_page($slug) && !mta_tm_live());
    }
    $k = mta_tm_rail_key();
    if ($k && get_query_var('mta_rail_station') && !mta_tm_rail_station_current($k)) { // unknown station, or still in preview
        $hide = true;
    }
    if ($hide) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    }
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    if (mta_tm_live()) {
        return $ids;
    }
    foreach (MTA_TM_PAGES as $slug) {
        if ($p = get_page_by_path($slug, OBJECT, 'page')) {
            $ids[] = $p->ID;
        }
    }
    return $ids;
});
add_filter('wp_robots', function ($r) {
    $is = mta_tm_rail_key() && get_query_var('mta_rail_station');
    foreach (MTA_TM_PAGES as $slug) {
        $is = $is || is_page($slug);
    }
    return $is && mta_ridership_preview() ? ['noindex' => true, 'nofollow' => true] + $r : $r;
});
// Every railroad station in Yoast's page sitemap, once live.
add_filter('wpseo_sitemap_page_content', function ($xml) {
    $s = get_option('mta_tm_rail');
    if (!mta_tm_live() || mta_ridership_preview() || empty($s['stations'])) {
        return $xml;
    }
    $mod = esc_html(gmdate('c', strtotime($s['updated'])));
    foreach ($s['stations'] as $k => $list) {
        $p = get_page_by_path(MTA_TM_RAIL_SLUGS[$k], OBJECT, 'page');
        if ($p && $p->post_status === 'publish') {
            foreach ($list as $st) {
                $xml .= '<url><loc>' . esc_url(mta_tm_rail_url($k, $st['slug'])) . "</loc><lastmod>$mod</lastmod></url>\n";
            }
        }
    }
    return $xml;
});

function mta_tm_schedule($delay)
{
    if (!wp_next_scheduled(MTA_TM_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_TM_HOOK);
    }
}

function mta_tm_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }
    $parts = ['mta_tm_aar' => 'mta_tm_aar_fetch', 'mta_tm_rail' => 'mta_tm_rail_fetch', 'mta_tm_jobs' => 'mta_tm_jobs_fetch',
              'mta_tm_ezpass' => 'mta_tm_ezpass_fetch', 'mta_tm_sir' => 'mta_tm_sir_fetch', 'mta_tm_cbd' => 'mta_tm_cbd_fetch'];
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
        update_option('mta_ridership_tm_error', gmdate('c') . ' ' . implode(' · ', $errors), false);
        mta_ridership_retry_later(MTA_TM_HOOK, 'mta_tm_schedule');
    } else {
        delete_option('mta_ridership_tm_error');
    }
    if (mta_tm_live() && !mta_ridership_preview() && function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
}

/* ---------- fetching ---------- */

/** The latest month in a dataset ("2026-09"), from max() of a date column. */
function mta_tm_latest($id, $col, $where = '')
{
    $p = ['$select' => "max($col) AS m"];
    if ($where) {
        $p['$where'] = $where;
    }
    $m = mta_more_month(mta_more_api($id, $p)[0]['m'] ?? '');
    if (!$m) {
        throw new Exception("No data in $id");
    }
    return $m;
}

/** Rows grouped by one or more columns → [key => [sum columns]], keys joined with "|". */
function mta_tm_group($id, $group, array $sums, $where)
{
    $sel = [];
    foreach ($group as $i => $g) {
        $sel[] = "$g AS g$i";
    }
    foreach ($sums as $name => $expr) {
        $sel[] = "$expr AS $name";
    }
    $out = [];
    foreach (mta_more_api($id, ['$select' => implode(', ', $sel), '$where' => $where, '$group' => implode(', ', array_map(function ($i) { return "g$i"; }, array_keys($group)))]) as $r) {
        $k = [];
        foreach (array_keys($group) as $i) {
            $k[] = $r["g$i"] ?? '';
        }
        $k = implode('|', $k);
        foreach (array_keys($sums) as $name) {
            $out[$k][$name] = (float) ($r[$name] ?? 0);
        }
    }
    return $out;
}

/** The month after $m ("Y-m"). */
function mta_tm_next($m)
{
    return gmdate('Y-m', strtotime("$m-01 +1 month UTC"));
}

/** Days in the months $from..$to ("Y-m"), for "a day" averages. */
function mta_tm_days($from, $to)
{
    return (int) round((strtotime(mta_tm_next($to) . '-01 UTC') - strtotime("$from-01 UTC")) / 86400);
}

/** Access-A-Ride: trips, on-time pickups and drop-offs, ride times, no-shows, complaints, calls and registrants. */
function mta_tm_aar_fetch()
{
    $m = mta_tm_latest('hasd-hdb7', 'day');
    $from = mta_more_month("$m-01", 11);
    $w12 = function ($col) use ($from, $m) { return "$col >= '$from-01' AND $col < '" . mta_tm_next($m) . "-01'"; };
    $x = ['latest' => $m, 'from' => $from, 'days' => mta_tm_days($from, $m)];

    // Trips by month since 2016 (two datasets: to 2023, and from 2024).
    $months = [];
    foreach (['fn46-66ir', 'hasd-hdb7'] as $id) {
        foreach (mta_tm_group($id, ['date_trunc_ym(day)'], ['t' => 'sum(trips)', 'r' => 'sum(ridership)'], "day >= '2016-01-01'") as $k => $v) {
            $months[mta_more_month($k)] = ['t' => $v['t'], 'r' => $v['r']];
        }
    }
    ksort($months);
    $x['months'] = $months;
    $sum = function ($group) use ($w12) {
        $o = [];
        foreach (mta_tm_group('hasd-hdb7', [$group], ['t' => 'sum(trips)'], $w12('day')) as $k => $v) {
            if ($k !== '') {
                $o[$k] = $v['t'];
            }
        }
        arsort($o);
        return $o;
    };
    $x['providers'] = $sum('provider_type');
    $x['boroughs'] = $sum('pickup_borough');
    $hours = array_fill(0, 24, 0);
    foreach ($sum('hour_of_promise_time') as $h => $n) {
        $hours[(int) $h] = $n;
    }
    $x['hours'] = $hours;

    // Pickups on time (15, 20 and 30 minutes after the promised time), last 24 months by month, and by borough.
    $pm = mta_tm_latest('dqvw-iu8s', 'date');
    $pf = mta_more_month("$pm-01", 11);
    $x['pickup_range'] = [$pf, $pm];
    $x['pickup'] = [];
    foreach (mta_tm_group('dqvw-iu8s', ['standard', 'date_trunc_ym(date)'], ['ok' => 'sum(pickups_within_standards)', 'n' => 'sum(total_pickups)'],
        "date >= '" . mta_more_month("$pm-01", 23) . "-01'") as $k => $v) {
        [$std, $mm] = explode('|', $k);
        $x['pickup'][(int) $std][mta_more_month($mm)] = $v;
    }
    $x['pickup_boro'] = mta_tm_group('dqvw-iu8s', ['pickup_location'], ['ok' => 'sum(pickups_within_standards)', 'n' => 'sum(total_pickups)'],
        "standard = '20-minutes' AND date >= '$pf-01'");

    // Drop-offs for appointments: on time (up to 30 minutes early, not late), early or late.
    $dm = mta_tm_latest('f84c-ude3', 'date');
    $df = mta_more_month("$dm-01", 11);
    $x['dropoff_range'] = [$df, $dm];
    $x['dropoff'] = [];
    foreach (mta_tm_group('f84c-ude3', ['arrival_time'], ['n' => 'sum(trips_within_arrival_time)'], "date >= '$df-01'") as $k => $v) {
        $x['dropoff'][$k] = $v['n'];
    }

    // Ride times by trip length.
    $rm = mta_tm_latest('w9xt-anps', 'trip_date');
    $rf = mta_more_month("$rm-01", 11);
    $x['ride_range'] = [$rf, $rm];
    $x['ride'] = [];
    foreach (mta_tm_group('w9xt-anps', ['distance', 'within_max_ride_time'], ['n' => 'sum(total_trips)', 'min' => 'sum(total_ride_time_min)'], "trip_date >= '$rf-01'") as $k => $v) {
        [$d, $ok] = explode('|', $k);
        $r = $x['ride'][$d] ?? ['n' => 0, 'min' => 0, 'ok' => 0];
        $r['n'] += $v['n'];
        $r['min'] += $v['min'];
        if (strtolower($ok) === 'true') {
            $r['ok'] += $v['n'];
        }
        $x['ride'][$d] = $r;
    }

    // Small monthly tables: no-shows per 1,000 trips, the "customer experience" rate, calls and registrants.
    $monthly = function ($id, $col, $value) {
        $o = [];
        foreach (mta_more_api($id, ['$where' => "$col >= '" . mta_more_month(gmdate('Y-m-01'), 26) . "-01'"]) as $r) {
            $o[$r['provider_type'] ?? ($r['call_type'] ?? '')][mta_more_month($r[$col])] = $value($r);
        }
        foreach ($o as &$s) {
            ksort($s);
        }
        return $o;
    };
    $x['noshows'] = $monthly('mdy9-kvbb', 'month', function ($r) { return (float) $r['provider_no_shows']; });
    $x['experience'] = $monthly('suhb-73ts', 'date', function ($r) { return (float) $r['customer_experience'] * 100; });
    $x['calls'] = $monthly('6r5t-chrj', 'month', function ($r) {
        return ['in' => (int) $r['number_of_calls_received'], 'up' => (int) $r['number_of_calls_picked_up'], 'speed' => (float) $r['average_answer_speed']];
    });
    $rg = mta_tm_latest('cxps-6wkq', 'month');
    $x['registrants_month'] = $rg;
    $x['registrants'] = [];
    foreach (mta_more_api('cxps-6wkq', ['$where' => "month = '$rg-01'"]) as $r) {
        $x['registrants'][$r['location']] = (int) $r['registrants'];
    }
    arsort($x['registrants']);
    $x['registrants_series'] = [];
    foreach (mta_tm_group('cxps-6wkq', ['month'], ['n' => 'sum(registrants)'], "month >= '2018-01-01'") as $k => $v) {
        $x['registrants_series'][mta_more_month($k)] = $v['n'];
    }
    ksort($x['registrants_series']);

    // Complaints by category.
    $cm = mta_tm_latest('re4e-8rv8', 'date');
    $cf = mta_more_month("$cm-01", 11);
    $x['complaints_range'] = [$cf, $cm];
    $x['complaints'] = [];
    foreach (mta_tm_group('re4e-8rv8', ['category'], ['n' => 'sum(complaints::number)'], "date >= '$cf-01'") as $k => $v) {
        if ($k !== '') {
            $x['complaints'][$k] = $v['n'];
        }
    }
    arsort($x['complaints']);
    $x['complaint_trips'] = 0;
    foreach ($months as $mm => $v) {
        if ($mm >= $cf && $mm <= $cm) {
            $x['complaint_trips'] += $v['t'];
        }
    }
    return $x;
}

/** "Penn Station" → "penn-station"; the railroad's stations with tickets, crowding, branch on-time rates and elevators. */
function mta_tm_rail_fetch()
{
    $stations = ['lirr' => [], 'mnr' => []];
    $rr = ['LIRR' => 'lirr', 'MNR' => 'mnr'];
    foreach (mta_more_api('wxmd-5cpm', []) as $r) {
        $k = $rr[$r['railroad'] ?? ''] ?? null;
        if (!$k || empty($r['code'])) {
            continue;
        }
        $stations[$k][$r['code']] = ['code' => $r['code'], 'name' => $r['station_name'], 'branch' => $r['branch'] ?? '', 'zone' => $r['zone'] ?? '',
            'access' => $r['accessibility'] ?? '', 'lat' => isset($r['latitude']) ? (float) $r['latitude'] : null, 'lon' => isset($r['longitude']) ? (float) $r['longitude'] : null,
            'url' => is_array($r['station_url'] ?? null) ? ($r['station_url']['url'] ?? '') : '', 'in' => $r['inbound_title'] ?? '', 'out' => $r['outbound_title'] ?? ''];
    }
    if (!$stations['lirr'] || !$stations['mnr']) {
        throw new Exception('No railroad stations returned');
    }
    foreach ($stations as &$list) {
        $used = [];
        uasort($list, function ($a, $b) { return strcmp($a['name'], $b['name']); });
        foreach ($list as &$s) {
            $slug = sanitize_title($s['name']);
            if (isset($used[$slug])) {
                $slug .= '-' . strtolower($s['code']);
            }
            $used[$slug] = true;
            $s['slug'] = $slug;
        }
        unset($s);
    }
    unset($list);

    // Ticket sales: the last 12 months and the 12 before, by origin station; destinations and ticket types for the last 12.
    $tm = mta_tm_latest('5ypk-fceg', 'date', "railroad IN ('LIRR', 'MNR')");
    $tf = mta_more_month("$tm-01", 11);
    $tp = mta_more_month("$tm-01", 23);
    $end = mta_tm_next($tm);
    $w = "railroad IN ('LIRR', 'MNR') AND date < '$end-01' AND ";
    $t = ['latest' => $tm, 'from' => $tf, 'days' => mta_tm_days($tf, $tm)];
    foreach (['tickets' => "date >= '$tf-01'", 'tickets_before' => "date >= '$tp-01' AND date < '$tf-01'"] as $field => $when) {
        $sql = $field === 'tickets' ? $w . $when : "railroad IN ('LIRR', 'MNR') AND " . $when;
        foreach (mta_tm_group('5ypk-fceg', ['railroad', 'origin_station_code'], ['n' => 'sum(number_of_tickets)'], $sql) as $key => $v) {
            [$r, $c] = explode('|', $key);
            if (isset($rr[$r], $stations[$rr[$r]][$c])) {
                $stations[$rr[$r]][$c][$field] = $v['n'];
            }
        }
    }
    foreach (mta_tm_group('5ypk-fceg', ['railroad', 'destination_station_code'], ['n' => 'sum(number_of_tickets)'], $w . "date >= '$tf-01'") as $key => $v) {
        [$r, $c] = explode('|', $key);
        if (isset($rr[$r], $stations[$rr[$r]][$c])) {
            $stations[$rr[$r]][$c]['tickets_to'] = $v['n'];
        }
    }
    $dest = [];
    foreach (mta_tm_group('5ypk-fceg', ['railroad', 'origin_station_code', 'destination_station_code'], ['n' => 'sum(number_of_tickets)'], $w . "date >= '$tf-01'") as $key => $v) {
        [$r, $o, $d] = explode('|', $key);
        if (isset($rr[$r]) && $d !== '' && $d !== $o) {
            $dest[$rr[$r]][$o][$d] = $v['n'];
        }
    }
    foreach ($dest as $k => $by) {
        foreach ($by as $o => $ds) {
            if (isset($stations[$k][$o])) {
                arsort($ds);
                $stations[$k][$o]['dest'] = array_slice($ds, 0, 8, true);
            }
        }
    }
    foreach (mta_tm_group('5ypk-fceg', ['railroad', 'origin_station_code', 'sold_as'], ['n' => 'sum(number_of_tickets)'], $w . "date >= '$tf-01'") as $key => $v) {
        [$r, $o, $s] = explode('|', $key);
        if (isset($rr[$r], $stations[$rr[$r]][$o])) {
            $stations[$rr[$r]][$o]['sold_as'][$s ?: 'other'] = $v['n'];
        }
    }
    foreach (mta_tm_group('5ypk-fceg', ['railroad', 'origin_station_code', 'fare_type'], ['n' => 'sum(number_of_tickets)'], $w . "date >= '$tf-01'") as $key => $v) {
        [$r, $o, $f] = explode('|', $key);
        if (isset($rr[$r], $stations[$rr[$r]][$o])) {
            $stations[$rr[$r]][$o]['fare'][$f ?: 'other'] = $v['n'];
        }
    }
    foreach ($stations as $k => &$list) {
        $rank = array_filter(array_column($list, 'tickets', 'code'));
        arsort($rank);
        $i = 0;
        foreach ($rank as $c => $n) {
            $list[$c]['rank'] = ++$i;
        }
    }
    unset($list);

    // How full LIRR electric trains are when they leave each station, weekdays by hour and direction (last 3 months).
    $cm = mta_tm_latest('hb2b-cimm', 'service_date');
    $cf = mta_more_month("$cm-01", 2);
    $t['crowd_range'] = [$cf, $cm];
    foreach (mta_tm_group('hb2b-cimm', ['stop_code', 'direction', 'date_extract_hh(scheduled_time)'], ['n' => 'count(*)', 'occ' => 'avg(train_occupancy)', 'pax' => 'avg(total_passengers)'],
        "service_date >= '$cf-01' AND missing_load_data = '0' AND date_extract_dow(service_date) BETWEEN 1 AND 5") as $key => $v) {
        [$c, $dir, $h] = explode('|', $key);
        if (isset($stations['lirr'][$c]) && $v['n'] >= 5) {
            $stations['lirr'][$c]['crowd'][$dir === '1' ? 'in' : 'out'][(int) $h] = [round($v['occ'] * 100), round($v['pax']), (int) $v['n']];
        }
    }
    $branch_names = ['BY' => 'Babylon', 'CI' => 'City Terminal Zone', 'FR' => 'Far Rockaway', 'HM' => 'Hempstead', 'HH' => 'Huntington', 'LB' => 'Long Beach',
                     'MK' => 'Montauk', 'OB' => 'Oyster Bay', 'PJ' => 'Port Jefferson', 'RK' => 'Ronkonkoma', 'WH' => 'West Hempstead', 'PW' => 'Port Washington'];

    // Trains arriving at Jamaica on time, by branch and time of day (last 12 months).
    $jm = mta_tm_latest('hv6n-yxrc', 'month');
    $jf = mta_more_month("$jm-01", 11);
    $t['jamaica_range'] = [$jf, $jm];
    $t['jamaica'] = [];
    foreach (mta_tm_group('hv6n-yxrc', ['branch_id', 'peak_offpeak'], ['ok' => 'sum(number_trains_on_time)', 'n' => 'sum(number_scheduled_trips)'], "month >= '$jf-01'") as $key => $v) {
        [$b, $p] = explode('|', $key);
        $t['jamaica'][$branch_names[$b] ?? $b][$p] = $v['n'] ? round($v['ok'] / $v['n'] * 100, 1) : null;
    }

    // Elevator and escalator availability by branch (last 12 months).
    $t['elevators'] = ['lirr' => [], 'mnr' => []];
    $em = mta_tm_latest('9hjt-526f', 'rundate');
    foreach (mta_tm_group('9hjt-526f', ['branch', 'asset_type'], ['a' => 'avg(availability)'], "rundate >= '" . mta_more_month("$em-01", 11) . "-01'") as $key => $v) {
        [$b, $a] = explode('|', $key);
        $t['elevators']['lirr'][$b][strtolower($a)] = round($v['a'] * 100, 1);
    }
    $em = mta_tm_latest('ax67-8386', 'month');
    foreach (mta_tm_group('ax67-8386', ['branch'], ['e' => 'avg(elevator_availability)', 's' => 'avg(escalator_availability)'], "month >= '" . mta_more_month("$em-01", 11) . "-01'") as $b => $v) {
        $t['elevators']['mnr'][$b] = ['elevator' => round($v['e'] * 100, 1), 'escalator' => $v['s'] ? round($v['s'] * 100, 1) : null];
    }
    $t['elevators_month'] = $em;
    return ['stations' => array_map('array_values', $stations)] + $t;
}

/** One SoQL query (for chained "|>" queries the plain parameters can't express). */
function mta_tm_soql($id, $soql)
{
    $res = wp_remote_get(add_query_arg(['$query' => rawurlencode($soql)], "https://data.ny.gov/resource/$id.json"),
        ['timeout' => 300, 'headers' => ($t = get_option('mta_ridership_app_token')) ? ['X-App-Token' => $t] : []]);
    if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
        throw new Exception("$id query failed: " . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res)));
    }
    return json_decode(wp_remote_retrieve_body($res), true);
}

/**
 * The payroll spells one job many ways ("Bus Operator(Revenue Vehicle)", "Bus Oper(Rev Veh)TWU L100 01-F"):
 * a SoQL case() that gives the common ones a plain name, in order (the first match wins). Others keep their title.
 */
function mta_tm_job_rules()
{
    return [['Train operator (yard)', ['TRAIN OPERATOR (YARD']], ['Train operator', ['TRAIN OPERATOR']], ['Bus operator', ['BUS OPER']],
            ['Flagging conductor (track work)', ['CONDUCTOR (CONSTR', 'CONDUCTOR(CONSTR']], ['Conductor', ['CONDUCTOR']], ['Assistant conductor', ['ASSISTANT CONDUCTOR']],
            ['Cleaner', ['CLEANER', 'COACH CLEANER', 'CAR APPEARANCE', 'STATION APPEAR']], ['Station agent', ['STATION AGENT']],
            ['Track worker (specialist)', ['TRACK WORKER (SPECIALIST']], ['Track worker', ['TRACK WORKER']], ['Car inspector', ['CAR INSPECTOR', 'ROAD CAR INSPECTOR']],
            ['Police officer', ['POLICE OFFICER']], ['Signal maintainer', ['SIGNAL MAINTAINER']], ['Bus maintainer (mechanic)', ['BUS MAINTAINER']],
            ['Structure maintainer', ['STRUCTURE MAINTAINER']], ['Locomotive engineer', ['LOCOMOTIVE ENGINEER', '=ENGINEER']], ['Electrical helper', ['TA ELECTR HELPER']],
            ['Power distribution maintainer', ['POWER DIST']], ['Dispatcher', ['DISPATCHER', 'ASST. TRAIN DISPATCHER']], ['Bridge and tunnel officer', ['=BTO']],
            ['Maintenance supervisor', ['MTCE.SUPV']], ['Light maintainer', ['LIGHT MAINTAINER']], ['Telephone maintainer', ['TELEPHONE MAINTAINER']], ['Tower operator', ['TOWER OPERATOR']]];
}

function mta_tm_job_case($col)
{
    $parts = [];
    foreach (mta_tm_job_rules() as [$name, $prefixes]) {
        $conds = array_map(function ($p) use ($col) {
            return $p[0] === '=' ? "upper($col) = '" . substr($p, 1) . "'" : "starts_with(upper($col), '$p')";
        }, $prefixes);
        $parts[] = implode(' OR ', $conds) . ", '$name'";
    }
    return 'case(' . implode(', ', $parts) . ", true, $col)";
}

/** A payroll title without its union and class codes: "Supervisor Station I 01-F" → "Supervisor Station I". */
function mta_tm_job_clean($t)
{
    return trim(preg_replace(['/\s*\b(TWU|ATU)\b.*$/i', '/\s+\d{2}-[A-Z]\s*$/'], '', $t));
}

/** MTA jobs: positions by agency and group, the common job titles and their pay, hires and departures, overtime hours. */
function mta_tm_jobs_fetch()
{
    $m = mta_tm_latest('ui26-n8p4', 'month', "scenario = 'Actual'");
    $x = ['latest' => $m, 'agencies' => [], 'groups' => [], 'series' => []];
    foreach (mta_tm_group('ui26-n8p4', ['agency'], ['p' => 'sum(positions)'], "scenario = 'Actual' AND month = '$m-01'") as $k => $v) {
        $x['agencies'][$k] = round($v['p']);
    }
    arsort($x['agencies']);
    foreach (mta_tm_group('ui26-n8p4', ['occupational_group'], ['p' => 'sum(positions)'], "scenario = 'Actual' AND month = '$m-01'") as $k => $v) {
        $x['groups'][$k] = round($v['p']);
    }
    arsort($x['groups']);
    foreach (mta_tm_group('ui26-n8p4', ['scenario', 'month'], ['p' => 'sum(positions)'], "month <= '$m-01'") as $k => $v) {
        [$s, $mm] = explode('|', $k);
        $x['series'][$s === 'Actual' ? 'actual' : 'budget'][mta_more_month($mm)] = round($v['p']);
    }
    foreach ($x['series'] as &$s) {
        ksort($s);
    }
    unset($s);

    // Job titles with 25 or more people in the latest full payroll year: typical pay and overtime. No names.
    $year = (get_option('mta_more_pay')['year'] ?? '') ?: (string) ((int) gmdate('Y') - 1);
    $total = 'regular_pay + overtime_pay + overtime_bonus_pay + cash_outs + retro_pay + other_pay';
    // Only people who held the title for 10 or more of the year's 12 monthly pay records: titles change during the year,
    // and a person's pay split across two titles would make both look badly paid.
    $people = "SELECT name, paying_agency AS a, working_title AS t, sum($total) AS pay, sum(overtime_pay + overtime_bonus_pay) AS otpay, count(distinct balance_period) AS months " .
        "WHERE balance_year = '$year' GROUP BY name, a, t";
    $x['year'] = $year;
    $x['titles'] = array_map(function ($r) {
        return ['title' => mta_tm_job_clean($r['job'] ?? ''), 'agency' => $r['a'] ?? '', 'people' => (int) $r['people'], 'med_pay' => round((float) ($r['med_pay'] ?? 0)), 'avg_ot' => round((float) ($r['avg_ot'] ?? 0))];
    }, mta_tm_soql('kcjb-nf3e', "$people |> SELECT " . mta_tm_job_case('t') . " AS job, a, count(*) AS people, median(pay) AS med_pay, avg(otpay) AS avg_ot WHERE months >= 10 " .
        'GROUP BY job, a HAVING people >= 25 ORDER BY people DESC LIMIT 80'));

    // New hires by year, and people working at the end of each year. (Separations are left out: added to the hires
    // they don't match the change in people working, so they can't be read as "people who left".)
    $x['hires'] = [];
    $x['hires_latest'] = mta_tm_latest('s9d4-b4kt', 'month');
    foreach (mta_tm_group('s9d4-b4kt', ['date_extract_y(month)'], ['hired' => 'sum(new_hires)'], "month >= '2013-01-01'") as $y => $v) {
        $x['hires'][(int) $y] = ['in' => round($v['hired']), 'staff' => null];
    }
    foreach (mta_tm_group('s9d4-b4kt', ['date_extract_y(month)'], ['n' => 'sum(employee_count)'],
        "month >= '2013-01-01' AND (date_extract_m(month) = 12 OR month = '{$x['hires_latest']}-01')") as $y => $v) {
        if (isset($x['hires'][(int) $y])) {
            $x['hires'][(int) $y]['staff'] = round($v['n']);
        }
    }
    ksort($x['hires']);

    // Overtime and regular hours by year (full years only).
    $om = mta_tm_latest('jkya-6vhd', 'month');
    $x['overtime'] = [];
    foreach (mta_tm_group('jkya-6vhd', ['date_extract_y(month)'], ['ot' => 'sum(total_overtime)', 'rt' => 'sum(regular_time)'], "month < '" . substr($om, 0, 4) . "-01-01'") as $y => $v) {
        $x['overtime'][(int) $y] = ['ot' => round($v['ot']), 'rt' => round($v['rt'])];
    }
    ksort($x['overtime']);
    return $x;
}

/** "AAA MANHATTAN" → "AAA Manhattan", "233RD STREET" → "233rd Street": the list is in capitals. */
function mta_tm_title_case($t)
{
    return preg_replace_callback("/[A-Za-z0-9&'.]+/", function ($m) {
        $w = $m[0];
        if (in_array(strtoupper($w), ['AAA', 'DMV', 'MVO', 'NY', 'NJ', 'CT', 'PA', 'LLC', 'II', 'III', 'NE', 'NW', 'SE', 'SW', 'US'], true)) {
            return strtoupper($w);
        }
        return ucfirst(strtolower($w));
    }, $t);
}

/** The places that sell E-ZPass tags (the state's snapshot list) and when the list was last updated. */
function mta_tm_ezpass_fetch()
{
    $out = [];
    foreach (mta_more_api('y59h-w6v4', ['$order' => 'state, city, company']) as $r) {
        $c = $r['georeference']['coordinates'] ?? null;
        $out[] = ['name' => mta_tm_title_case(trim($r['company'] ?? '')), 'street' => mta_tm_title_case(trim(($r['street_1'] ?? '') . (empty($r['street_2']) ? '' : ', ' . $r['street_2']))),
                  'city' => ucwords(strtolower(trim($r['city'] ?? ''))), 'state' => $r['state'] ?? '', 'zip' => preg_match('/^\d{3,4}$/', $z = substr($r['zip_code'] ?? '', 0, 5)) ? str_pad($z, 5, '0', STR_PAD_LEFT) : $z, // the data drops leading zeros (CT, NJ)
                  'lat' => $c ? (float) $c[1] : null, 'lon' => $c ? (float) $c[0] : null];
    }
    if (!$out) {
        throw new Exception('No E-ZPass retailers returned');
    }
    $meta = wp_remote_get('https://data.ny.gov/api/views/y59h-w6v4.json', ['timeout' => 60]);
    $as_of = null;
    if (!is_wp_error($meta) && wp_remote_retrieve_response_code($meta) === 200) {
        $d = json_decode(wp_remote_retrieve_body($meta), true);
        $as_of = !empty($d['rowsUpdatedAt']) ? gmdate('Y-m-d', (int) $d['rowsUpdatedAt']) : null;
    }
    return ['places' => $out, 'as_of' => $as_of];
}

/** Staten Island Railway: on-time rates, scheduled trips, distance between breakdowns, riders and stations. */
function mta_tm_sir_fetch()
{
    $m = mta_tm_latest('fccm-griq', 'month');
    $x = ['latest' => $m, 'from' => mta_more_month("$m-01", 11), 'otp' => []];
    foreach (mta_more_api('fccm-griq', ['$where' => "month >= '" . mta_more_month("$m-01", 23) . "-01'"]) as $r) {
        $x['otp'][$r['day_time']][mta_more_month($r['month'])] = ['ok' => (int) $r['on_time_trips'], 'n' => (int) $r['scheduled_trips'], 'late' => (int) $r['delayed_trains']];
    }
    $x['trips'] = [];
    $tm = mta_tm_latest('k3aj-27se', 'month');
    foreach (mta_more_api('k3aj-27se', ['$where' => "month = '$tm-01'"]) as $r) {
        if (is_numeric($r['trips'] ?? '')) {
            $x['trips'][$r['time']] = (int) $r['trips'];
        }
    }
    $x['mdbf'] = [];
    foreach (mta_more_api('n2pf-h43s', ['$where' => "month >= '2015-01-01'", '$order' => 'month']) as $r) {
        if (is_numeric($r['_12_month_average_mdbf'] ?? '')) {
            $x['mdbf'][mta_more_month($r['month'])] = round((float) $r['_12_month_average_mdbf']);
        }
    }
    $rid = [];
    foreach (mta_more_api('sayj-mze2', ['$select' => 'date_trunc_ym(date) AS m, sum(count) AS n, count(*) AS days',
        '$where' => "mode = 'SIR' AND date >= '" . mta_more_month(gmdate('Y-m-01'), 25) . "-01'", '$group' => 'm']) as $r) {
        $rid[mta_more_month($r['m'])] = ['n' => (int) $r['n'], 'days' => (int) $r['days']];
    }
    ksort($rid);
    $x['riders'] = $rid;
    $x['stations'] = [];
    foreach (mta_more_api('39hk-dx4f', ['$select' => 'stop_name, ada, gtfs_latitude AS lat', '$where' => "daytime_routes = 'SIR'"]) as $r) {
        $x['stations'][] = ['name' => $r['stop_name'], 'ada' => (string) ($r['ada'] ?? '0'), 'lat' => (float) ($r['lat'] ?? 0)];
    }
    usort($x['stations'], function ($a, $b) { return $b['lat'] <=> $a['lat']; }); // St. George (north) first
    return $x;
}

/** Congestion pricing zone: miles driven, greenhouse gas, taxi and Uber/Lyft trips, bus speeds and traffic speeds. */
function mta_tm_cbd_fetch()
{
    $x = ['vmt' => [], 'ghg' => [], 'trips' => [], 'bus' => [], 'speed' => []];
    foreach (mta_more_api('jbxx-kxff', ['$order' => 'date']) as $r) {
        $x['vmt'][$r['area']][mta_more_month($r['date'])] = round((float) $r['indexed_average_vmt'], 1);
    }
    foreach (mta_more_api('c72y-2ai5', ['$order' => 'date']) as $r) {
        $x['ghg'][mta_more_month($r['date'])] = ['label' => $r['time_period'], 'v' => round((float) $r['indexed_average_ghg'], 1)];
    }
    foreach (mta_tm_group('d3qp-nhf8', ['date_trunc_ym(month)', 'service_type'], ['n' => 'sum(trips)'], "cbd_relation = 'Entering CBD' AND month >= '2023-01-01'") as $k => $v) {
        [$mm, $s] = explode('|', $k);
        $kind = in_array($s, ['yellow', 'green'], true) ? 'taxi' : ($s === 'fhvhv' ? 'app' : 'car');
        $mm = mta_more_month($mm);
        $x['trips'][$kind][$mm] = ($x['trips'][$kind][$mm] ?? 0) + $v['n'];
    }
    foreach ($x['trips'] as &$s) {
        ksort($s);
    }
    unset($s);
    foreach (mta_tm_group('r6db-kkzj', ['date_trunc_ym(month)', 'cbd_relation'], ['mi' => 'sum(sum_mileage::number)', 'h' => 'sum(sum_time::number)'], "month >= '2023-01-01'") as $k => $v) {
        [$mm, $rel] = explode('|', $k);
        if ($v['h'] > 0) {
            $x['bus'][$rel === 'CBD' ? 'in' : 'out'][mta_more_month($mm)] = round($v['mi'] / $v['h'], 2);
        }
    }
    foreach ($x['bus'] as &$s) {
        ksort($s);
    }
    unset($s);
    foreach (mta_more_api('6p29-6xqn', ['$order' => 'month']) as $r) {
        $x['speed'][$r['zone']][mta_more_month($r['month'])] = round((float) $r['zonal_speed'], 2);
    }
    if (!$x['vmt'] && !$x['trips']) {
        throw new Exception('No congestion zone data returned');
    }
    return $x;
}

/* ---------- shared pieces ---------- */

function mta_tm_css()
{
    wp_enqueue_style('mta-ridership', plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/mta-ridership.css', [], '1.16.12');
}

function mta_tm_pct($a, $b, $dp = 1)
{
    return $b ? round($a / $b * 100, $dp) : null;
}

/** Change from $b to $a in words: "12% more" / "3.4% fewer". */
function mta_tm_change($a, $b, $more = 'more', $fewer = 'fewer')
{
    if (!$b) {
        return '';
    }
    $p = round(($a / $b - 1) * 100, 1);
    return abs($p) . '% ' . ($p >= 0 ? $more : $fewer);
}

function mta_tm_faq_html(array $faq)
{
    $o = '';
    foreach ($faq as [$q, $a]) {
        $o .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($a) . '</p></details>';
    }
    return $o ? '<section class="mta-section mta-faq"><' . mta_ridership_h() . '>Questions</' . mta_ridership_h() . '>' . $o . '</section>' : '';
}

/** "Data (data.ny.gov): ..." line with the update date, source code and licence. */
function mta_tm_source(array $ids, $updated, $extra = '')
{
    $links = [];
    foreach ($ids as $id => $t) {
        $links[] = '<a href="https://data.ny.gov/d/' . esc_attr($id) . '" rel="noopener">' . esc_html($t) . '</a>';
    }
    return '<p class="mta-source mta-source-ssr">Data (data.ny.gov): ' . implode(', ', $links) . '. Updated ' . esc_html(gmdate('M j, Y', strtotime($updated))) . '.' . $extra .
        '<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
}

/** The 12 months up to and including $to, from a [Y-m => v] series. */
function mta_tm_last12(array $series, $to)
{
    $from = mta_more_month("$to-01", 11);
    return array_filter($series, function ($m) use ($from, $to) { return $m >= $from && $m <= $to; }, ARRAY_FILTER_USE_KEY);
}

/* ---------- Access-A-Ride page ---------- */

function mta_tm_aar_facts()
{
    $x = get_option('mta_tm_aar');
    if (!$x || empty($x['months'])) {
        return null;
    }
    $trips12 = array_sum(array_column(mta_tm_last12($x['months'], $x['latest']), 't'));
    $prev = [];
    foreach ($x['months'] as $m => $v) {
        if ($m >= mta_more_month($x['from'] . '-01', 12) && $m < $x['from']) {
            $prev[] = $v['t'];
        }
    }
    $p20 = mta_tm_last12($x['pickup'][20] ?? [], $x['pickup_range'][1]);
    $on20 = mta_tm_pct(array_sum(array_column($p20, 'ok')), array_sum(array_column($p20, 'n')));
    $prev20 = array_filter($x['pickup'][20] ?? [], function ($m) use ($x) { return $m < $x['pickup_range'][0]; }, ARRAY_FILTER_USE_KEY);
    $drop_total = array_sum($x['dropoff']);
    $drop_ok = 0;
    foreach ($x['dropoff'] as $k => $n) {
        if (stripos($k, 'on-time') !== false) {
            $drop_ok += $n;
        }
    }
    $calls = ['in' => 0, 'up' => 0, 'wait' => 0];
    foreach ($x['calls'] as $type => $s) {
        foreach (array_slice($s, -12, 12, true) as $v) {
            $calls['in'] += $v['in'];
            $calls['up'] += $v['up'];
            $calls['wait'] += $v['speed'] * $v['in'];
        }
    }
    $ride_n = array_sum(array_column($x['ride'], 'n'));
    return ['x' => $x, 'per_day' => round($trips12 / max(1, $x['days'])), 'trips12' => $trips12,
            'chg' => count($prev) === 12 ? mta_tm_change($trips12, array_sum($prev)) : '',
            'on20' => $on20, 'on20_before' => mta_tm_pct(array_sum(array_column($prev20, 'ok')), array_sum(array_column($prev20, 'n'))),
            'on' => array_map(function ($std) use ($x) {
                $s = mta_tm_last12($x['pickup'][$std] ?? [], $x['pickup_range'][1]);
                return mta_tm_pct(array_sum(array_column($s, 'ok')), array_sum(array_column($s, 'n')));
            }, [15 => 15, 20 => 20, 30 => 30]),
            'drop_on' => mta_tm_pct($drop_ok, $drop_total), 'calls' => $calls, 'answered' => mta_tm_pct($calls['up'], $calls['in'], 0),
            'wait' => $calls['in'] ? round($calls['wait'] / $calls['in']) : null,
            'ride_avg' => $ride_n ? round(array_sum(array_column($x['ride'], 'min')) / $ride_n) : null,
            'ride_ok' => mta_tm_pct(array_sum(array_column($x['ride'], 'ok')), $ride_n),
            'registrants' => array_sum($x['registrants']), 'range' => mta_more_range($x['from'], $x['latest'])];
}

function mta_tm_aar_provider($p)
{
    $n = ['Primary' => 'Primary (Access-A-Ride vans)', 'Broker' => 'Broker (car services)', 'Taxi' => 'Taxis', 'E-Hail Pilot' => 'E-Hail (app-booked taxis)',
          'Supplemental' => 'Supplemental (extra car services)', 'Contingency' => 'Contingency (backup providers)'];
    return $n[$p] ?? $p;
}

function mta_tm_aar_faq($f)
{
    $x = $f['x'];
    $out = [['How many people use Access-A-Ride?', 'Access-A-Ride gave about ' . number_format($f['per_day']) . ' trips a day from ' . $f['range'] . ' (' . number_format(round($f['trips12'])) .
        ' in all)' . ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before' : '') . '. ' . number_format($f['registrants']) . ' people were registered in ' .
        gmdate('F Y', strtotime($x['registrants_month'] . '-01 UTC')) . '.']];
    if ($f['on20'] !== null) {
        $out[] = ['Is Access-A-Ride on time?', $f['on20'] . '% of pickups came within 20 minutes of the promised time (the MTA\'s standard), ' . mta_more_range(...$x['pickup_range']) .
            '. ' . $f['on'][15] . '% came within 15 minutes and ' . $f['on'][30] . '% within 30 minutes.' .
            ($f['drop_on'] !== null ? ' For trips to an appointment, ' . $f['drop_on'] . '% arrived on time (no more than 30 minutes early and not late).' : '')];
    }
    if ($f['ride_avg']) {
        $out[] = ['How long is an Access-A-Ride trip?', 'The average trip took ' . $f['ride_avg'] . ' minutes, and ' . $f['ride_ok'] . '% of trips stayed within the maximum ride time for their distance (' .
            mta_more_range(...$x['ride_range']) . ').'];
    }
    if ($f['answered'] !== null) {
        $out[] = ['How long does Access-A-Ride take to answer the phone?', 'Over the last 12 months of data, ' . $f['answered'] . '% of calls were answered, after an average wait of about ' .
            ($f['wait'] >= 90 ? round($f['wait'] / 60, 1) . ' minutes' : $f['wait'] . ' seconds') . '.'];
    }
    if ($x['complaints']) {
        $top = array_key_first($x['complaints']);
        $out[] = ['What do Access-A-Ride riders complain about most?', 'The most common complaint category was "' . $top . '" (' . number_format($x['complaints'][$top]) . ' complaints, ' .
            mta_more_range(...$x['complaints_range']) . '), out of ' . number_format(array_sum($x['complaints'])) . ' complaints in all.'];
    }
    return $out;
}

function mta_tm_aar_page()
{
    if (!mta_tm_live()) {
        return '';
    }
    $f = mta_tm_aar_facts();
    if (!$f) {
        return '<p>Access-A-Ride data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $x = $f['x'];
    $o = '<header class="mta-hero"><h1>Access-A-Ride (NYC Paratransit): On-Time Rates, Trips &amp; Complaints</h1>' .
        '<p class="mta-hero-links">An Access-A-Ride report card from MTA open data, ' . esc_html($f['range']) . ', updated monthly · Not affiliated with the MTA</p><div class="mta-tiles">' .
        mta_more_tile(number_format($f['per_day']), 'trips a day') . ($f['on20'] !== null ? mta_more_tile($f['on20'] . '%', 'pickups on time') : '') .
        mta_more_tile(number_format($f['registrants']), 'people registered') . ($f['answered'] !== null ? mta_more_tile($f['answered'] . '%', 'of calls answered') : '') . '</div></header>';
    $o .= '<p class="mta-note">To book or check a trip, apply or find the phone number, go to the MTA\'s <a href="https://www.mta.info/accessibility/access-a-ride" rel="noopener">Access-A-Ride page</a>. ' .
        'This page shows how the service performs, from the MTA\'s own data.</p>';

    $per = [];
    foreach ($x['months'] as $m => $v) {
        $per[$m] = round($v['t'] / (int) gmdate('t', strtotime("$m-01 UTC")));
    }
    $items = [];
    foreach ($x['boroughs'] as $b => $n) {
        if ($n / max(1, $x['days']) < 1) {
            continue;
        }
        $items[] = [$b === 'Other' ? 'Outside the city / unknown' : $b, $n, number_format(round($n / max(1, $x['days']))) . ' a day', round($n / max(1, $f['trips12']) * 100, 1) . '% of trips'];
    }
    $prov = [];
    foreach ($x['providers'] as $p => $n) {
        $prov[] = [mta_tm_aar_provider($p), $n, round($n / max(1, $f['trips12']) * 100, 1) . '%', ''];
    }
    $o .= mta_more_sec('How many people use Access-A-Ride?', '<p>' . esc_html('Access-A-Ride gave about ' . number_format($f['per_day']) . ' trips a day from ' . $f['range'] .
        ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before' : '') . '.') . '</p>' . mta_more_h3('Trips a day, by month') . mta_more_month_chart($per, 'Access-A-Ride trips a day by month') .
        mta_more_h3('Where trips start') . mta_more_bars($items) . mta_more_h3('Who drives') . mta_more_bars($prov),
        'Trips given to registered riders (personal care attendants and guests riding along are not counted). Access-A-Ride uses its own contracted vans and also books car services and taxis.');

    $hours = $x['hours'];
    $items = [];
    foreach ($hours as $h => $n) {
        $items[] = [mta_ridership_hour_name($h), $n, number_format(round($n / max(1, $x['days']))), ''];
    }
    $peak = array_search(max($hours), $hours, true);
    $o .= mta_more_sec('When do Access-A-Ride trips happen?', '<p>' . esc_html('The busiest hour for pickups is ' . mta_ridership_hour_name($peak) . ', with about ' .
        number_format(round($hours[$peak] / max(1, $x['days']))) . ' trips on an average day.') . '</p>' . mta_more_bars($items), 'Trips by the hour of the promised pickup time, average day.');

    if ($f['on20'] !== null) {
        $series = [];
        foreach ($x['pickup'][20] as $m => $v) {
            $series[$m] = mta_tm_pct($v['ok'], $v['n']);
        }
        $rows = [];
        $boro = $x['pickup_boro'];
        uasort($boro, function ($a, $b) { return ($b['ok'] / max(1, $b['n'])) <=> ($a['ok'] / max(1, $a['n'])); });
        foreach ($boro as $b => $v) {
            if ($v['n'] >= 1000) {
                $rows[] = [esc_html($b === 'Other' ? 'Nassau, Westchester and other' : $b), esc_html(mta_tm_pct($v['ok'], $v['n']) . '%'), number_format($v['n'])];
            }
        }
        $o .= mta_more_sec('Is Access-A-Ride on time?', '<div class="mta-tiles mta-tiles-yoy">' . mta_more_tile($f['on'][15] . '%', 'within 15 minutes') . mta_more_tile($f['on'][20] . '%', 'within 20 minutes') .
            mta_more_tile($f['on'][30] . '%', 'within 30 minutes') . '</div><p>' . esc_html($f['on20'] . '% of pickups came within 20 minutes of the promised time' .
            ($f['on20_before'] !== null ? ', compared with ' . $f['on20_before'] . '% the year before.' : '.')) . '</p>' .
            mta_more_h3('Pickups within 20 minutes, by month') . mta_more_month_chart($series, 'Pickups on time by month', function ($v) { return $v . '%'; }) .
            mta_more_h3('By borough') . mta_more_table(['Pickup borough', 'Within 20 minutes', 'Pickups'], $rows),
            'Pickups compared with the promised pickup time, ' . esc_html(mta_more_range(...$x['pickup_range'])) . '. The MTA\'s standard is 20 minutes. Covers Access-A-Ride\'s vans and car services (not taxis or e-hail).');
    }

    if ($x['dropoff']) {
        $names = ['<1 minute late to 30 mins early (On-time)' => 'On time (up to 30 minutes early)', '31-45 mins early' => '31–45 minutes early',
                  'Over 45 mins early' => 'More than 45 minutes early', 'Over 1 minute late' => 'Late'];
        $tot = array_sum($x['dropoff']);
        $items = [];
        foreach ($names as $k => $label) {
            if (isset($x['dropoff'][$k])) {
                $items[] = [$label, $x['dropoff'][$k], mta_tm_pct($x['dropoff'][$k], $tot) . '%', number_format($x['dropoff'][$k]) . ' trips'];
            }
        }
        $o .= mta_more_sec('Do riders get to appointments on time?', '<p>' . esc_html($f['drop_on'] . '% of trips to an appointment arrived on time, meaning no more than 30 minutes early and not late.') . '</p>' .
            mta_more_bars($items), 'Trips booked with an appointment time, ' . esc_html(mta_more_range(...$x['dropoff_range'])) . '.');
    }

    if ($x['ride']) {
        $order = ['0-3 miles', '3-6 miles', '6-9 miles', '9-12 miles', '12-14 miles', '>14 miles'];
        $ride = $x['ride'];
        uksort($ride, function ($a, $b) {
            preg_match('/\d+/', $a, $ma);
            preg_match('/\d+/', $b, $mb);
            return ((int) ($ma[0] ?? 99) <=> (int) ($mb[0] ?? 99)) ?: strcmp($a, $b);
        });
        $rows = [];
        foreach ($ride as $d => $v) {
            $rows[] = [esc_html(str_replace(['>', '-'], ['more than ', '–'], $d)), number_format($v['n']), esc_html(round($v['min'] / max(1, $v['n'])) . ' min'), esc_html(mta_tm_pct($v['ok'], $v['n']) . '%')];
        }
        $o .= mta_more_sec('How long are Access-A-Ride trips?', '<p>' . esc_html('The average trip took ' . $f['ride_avg'] . ' minutes. ' . $f['ride_ok'] . '% of trips stayed within the maximum ride time the MTA sets for their distance.') . '</p>' .
            mta_more_table(['Trip length', 'Trips', 'Average ride', 'Within the maximum'], $rows), 'Completed trips, ' . esc_html(mta_more_range(...$x['ride_range'])) . '.');
    }

    $rows = [];
    foreach (['Primary', 'Broker'] as $p) {
        $ns = array_slice($x['noshows'][$p] ?? [], -12, 12, true);
        $ce = array_slice($x['experience'][$p] ?? [], -12, 12, true);
        if ($ns || $ce) {
            $rows[] = [esc_html(mta_tm_aar_provider($p)), $ns ? esc_html(round(array_sum($ns) / count($ns), 2)) : '–', $ce ? esc_html(round(array_sum($ce) / count($ce)) . '%') : '–'];
        }
    }
    if ($rows) {
        $o .= mta_more_sec('No-shows and trip quality', mta_more_table(['Provider', 'No-shows per 1,000 trips', 'Trips meeting every standard'], $rows),
            'Last 12 months of data. A no-show is when the driver doesn\'t arrive within 30 minutes of the promised time and the trip doesn\'t happen. ' .
            '"Every standard" means the trip was on time at pickup and drop-off, within the maximum ride time and not a no-show.');
    }

    if ($x['complaints']) {
        $items = [];
        foreach (array_slice($x['complaints'], 0, 10, true) as $c => $n) {
            $items[] = [$c, $n, number_format($n), ''];
        }
        $per1000 = $x['complaint_trips'] ? round(array_sum($x['complaints']) / $x['complaint_trips'] * 1000, 1) : null;
        $o .= mta_more_sec('What do Access-A-Ride riders complain about?', '<p>' . esc_html(number_format(array_sum($x['complaints'])) . ' complaints' .
            ($per1000 ? ', about ' . $per1000 . ' for every 1,000 trips.' : '.')) . '</p>' . mta_more_bars($items),
            'Complaints to Access-A-Ride by category, ' . esc_html(mta_more_range(...$x['complaints_range'])) . '.');
    }

    if ($x['calls']) {
        $rows = [];
        foreach ($x['calls'] as $type => $s) {
            $s = array_slice($s, -12, 12, true);
            $in = array_sum(array_column($s, 'in'));
            $up = array_sum(array_column($s, 'up'));
            $wait = $in ? array_sum(array_map(function ($v) { return $v['speed'] * $v['in']; }, $s)) / $in : 0;
            $rows[] = [esc_html($type === 'Transit Control' ? 'Trip status ("Where\'s my ride?")' : $type), number_format(round($in / max(1, count($s)))), esc_html(mta_tm_pct($up, $in, 0) . '%'),
                       esc_html($wait >= 90 ? round($wait / 60, 1) . ' min' : round($wait) . ' sec')];
        }
        $last = max(array_map(function ($s) { return array_key_last($s); }, $x['calls']));
        $o .= mta_more_sec('How fast does Access-A-Ride answer the phone?', mta_more_table(['Line', 'Calls a month', 'Answered', 'Average wait'], $rows),
            'The 12 months to ' . esc_html(gmdate('M Y', strtotime("$last-01 UTC"))) . '.');
    }

    if ($x['registrants']) {
        $items = [];
        foreach ($x['registrants'] as $b => $n) {
            $items[] = [$b, $n, number_format($n), ''];
        }
        $o .= mta_more_sec('Who is registered for Access-A-Ride?', '<p>' . esc_html(number_format($f['registrants']) . ' people were registered in ' . gmdate('F Y', strtotime($x['registrants_month'] . '-01 UTC')) . '.') . '</p>' .
            mta_more_bars($items) . mta_more_h3('Registered riders, by month') . mta_more_month_chart($x['registrants_series'], 'Access-A-Ride registrants by month'),
            'People registered for Access-A-Ride, by where they live.');
    }

    $o .= mta_tm_faq_html(mta_tm_aar_faq($f));
    $o .= mta_ridership_ssr_more('aar');
    $o .= mta_tm_source(['hasd-hdb7' => 'Paratransit Ridership', 'fn46-66ir' => 'Paratransit Ridership 2016–2023', 'dqvw-iu8s' => 'Pickup On-Time Performance',
        'f84c-ude3' => 'Drop-off On-Time Performance', 'w9xt-anps' => 'Ride Time', 'mdy9-kvbb' => 'Provider No-Shows', 'suhb-73ts' => 'Customer Experience',
        're4e-8rv8' => 'Customer Complaints', '6r5t-chrj' => 'Call Center Performance', 'cxps-6wkq' => 'Registrants'], $x['updated']);
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

add_shortcode('mta_access_a_ride', 'mta_tm_aar_page');

/* ---------- LIRR and Metro-North station pages ---------- */

/** "lirr" or "mnr" on the railroad pages (and their station pages), else null. */
function mta_tm_rail_key()
{
    foreach (MTA_TM_RAIL_SLUGS as $k => $slug) {
        if (is_page($slug)) {
            return $k;
        }
    }
    return null;
}

function mta_tm_rail_stations($k)
{
    $s = get_option('mta_tm_rail');
    return $s['stations'][$k] ?? [];
}

function mta_tm_rail_station_current($k)
{
    $slug = get_query_var('mta_rail_station');
    if (!$slug || !mta_tm_live()) {
        return null;
    }
    foreach (mta_tm_rail_stations($k) as $st) {
        if ($st['slug'] === $slug) {
            return $st;
        }
    }
    return null;
}

/** The branch name the on-time data uses for a station's branch. */
function mta_tm_otp_branch($k, $branch)
{
    $map = ['Greenport' => 'Ronkonkoma', 'Belmont' => 'Hempstead', 'Wassaic' => 'Harlem', 'Waterbury' => 'New Haven', 'New Canaan' => 'New Haven', 'Danbury' => 'New Haven'];
    return $map[$branch] ?? $branch;
}

function mta_tm_sold_as_name($s)
{
    $n = ['one_way' => 'One-way', 'round_trip' => 'Round trip', 'monthly' => 'Monthly', 'weekly' => 'Weekly', 'ten_trip' => '10-trip', 'day_pass' => 'Day pass', 'other' => 'Other'];
    return $n[$s] ?? ucfirst(str_replace('_', ' ', $s));
}

/** The numbers a station page, its title and its FAQ share. */
function mta_tm_rail_station_facts($k, $st)
{
    $d = get_option('mta_tm_rail');
    $short = mta_more_rail_names()[$k][0];
    $per_day = !empty($st['tickets']) ? round($st['tickets'] / max(1, $d['days'])) : null;
    $peak = ($st['fare']['peak'] ?? 0);
    $off = ($st['fare']['off_peak'] ?? 0);
    $crowd = null;
    if (!empty($st['crowd']['in'])) {
        $best = null;
        foreach ($st['crowd']['in'] as $h => $v) {
            if (!$best || $v[0] > $best[1][0]) {
                $best = [$h, $v];
            }
        }
        $crowd = ['hour' => $best[0], 'occ' => $best[1][0], 'pax' => $best[1][1]];
    }
    return ['d' => $d, 'short' => $short, 'per_day' => $per_day, 'count' => count(array_filter(array_column(mta_tm_rail_stations($k), 'tickets'))),
            'chg' => !empty($st['tickets_before']) ? mta_tm_change($st['tickets'] ?? 0, $st['tickets_before']) : '',
            'peak_share' => $peak + $off ? round($peak / ($peak + $off) * 100) : null, 'crowd' => $crowd,
            'range' => mta_more_range($d['from'], $d['latest'])];
}

function mta_tm_rail_station_faq($k, $st, $f)
{
    $name = $st['name'];
    $s = $f['short'];
    $out = [];
    if ($f['per_day']) {
        $out[] = ["How busy is $name station?", 'About ' . number_format($f['per_day']) . " tickets a day were sold for trips starting at $name (" . $f['range'] . '), the #' . $st['rank'] .
            ' most of ' . $f['count'] . ' ' . $s . ' stations' . ($f['chg'] ? ', ' . $f['chg'] . ' than the year before' : '') . '.'];
    }
    $out[] = ["Is $name station accessible?", $st['access'] === 'FULL' ? "Yes. The MTA lists $name as fully accessible." :
        ($st['access'] ? "The MTA lists $name as " . strtolower(str_replace('_', ' ', $st['access'])) . ' accessible. Check mta.info before you travel.' : "Check mta.info for $name's accessibility.")];
    if ($st['zone'] !== '') {
        $out[] = ["What fare zone is $name in?", "$name is in zone " . $st['zone'] . ' on the ' . ($st['branch'] === 'City Terminal Zone' ? 'City Terminal Zone' : $st['branch'] . ' Branch') . '.'];
    }
    if ($f['crowd']) {
        $out[] = ["How crowded are trains at $name?", 'On weekdays, trains toward the city are fullest when they leave ' . $name . ' around ' . mta_ridership_hour_name($f['crowd']['hour']) .
            ', with about ' . number_format($f['crowd']['pax']) . ' people on board (' . $f['crowd']['occ'] . '% of seats).'];
    }
    if (!empty($st['dest'])) {
        $all = [];
        foreach (mta_tm_rail_stations($k) as $o) {
            $all[$o['code']] = $o['name'];
        }
        $top = array_key_first($st['dest']);
        $out[] = ["Where do people go from $name?", 'The most common destination on tickets from ' . $name . ' is ' . ($all[$top] ?? $top) . '.'];
    }
    return $out;
}

function mta_tm_rail_station_page($k, $st)
{
    mta_tm_css();
    $f = mta_tm_rail_station_facts($k, $st);
    $d = $f['d'];
    $s = $f['short'];
    $name = $st['name'];
    $all = [];
    foreach (mta_tm_rail_stations($k) as $o) {
        $all[$o['code']] = $o;
    }
    $branch = $st['branch'] === 'City Terminal Zone' ? 'City Terminal Zone' : $st['branch'] . ' Branch';
    $access = $st['access'] === 'FULL' ? 'Accessible' : ($st['access'] ? ucfirst(strtolower(str_replace('_', ' ', $st['access']))) . ' accessible' : '');
    $o = '<p class="mta-note"><a href="' . esc_url(mta_more_url($k)) . '">' . esc_html($s) . '</a> › ' . esc_html($name) . '</p>' .
        '<header class="mta-hero"><h1>' . esc_html("$name $s Station: " . (!empty($st['crowd']) ? 'Tickets, Crowding & On-Time Data' : 'Tickets, Destinations & On-Time Data')) . '</h1>' .
        '<p class="mta-hero-links">' . esc_html($branch . ($st['zone'] !== '' ? ' · Zone ' . $st['zone'] : '') . ($access ? ' · ' . $access : '') . ' · From MTA open data · Not affiliated with the MTA') . '</p><div class="mta-tiles">' .
        ($f['per_day'] ? mta_more_tile(number_format($f['per_day']), 'tickets a day from here') . mta_more_tile('#' . $st['rank'], 'of ' . $f['count'] . ' ' . $s . ' stations') : '') .
        ($f['peak_share'] !== null ? mta_more_tile($f['peak_share'] . '%', 'of tickets are peak fares') : '') .
        ($f['crowd'] ? mta_more_tile($f['crowd']['occ'] . '%', 'of seats taken, busiest hour') : '') . '</div></header>';

    if ($f['per_day']) {
        $o .= mta_more_sec("How busy is $name station?", '<p>' . esc_html('About ' . number_format($f['per_day']) . ' tickets a day were sold for trips starting at ' . $name . ' (' . $f['range'] . '), the #' . $st['rank'] .
            ' most of ' . $f['count'] . ' ' . $s . ' stations' . ($f['chg'] ? ', and ' . $f['chg'] . ' than in the 12 months before.' : '.') .
            (!empty($st['tickets_to']) ? ' Another ' . number_format(round($st['tickets_to'] / max(1, $d['days']))) . ' a day were sold for trips ending here.' : '')) . '</p>',
            'Tickets, not riders: a monthly or weekly ticket counts once however many times it is used, and a round trip counts once.');
    }

    if (!empty($st['dest'])) {
        $items = [];
        foreach ($st['dest'] as $c => $n) {
            $dn = $all[$c]['name'] ?? $c;
            $items[] = [isset($all[$c]) ? ['html' => '<a href="' . esc_url(mta_tm_rail_url($k, $all[$c]['slug'])) . '">' . esc_html($dn) . '</a>'] : $dn, $n,
                        number_format(round($n / max(1, $d['days']))) . ' a day', mta_tm_pct($n, $st['tickets'] ?? 0, 0) . '% of tickets'];
        }
        $o .= mta_more_sec("Where do riders from $name go?", mta_more_bars($items), 'The most common destinations on tickets sold from ' . esc_html($name) . ', ' . esc_html($f['range']) . '.');
    }

    if (!empty($st['sold_as'])) {
        $sold = $st['sold_as'];
        arsort($sold);
        $tot = array_sum($sold);
        $items = [];
        foreach ($sold as $t => $n) {
            if ($n / max(1, $tot) >= 0.005) {
                $items[] = [mta_tm_sold_as_name($t), $n, mta_tm_pct($n, $tot) . '%', number_format($n) . ' sold'];
            }
        }
        $o .= mta_more_sec("What tickets do $name riders buy?", ($f['peak_share'] !== null ? '<p>' . esc_html($f['peak_share'] . '% of peak and off-peak tickets from ' . $name . ' were peak fares.') . '</p>' : '') .
            mta_more_bars($items), esc_html($f['range']) . ', all ways of buying (TrainTime app, ticket machines and ticket windows).');
    }

    if (!empty($st['crowd'])) {
        $cell = function ($v) { return $v ? esc_html($v[0] . '%') . ' <small class="mta-note">~' . number_format($v[1]) . ' on board</small>' : '–'; };
        $rows = [];
        for ($h = 0; $h < 24; $h++) {
            $in = $st['crowd']['in'][$h] ?? null;
            $out = $st['crowd']['out'][$h] ?? null;
            if (($in[2] ?? 0) + ($out[2] ?? 0) >= 20) { // skip hours with hardly any trains measured
                $rows[] = [esc_html(mta_ridership_hour_name($h)), $cell($in), $cell($out)];
            }
        }
        $body = mta_more_table(['Leaving at', 'Toward ' . ($k === 'lirr' ? 'the city' : 'Grand Central'), 'Away from the city'], $rows);
        $o .= mta_more_sec("How crowded are trains at $name?", ($f['crowd'] ? '<p>' . esc_html('On weekdays, trains toward the city are fullest when they leave ' . $name . ' around ' . mta_ridership_hour_name($f['crowd']['hour']) .
            ', with about ' . number_format($f['crowd']['pax']) . ' people on board, ' . $f['crowd']['occ'] . '% of the seats.') . '</p>' : '') . $body,
            'Average load as trains leave the station, weekdays, ' . esc_html(mta_more_range(...$d['crowd_range'])) . ', from weight sensors on the LIRR\'s electric trains (M7 and M9). Diesel trains are not measured. Over 100% means people standing.');
    }

    $rail = get_option('mta_more_rail')[$k]['otp']['branches'] ?? [];
    $ob = mta_tm_otp_branch($k, $st['branch']);
    if (isset($rail[$ob])) {
        $b = $rail[$ob];
        $body = '<p>' . esc_html('From ' . mta_more_range(get_option('mta_more_rail')[$k]['from'], get_option('mta_more_rail')[$k]['latest']) . ', ' . $b['otp'] . '% of ' . $ob . ' trains were on time' .
            ($b['before'] !== null ? ' (' . $b['before'] . '% the year before)' : '') . '.') . ' <a href="' . esc_url(mta_more_url($k)) . '">Every ' . esc_html($s) . ' branch compared &rarr;</a></p>';
        $o .= mta_more_sec("Are $name trains on time?", $body, 'On time means arriving within 5 minutes 59 seconds of schedule. The railroad reports this by branch, not by station.');
    }

    if ($st['code'] === 'JAM' && !empty($d['jamaica'])) {
        $rows = [];
        $j = $d['jamaica'];
        ksort($j);
        foreach ($j as $b => $v) {
            $pc = function ($p) { return $p === null ? '–' : $p . '%'; };
            $rows[] = [esc_html($b), esc_html($pc($v['AM'] ?? null)), esc_html($pc($v['PM'] ?? null)), esc_html($pc($v['Off-peak'] ?? null))];
        }
        $o .= mta_more_sec('Are trains on time at Jamaica?', mta_more_table(['Branch', 'Morning rush', 'Evening rush', 'Off-peak'], $rows),
            'Trains arriving at Jamaica on time, by the branch they come from, ' . esc_html(mta_more_range(...$d['jamaica_range'])) .
            '. On time here means less than 3 minutes late, a stricter test than the railroad\'s usual 6 minutes, so the numbers are lower.');
    }

    $eb = $d['elevators'][$k] ?? [];
    $key = $st['branch'] === 'City Terminal Zone' ? ($k === 'lirr' ? 'City Terminal' : 'Grand Central Terminal') : mta_tm_otp_branch($k, $st['branch']);
    if (isset($eb[$key])) {
        $e = $eb[$key];
        $area = $key === 'Grand Central Terminal' ? 'Grand Central Terminal' : ($key === 'City Terminal' ? 'City Terminal Zone' : "$key Branch");
        $o .= mta_more_sec('Elevators and escalators', '<p>' . esc_html(($access ? $name . ' is listed as ' . strtolower($access) . '. ' : '') . 'Across the ' . $area .
            ', elevators worked ' . ($e['elevator'] ?? '–') . '% of the time' . (!empty($e['escalator']) ? ' and escalators ' . $e['escalator'] . '%' : '') . ' over the last 12 months.') . '</p>',
            'The railroad reports elevator and escalator availability by branch, not by station.');
    }

    $same = array_filter(mta_tm_rail_stations($k), function ($o) use ($st) { return $o['branch'] === $st['branch'] && $o['code'] !== $st['code']; });
    if ($same) {
        $li = '';
        foreach ($same as $o2) {
            $li .= '<li><a href="' . esc_url(mta_tm_rail_url($k, $o2['slug'])) . '">' . esc_html($o2['name']) . '</a>' . (!empty($o2['tickets']) ? ' <small class="mta-note">' .
                number_format(round($o2['tickets'] / max(1, $d['days']))) . ' tickets a day</small>' : '') . '</li>';
        }
        $o .= mta_more_sec('Other stations on the ' . $branch, '<ul class="mta-stop-list">' . $li . '</ul>');
    }
    if ($st['url']) {
        $o .= '<p class="mta-note">Schedules, parking and station details: <a href="' . esc_url($st['url']) . '" rel="noopener">' . esc_html($name) . ' on mta.info</a>.</p>';
    }
    $o .= mta_tm_faq_html(mta_tm_rail_station_faq($k, $st, $f));
    $ids = ['5ypk-fceg' => 'MTA Railroad Ticket Sales', 'wxmd-5cpm' => 'MTA Rail Stations'] +
        ($k === 'lirr' ? ['hb2b-cimm' => 'LIRR Stop-Level Occupancy', '9hjt-526f' => 'LIRR Elevator and Escalator Availability', '6kq9-5ikh' => 'LIRR On-Time Performance'] +
                         ($st['code'] === 'JAM' ? ['hv6n-yxrc' => 'LIRR Jamaica On-Time Performance'] : [])
                       : ['ax67-8386' => 'Metro-North Elevator and Escalator Availability', '83hw-i6xw' => 'Metro-North On-Time Performance']);
    $o .= mta_tm_source($ids, $d['updated']);
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

/** Every station on the railroad page, by branch, linking to its page. */
function mta_tm_rail_station_list($k)
{
    if (!mta_tm_live() || !($list = mta_tm_rail_stations($k))) {
        return '';
    }
    $d = get_option('mta_tm_rail');
    $by = [];
    foreach ($list as $st) {
        $by[$st['branch']][] = $st;
    }
    ksort($by);
    $o = '';
    $top = array_filter($list, function ($s) { return !empty($s['rank']) && $s['rank'] <= 10; });
    usort($top, function ($a, $b) { return $a['rank'] <=> $b['rank']; });
    if ($top) {
        $items = [];
        foreach ($top as $st) {
            $items[] = [['html' => '<a href="' . esc_url(mta_tm_rail_url($k, $st['slug'])) . '">' . esc_html($st['name']) . '</a>'], $st['tickets'],
                        number_format(round($st['tickets'] / max(1, $d['days']))) . ' a day', $st['branch']];
        }
        $o .= mta_more_h3('Busiest stations (tickets sold for trips starting there)') . mta_more_bars($items);
    }
    foreach ($by as $b => $sts) {
        $li = '';
        foreach ($sts as $st) {
            $li .= '<li><a href="' . esc_url(mta_tm_rail_url($k, $st['slug'])) . '">' . esc_html($st['name']) . '</a></li>';
        }
        $o .= mta_more_h3($b === 'City Terminal Zone' ? 'City Terminal Zone' : "$b Branch") . '<ul class="mta-stop-list">' . $li . '</ul>';
    }
    $short = mta_more_rail_names()[$k][0];
    return mta_more_sec("Every $short station", $o, 'Each station page shows tickets sold there, where riders go' . ($k === 'lirr' ? ', how crowded trains are by hour' : '') .
        ' and its branch\'s on-time rate. ' . esc_html(mta_more_range($d['from'], $d['latest'])) . '.');
}

// Search titles, descriptions, canonical links and FAQ data for the station pages (before Yoast's, which would point at the railroad page).
add_action('template_redirect', function () {
    $k = mta_tm_rail_key();
    if (!$k || !($st = mta_tm_rail_station_current($k))) {
        return;
    }
    $f = mta_tm_rail_station_facts($k, $st);
    $title = $st['name'] . ' ' . $f['short'] . ' Station: ' . (!empty($st['crowd']) ? 'Tickets, Crowding & On-Time' : 'Tickets, Destinations & On-Time');
    $desc = mta_ridership_seo_desc($st['name'] . ' ' . $f['short'] . ' station (' . ($st['branch'] === 'City Terminal Zone' ? 'City Terminal Zone' : $st['branch'] . ' Branch') .
        ($st['zone'] !== '' ? ', zone ' . $st['zone'] : '') . ')' . ($f['per_day'] ? ': about ' . number_format($f['per_day']) . ' tickets a day, #' . $st['rank'] . ' of ' . $f['count'] : '') .
        '. Where riders go, ' . ($f['crowd'] ? 'how crowded trains are by hour, ' : '') . 'on-time rate and accessibility. From MTA open data.');
    $url = mta_tm_rail_url($k, $st['slug']);
    $t = function () use ($title) { return mta_ridership_seo_title($title); };
    add_filter('pre_get_document_title', $t, 99);
    add_filter('wpseo_title', $t, 99);
    add_filter('wpseo_opengraph_title', function () use ($title) { return $title; }, 99);
    foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $flt) {
        add_filter($flt, function () use ($desc) { return $desc; }, 99);
    }
    foreach (['wpseo_canonical', 'wpseo_opengraph_url'] as $flt) {
        add_filter($flt, function () use ($url) { return $url; }, 99);
    }
    add_action('wp_head', function () use ($k, $st, $f) {
        echo '<script type="application/ld+json">' . wp_json_encode([
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => array_map(function ($i) {
                return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
            }, mta_tm_rail_station_faq($k, $st, $f)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
    });
}, 5);

/* ---------- MTA jobs page ---------- */

function mta_tm_jobs_facts()
{
    $j = get_option('mta_tm_jobs');
    if (!$j || empty($j['agencies'])) {
        return null;
    }
    $full = array_filter($j['hires'], function ($y) use ($j) { return $y < (int) substr($j['hires_latest'], 0, 4) || substr($j['hires_latest'], 5, 2) === '12'; }, ARRAY_FILTER_USE_KEY);
    $hy = $full ? array_key_last($full) : null;
    return ['j' => $j, 'total' => array_sum($j['agencies']), 'hire_year' => $hy, 'hired' => $hy ? $full[$hy]['in'] : null,
            'pay' => get_option('mta_more_pay'), 'month' => gmdate('F Y', strtotime($j['latest'] . '-01 UTC'))];
}

/** The largest job title whose name contains $word, e.g. "Bus Operator". */
function mta_tm_job_like($j, $word)
{
    foreach ($j['titles'] as $t) {
        if (stripos($t['title'], $word) !== false) {
            return $t;
        }
    }
    return null;
}

function mta_tm_jobs_faq($f)
{
    $j = $f['j'];
    $out = [['How many people work for the MTA?', 'About ' . number_format($f['total']) . ' positions were filled in ' . $f['month'] . ', ' . number_format($j['agencies']['NYCT'] ?? 0) .
        ' of them at New York City Transit (subways and buses).']];
    if ($j['titles']) {
        $t = $j['titles'][0];
        $out[] = ['What is the most common MTA job?', $t['title'] . ' (' . mta_more_agency_name($t['agency']) . '), with ' . number_format($t['people']) . ' people in ' . $j['year'] .
            '. Their typical (median) pay was $' . number_format($t['med_pay']) . ', including overtime.'];
    }
    foreach (['Bus Operator', 'Train Operator', 'Conductor', 'Station Agent', 'Track Worker', 'Cleaner'] as $w) {
        if ($t = mta_tm_job_like($j, $w)) {
            $out[] = ['How much does an MTA ' . strtolower($w) . ' make?', 'The typical (median) ' . $t['title'] . ' at ' . mta_more_agency_name($t['agency']) . ' made $' . number_format($t['med_pay']) .
                ' in ' . $j['year'] . ', including about $' . number_format($t['avg_ot']) . ' in overtime on average (' . number_format($t['people']) . ' people).'];
        }
    }
    if ($f['hired']) {
        $out[] = ['How many people does the MTA hire a year?', 'The MTA hired ' . number_format($f['hired']) . ' people in ' . $f['hire_year'] . ', counting new hires and people rehired.'];
    }
    $out[] = ['Where can I find MTA job openings?', 'On the MTA\'s careers site at mta.info/careers, which lists openings and civil service exams. This page only shows MTA data about its jobs.'];
    return $out;
}

function mta_tm_agency_short($a)
{
    $n = ['NYCT' => 'New York City Transit (subways and buses)', 'LIRR' => 'Long Island Rail Road', 'MNR' => 'Metro-North Railroad', 'MTAHQ' => 'MTA Headquarters and MTA Police',
          'MTABC' => 'MTA Bus Company', 'CD' => 'Construction and Development', 'BT' => 'Bridges and Tunnels', 'SIR' => 'Staten Island Railway', 'GCMCOC' => 'Grand Central Madison operations'];
    return $n[$a] ?? mta_more_agency_name($a);
}

function mta_tm_jobs_page()
{
    if (!mta_tm_live()) {
        return '';
    }
    $f = mta_tm_jobs_facts();
    if (!$f) {
        return '<p>MTA jobs data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $j = $f['j'];
    $top = $j['titles'][0] ?? null;
    $o = '<header class="mta-hero"><h1>MTA Jobs: How Many People Work at the MTA and What the Jobs Pay</h1>' .
        '<p class="mta-hero-links">From MTA open data on headcount, payroll and hiring · No names shown · Not affiliated with the MTA</p><div class="mta-tiles">' .
        mta_more_tile(number_format($f['total']), 'positions filled') . ($f['hired'] ? mta_more_tile(number_format($f['hired']), 'hired in ' . $f['hire_year']) : '') .
        (!empty($f['pay']['all']['med']) ? mta_more_tile('$' . number_format($f['pay']['all']['med']), 'typical pay (' . $f['pay']['year'] . ')') : '') .
        ($top ? mta_more_tile(number_format($top['people']), strtolower($top['title']) . 's') : '') . '</div></header>';
    $o .= '<p class="mta-note">Looking for a job at the MTA? Openings and civil service exams are on the MTA\'s <a href="https://www.mta.info/careers" rel="noopener">careers site</a>. ' .
        'This page shows the MTA\'s own data about its jobs.</p>';

    $items = [];
    foreach ($j['agencies'] as $a => $n) {
        if ($n > 0) {
            $items[] = [mta_tm_agency_short($a), $n, number_format($n), mta_tm_pct($n, $f['total']) . '%'];
        }
    }
    $groups = [];
    foreach ($j['groups'] as $g => $n) {
        $label = ['Operational Hourlies' => 'Operating jobs (train and bus crews, maintenance, cleaners...)', 'Managers/Supervisors' => 'Managers and supervisors',
                  'Professional/Technical/Clerical' => 'Professional, technical and office jobs'][$g] ?? $g;
        $groups[] = [$label, $n, number_format($n), mta_tm_pct($n, $f['total']) . '%'];
    }
    $series = $j['series']['actual'] ?? [];
    $o .= mta_more_sec('How many people work at the MTA?', '<p>' . esc_html('About ' . number_format($f['total']) . ' positions were filled in ' . $f['month'] . '.' .
        (isset($j['series']['budget'][$j['latest']]) ? ' The budget allowed for ' . number_format($j['series']['budget'][$j['latest']]) . '.' : '')) . '</p>' .
        mta_more_bars($items) . mta_more_h3('By kind of job') . mta_more_bars($groups) . (count($series) > 1 ? mta_more_h3('People working, by month') . mta_more_month_chart($series, 'MTA headcount by month') : ''),
        'Filled positions (full-time equivalents) in the MTA\'s financial plan.');

    if ($j['titles']) {
        $rows = [];
        foreach ($j['titles'] as $t) {
            $rows[] = [mta_more_job($t), number_format($t['people']), '$' . number_format($t['med_pay']), '$' . number_format($t['avg_ot'])];
        }
        $o .= mta_more_sec('Common MTA jobs and what they pay', mta_more_table(['Job title', 'People', 'Typical pay', 'Average overtime'], $rows),
            'Job titles with 25 or more people in ' . esc_html($j['year']) . ', most people first. Typical pay is the median total pay, including overtime, of people who held the title for at least 10 months of the year. ' .
            'Titles are written as in the MTA\'s payroll. <a href="' . esc_url(mta_more_url('pay')) . '">MTA salaries and overtime &rarr;</a>');
    }

    $rows = [];
    foreach ($j['hires'] as $y => $v) {
        $partial = $y === (int) substr($j['hires_latest'], 0, 4) && substr($j['hires_latest'], 5, 2) !== '12';
        $rows[] = [esc_html($y . ($partial ? ' (to ' . gmdate('M', strtotime($j['hires_latest'] . '-01 UTC')) . ')' : '')), number_format($v['in']), $v['staff'] ? number_format($v['staff']) : '–'];
    }
    if ($rows) {
        $o .= mta_more_sec('How many people does the MTA hire?', ($f['hired'] ? '<p>' . esc_html('The MTA hired ' . number_format($f['hired']) . ' people in ' . $f['hire_year'] . '.') . '</p>' : '') .
            mta_more_table(['Year', 'Hired', 'Working at year end'], array_reverse($rows)), 'New hires and rehires, and active employees at the end of each year (or the latest month), all MTA agencies, from the MTA workforce data.');
    }

    if ($j['overtime']) {
        $items = [];
        foreach ($j['overtime'] as $y => $v) {
            $items[] = [(string) $y, $v['ot'], round($v['ot'] / 1e6, 1) . ' million hours', mta_tm_pct($v['ot'], $v['rt']) . '% on top of regular hours'];
        }
        $last = end($j['overtime']);
        $o .= mta_more_sec('MTA overtime hours by year', '<p>' . esc_html('MTA workers put in ' . round($last['ot'] / 1e6, 1) . ' million hours of overtime in ' . array_key_last($j['overtime']) .
            ', ' . mta_tm_pct($last['ot'], $last['rt']) . '% on top of their regular hours.') . '</p>' . mta_more_bars(array_reverse($items)), 'Overtime and regular hours worked, all MTA agencies.');
    }

    $o .= mta_tm_faq_html(mta_tm_jobs_faq($f));
    $o .= mta_ridership_ssr_more('jobs');
    $o .= mta_tm_source(['ui26-n8p4' => 'MTA Headcount', 'kcjb-nf3e' => 'MTA Employee Payroll', 's9d4-b4kt' => 'MTA Workforce: New Hires and Separations', 'jkya-6vhd' => 'MTA Overtime'], $j['updated'],
        ' Pay is shown as medians and averages for job titles, never for a named person.');
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

add_shortcode('mta_jobs', 'mta_tm_jobs_page');

/* ---------- E-ZPass locations page ---------- */

function mta_tm_ezpass_faq($e)
{
    $p = $e['places'];
    $nyc = array_filter($p, function ($r) { return in_array(strtolower($r['city']), ['new york', 'brooklyn', 'bronx', 'staten island', 'queens'], true) ||
        preg_match('/^(10[0-4]|11[0-6])/', $r['zip']); });
    $names = array_count_values(array_column($p, 'name'));
    arsort($names);
    return [['Where can I buy an E-ZPass in New York?', 'At ' . count($p) . ' authorized retailers across the state, including ' . implode(', ', array_slice(array_keys($names), 0, 3)) .
        '. You can also order a tag online from E-ZPass New York at e-zpassny.com.'],
            ['Where can I get an E-ZPass in New York City?', count($nyc) . ' of the retailers on the list are in New York City. The map and list on this page show each address.'],
            ['Is this list up to date?', 'It is the state\'s snapshot list' . ($e['as_of'] ? ' from ' . gmdate('F Y', strtotime($e['as_of'])) : '') . '. Call ahead to check a store still sells tags.']];
}

/** A Google Maps search for a retailer by name and address (Google's documented link format, no key needed). */
function mta_tm_gmaps_url($name, $address)
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($name . ', ' . $address);
}

function mta_tm_ezpass_page()
{
    if (!mta_tm_live()) {
        return '';
    }
    $e = get_option('mta_tm_ezpass');
    if (!$e || empty($e['places'])) {
        return '<p>E-ZPass locations are loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    mta_ridership_leaflet();
    wp_enqueue_script('mta-points', plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/mta-points.js', ['leaflet'], '1.1.0', true);
    $gmaps = true; // "Open in Google Maps" links, live since 2026-11-03
    $p = $e['places'];
    $cities = array_unique(array_column($p, 'city'));
    $o = '<header class="mta-hero"><h1>Where to Get an E-ZPass: New York Retailer Locations Map</h1>' .
        '<p class="mta-hero-links">Stores and offices that sell E-ZPass tags, from New York State open data' . ($e['as_of'] ? ' (list as of ' . esc_html(gmdate('F Y', strtotime($e['as_of']))) . ')' : '') .
        ' · Not affiliated with the MTA or E-ZPass</p><div class="mta-tiles">' . mta_more_tile(number_format(count($p)), 'places to buy a tag') . mta_more_tile(number_format(count($cities)), 'cities and towns') . '</div></header>';
    $o .= '<p class="mta-note">You can also order a tag online from <a href="https://www.e-zpassny.com/" rel="noopener">E-ZPass New York</a> (the official site). Call a store before you go to check it still sells tags.</p>';
    $pts = [];
    foreach ($p as $r) {
        if ($r['lat'] !== null) {
            $pts[] = [round($r['lat'], 5), round($r['lon'], 5), $r['name'], $r['street'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['zip']];
        }
    }
    $o .= mta_more_sec('E-ZPass retailer map', '<div class="mta-map mta-points-map" style="height:420px;border-radius:12px" data-points="' . esc_attr(wp_json_encode($pts)) . '"' . ($gmaps ? ' data-gmaps="1"' : '') . '></div>',
        'Tap a dot for the name and address' . ($gmaps ? ', and a link to open it in Google Maps.' : '.'));
    $rows = [];
    foreach ($p as $r) {
        $addr = $r['street'] . ', ' . $r['city'] . ', ' . $r['state'] . ' ' . $r['zip'];
        $rows[] = [esc_html($r['name']) . '<br><small class="mta-note">' . esc_html($r['street']) . '</small>' .
            ($gmaps ? '<br><small><a href="' . esc_url(mta_tm_gmaps_url($r['name'], $addr)) . '" target="_blank" rel="noopener">Open in Google Maps</a></small>' : ''),
            esc_html($r['city'] . ', ' . $r['state'] . ' ' . $r['zip'])];
    }
    $o .= mta_more_sec('Every E-ZPass retailer', '<p><input type="search" class="mta-points-filter" placeholder="Search by town, ZIP or store" aria-label="Search the list" style="width:100%;max-width:360px"></p>' .
        mta_more_table(['Retailer and address', 'City'], $rows), 'By state, then city.');
    $o .= mta_tm_faq_html(mta_tm_ezpass_faq($e));
    $o .= mta_ridership_ssr_more('ezpass');
    $o .= mta_tm_source(['y59h-w6v4' => 'MTA E-ZPass Retailers Locations'], $e['updated']);
    return '<div class="mta-ridership mta-ezpass">' . $o . mta_ridership_disclaimer() . '</div>';
}

add_shortcode('mta_ezpass', 'mta_tm_ezpass_page');

/* ---------- Staten Island Railway page ---------- */

function mta_tm_sir_facts()
{
    $x = get_option('mta_tm_sir');
    if (!$x || empty($x['otp'])) {
        return null;
    }
    $rate = function ($day, $recent) use ($x) {
        $ok = $n = 0;
        foreach ($x['otp'][$day] ?? [] as $m => $v) {
            if (($m >= $x['from']) === $recent) {
                $ok += $v['ok'];
                $n += $v['n'];
            }
        }
        return mta_tm_pct($ok, $n);
    };
    $full = array_filter($x['riders'], function ($v, $m) { return $v['days'] >= (int) gmdate('t', strtotime("$m-01 UTC")); }, ARRAY_FILTER_USE_BOTH);
    $l12 = array_slice($full, -12, 12, true);
    $p12 = array_slice($full, -24, 12, true);
    $sum = function ($a) { return array_sum(array_column($a, 'n')); };
    $days = function ($a) { return max(1, array_sum(array_column($a, 'days'))); };
    return ['x' => $x, 'otp' => $rate('7-Day', true), 'otp_before' => $rate('7-Day', false), 'am' => $rate('AM Rush', true), 'pm' => $rate('PM Rush', true),
            'weekday' => $rate('Weekday', true), 'weekend' => $rate('Weekend', true),
            'per_day' => count($l12) === 12 ? round($sum($l12) / $days($l12)) : null, 'riders_chg' => count($p12) === 12 && count($l12) === 12 ? mta_tm_change($sum($l12), $sum($p12)) : '',
            'months' => array_map(function ($v) { return round($v['n'] / max(1, $v['days'])); }, $full),
            'mdbf' => $x['mdbf'] ? end($x['mdbf']) : null, 'trips' => $x['trips']['Tuesday to Friday Daily'] ?? ($x['trips']['Monday Daily'] ?? null),
            'range' => mta_more_range($x['from'], $x['latest'])];
}

function mta_tm_sir_faq($f)
{
    $out = [];
    if ($f['otp'] !== null) {
        $out[] = ['Is the Staten Island Railway on time?', $f['otp'] . '% of Staten Island Railway trains were on time from ' . $f['range'] . ($f['otp_before'] !== null ? ' (' . $f['otp_before'] . '% the year before)' : '') .
            '. At morning rush hour it was ' . $f['am'] . '%, and ' . $f['pm'] . '% at evening rush hour.'];
    }
    if ($f['per_day']) {
        $out[] = ['How many people ride the Staten Island Railway?', 'About ' . number_format($f['per_day']) . ' rides a day over the last 12 full months' . ($f['riders_chg'] ? ', ' . $f['riders_chg'] . ' than the 12 months before.' : '.')];
    }
    if ($f['trips']) {
        $out[] = ['How many trains does the Staten Island Railway run?', 'About ' . $f['trips'] . ' trains on a typical weekday, ' . ($f['x']['trips']['AM Rush'] ?? '–') . ' of them in the morning rush and ' .
            ($f['x']['trips']['PM Rush'] ?? '–') . ' in the evening rush.'];
    }
    if ($f['x']['stations']) {
        $acc = count(array_filter($f['x']['stations'], function ($s) { return $s['ada'] === '1'; }));
        $out[] = ['How many stations does the Staten Island Railway have?', count($f['x']['stations']) . ' stations from St. George to Tottenville, ' . $acc . ' of them fully accessible.'];
    }
    return $out;
}

function mta_tm_sir_page()
{
    if (!mta_tm_live()) {
        return '';
    }
    $f = mta_tm_sir_facts();
    if (!$f) {
        return '<p>Staten Island Railway data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $x = $f['x'];
    $o = '<header class="mta-hero"><h1>Staten Island Railway: On-Time Performance, Ridership &amp; Stations</h1>' .
        '<p class="mta-hero-links">A Staten Island Railway report card from MTA open data, ' . esc_html($f['range']) . ', updated monthly · Not affiliated with the MTA</p><div class="mta-tiles">' .
        ($f['otp'] !== null ? mta_more_tile($f['otp'] . '%', 'trains on time') : '') . ($f['per_day'] ? mta_more_tile(number_format($f['per_day']), 'rides a day') : '') .
        ($f['trips'] ? mta_more_tile((string) $f['trips'], 'trains a weekday') : '') . ($f['mdbf'] ? mta_more_tile(number_format($f['mdbf']), 'miles between breakdowns') : '') . '</div></header>';
    $o .= '<p class="mta-note">For schedules and service changes, see <a href="https://www.mta.info/schedules" rel="noopener">mta.info</a>.</p>';

    if ($f['otp'] !== null) {
        $series = [];
        foreach ($x['otp']['7-Day'] ?? [] as $m => $v) {
            $series[$m] = mta_tm_pct($v['ok'], $v['n']);
        }
        $rows = [];
        foreach (['Weekday' => 'Weekdays', 'AM Rush' => 'Morning rush', 'PM Rush' => 'Evening rush', 'Weekend' => 'Weekends'] as $k => $label) {
            $rows[] = [esc_html($label), esc_html(($f[['Weekday' => 'weekday', 'AM Rush' => 'am', 'PM Rush' => 'pm', 'Weekend' => 'weekend'][$k]] ?? '–') . '%')];
        }
        $o .= mta_more_sec('Is the Staten Island Railway on time?', '<p>' . esc_html($f['otp'] . '% of trains were on time from ' . $f['range'] . ($f['otp_before'] !== null ? ', compared with ' . $f['otp_before'] . '% the year before.' : '.')) . '</p>' .
            mta_more_table(['When', 'On time'], $rows) . mta_more_h3('On time, by month') . mta_more_month_chart($series, 'On-time rate by month', function ($v) { return $v . '%'; }),
            'Scheduled trains that arrived on time, every day of the week.');
    }

    if (count($f['months']) > 1) {
        $o .= mta_more_sec('How many people ride the Staten Island Railway?', '<p>' . esc_html('About ' . number_format($f['per_day'] ?? end($f['months'])) . ' rides a day over the last 12 full months' .
            ($f['riders_chg'] ? ', ' . $f['riders_chg'] . ' than the 12 months before.' : '.')) . '</p>' . mta_more_h3('Rides a day, by month') . mta_more_month_chart($f['months'], 'Rides a day by month'),
            'The MTA\'s daily ridership counts, averaged over each month.');
    }

    if ($x['trips']) {
        $rows = [];
        $names = ['Monday Daily' => 'Mondays', 'Tuesday to Friday Daily' => 'Tuesday to Friday', 'AM Rush' => 'Morning rush (weekdays)', 'PM Rush' => 'Evening rush (weekdays)', 'Saturday' => 'Saturdays', 'Sunday' => 'Sundays'];
        foreach ($x['trips'] as $t => $n) {
            $rows[] = [esc_html($names[$t] ?? $t), number_format($n)];
        }
        $o .= mta_more_sec('How many trains run?', mta_more_table(['Schedule', 'Trains'], $rows), 'Scheduled trains, ' . esc_html(gmdate('F Y', strtotime($x['latest'] . '-01 UTC'))) . '.');
    }

    if (count($x['mdbf']) > 1) {
        $o .= mta_more_sec('How often do Staten Island Railway trains break down?', '<p>' . esc_html('Over the last 12 months, cars ran about ' . number_format($f['mdbf']) . ' miles between breakdowns on average.') . '</p>' .
            mta_more_h3('Miles between breakdowns (12-month average)') . mta_more_month_chart($x['mdbf'], 'Mean distance between failures'),
            'Mean distance between failures: miles run by the railway\'s cars for each breakdown that delayed service. Higher is better.');
    }

    if ($x['stations']) {
        $li = '';
        foreach ($x['stations'] as $s) {
            $li .= '<li>' . esc_html($s['name']) . ($s['ada'] === '1' ? ' <small class="mta-note">accessible</small>' : ($s['ada'] === '2' ? ' <small class="mta-note">partly accessible</small>' : '')) . '</li>';
        }
        $o .= mta_more_sec('Staten Island Railway stations', '<ol class="mta-stop-list">' . $li . '</ol>', 'North (St. George, for the ferry) to south (Tottenville).');
    }

    $crime = get_option(MTA_CRIME_OPTION)['agencies']['SIR'] ?? null;
    if ($crime) {
        $o .= mta_more_sec('Crime on the Staten Island Railway', '<p>' . esc_html('The MTA counted ' . number_format($crime['year']['n']) . ' major felonies on the Staten Island Railway in the last 12 months, compared with ' .
            number_format($crime['before']['n']) . ' the year before.') . ' <a href="' . esc_url(mta_crime_url()) . '">Subway and railroad crime &rarr;</a></p>');
    }
    $o .= mta_tm_faq_html(mta_tm_sir_faq($f));
    $o .= mta_ridership_ssr_more('sir');
    $o .= mta_tm_source(['fccm-griq' => 'Staten Island Railway On-Time Performance', 'k3aj-27se' => 'Staten Island Railway Scheduled Trips',
        'n2pf-h43s' => 'Staten Island Railway Mean Distance Between Failures', 'sayj-mze2' => 'MTA Daily Ridership', '39hk-dx4f' => 'MTA Subway Stations'], $x['updated']);
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

add_shortcode('mta_sir', 'mta_tm_sir_page');

/* ---------- more congestion pricing data (on the congestion pricing page) ---------- */

/** Average of the months of $series in year $y, and of the same months in the 12 months up to the latest. */
function mta_tm_avg(array $series)
{
    return $series ? round(array_sum($series) / count($series), 1) : null;
}

function mta_tm_cbd_sections()
{
    if (!mta_tm_live() || !($c = get_option('mta_tm_cbd'))) {
        return '';
    }
    $sec = function ($t, $b, $n) { return mta_more_sec($t, $b, $n); };
    $o = '';
    $base = function ($s) { return array_filter($s, function ($m) { return substr($m, 0, 4) === '2024'; }, ARRAY_FILTER_USE_KEY); };

    if (!empty($c['vmt']['CRZ'])) {
        $s = $c['vmt']['CRZ'];
        $last = array_key_last($s);
        $l12 = mta_tm_avg(mta_tm_last12($s, $last));
        $o .= $sec('Miles driven inside the zone', '<p>' . esc_html('Over the 12 months to ' . gmdate('F Y', strtotime("$last-01 UTC")) . ', vehicles drove ' .
            round(abs(100 - $l12), 1) . '% ' . ($l12 <= 100 ? 'fewer' : 'more') . ' miles a day inside the zone than in January 2024, before tolling began.') . '</p>' .
            mta_more_month_chart($s, 'Miles driven in the zone (January 2024 = 100)', function ($v) { return $v . ' (Jan 2024 = 100)'; }),
            'Daily vehicle miles driven in the zone, as an index where January 2024 = 100, from the MTA\'s estimates using connected-vehicle and GPS data. Tolling began January 5, 2025.');
    }
    if (!empty($c['ghg'])) {
        $vals = array_map(function ($v) { return $v['v']; }, $c['ghg']);
        $lastq = end($c['ghg']);
        $o .= $sec('Greenhouse gas from vehicles in the zone', '<p>' . esc_html('In ' . $lastq['label'] . ', vehicle greenhouse gas emissions in the zone were at ' . $lastq['v'] .
            ' on a scale where early 2024 = 100 (2024 average: ' . mta_tm_avg($base($vals)) . ').') . '</p>' .
            mta_more_month_chart($vals, 'Vehicle greenhouse gas in the zone (Q1 2024 = 100)', function ($v) { return (string) $v; }),
            'Quarterly estimate of greenhouse gas from vehicles driving in the zone, indexed to the first quarter of 2024 = 100.');
    }
    if (!empty($c['trips']['app']) || !empty($c['trips']['taxi'])) {
        $body = '';
        $notes = [];
        foreach (['app' => 'Uber and Lyft', 'taxi' => 'Yellow and green taxis'] as $k => $label) {
            $s = $c['trips'][$k] ?? [];
            if (!$s) {
                continue;
            }
            $per = [];
            foreach ($s as $m => $n) {
                $per[$m] = round($n / (int) gmdate('t', strtotime("$m-01 UTC")));
            }
            $last = array_key_last($per);
            $now = mta_tm_last12($per, $last);
            $before = array_filter($per, function ($m) use ($last) { $p = mta_more_month("$last-01", 12); return $m <= $p && $m > mta_more_month("$last-01", 24); }, ARRAY_FILTER_USE_KEY);
            if (count($before) === 12) {
                $notes[] = $label . ': about ' . number_format(round(array_sum($now) / 12)) . ' trips a day into the zone, ' . mta_tm_change(array_sum($now), array_sum($before)) . ' than the 12 months before';
            }
            $body .= mta_more_h3($label . ': trips into the zone a day') . mta_more_month_chart($per, $label . ' trips entering the zone a day');
        }
        $o .= $sec('Taxi and Uber/Lyft trips into the zone', ($notes ? '<p>' . esc_html(implode('. ', $notes) . '.') . '</p>' : '') . $body,
            'Trips that start outside the zone and end inside it, from Taxi and Limousine Commission trip records. Riders pay a congestion fee per trip: $1.50 in Uber and Lyft, $0.75 in taxis.');
    }
    if (!empty($c['bus']['in'])) {
        $in = $c['bus']['in'];
        $out = $c['bus']['out'] ?? [];
        $last = array_key_last($in);
        $l12 = mta_tm_avg(mta_tm_last12($in, $last));
        $b24 = mta_tm_avg($base($in));
        $o .= $sec('Are buses faster in the zone?', '<p>' . esc_html('Buses averaged ' . $l12 . ' mph inside the zone over the 12 months to ' . gmdate('F Y', strtotime("$last-01 UTC")) .
            ($b24 ? ', compared with ' . $b24 . ' mph in 2024' : '') . '.' . ($out ? ' Outside the zone they averaged ' . mta_tm_avg(mta_tm_last12($out, array_key_last($out))) . ' mph.' : '')) . '</p>' .
            mta_more_h3('Bus speed inside the zone, by month') . mta_more_month_chart($in, 'Bus speed in the zone by month', function ($v) { return $v . ' mph'; }),
            'Average road speed of MTA buses (miles driven divided by time), all routes, all hours.');
    }
    if (!empty($c['speed']['CBD'])) {
        $rows = [];
        foreach ($c['speed'] as $z => $s) {
            $last = array_key_last($s);
            $y = substr($last, 0, 4);
            $now = array_filter($s, function ($m) use ($y) { return substr($m, 0, 4) === $y; }, ARRAY_FILTER_USE_KEY);
            $then = array_filter($s, function ($m) use ($y, $last) { return substr($m, 0, 4) === (string) ($y - 1) && substr($m, 5) <= substr($last, 5); }, ARRAY_FILTER_USE_KEY);
            $rows[] = [esc_html(['CBD' => 'Inside the zone', 'CBD Adjacent' => 'Next to the zone', 'Greater NYC' => 'Rest of NYC'][$z] ?? $z), esc_html(mta_tm_avg($then) . ' mph'), esc_html(mta_tm_avg($now) . ' mph')];
        }
        $last = array_key_last($c['speed']['CBD']);
        $y = (int) substr($last, 0, 4);
        $o .= $sec('Taxi and Uber/Lyft speeds', mta_more_table(['Area', 'Jan–' . gmdate('M', strtotime("$last-01 UTC")) . ' ' . ($y - 1), 'Jan–' . gmdate('M', strtotime("$last-01 UTC")) . ' ' . $y], $rows),
            'Average speed of taxi and Uber/Lyft trips, from trip records. The MTA stopped updating this after ' . esc_html(gmdate('F Y', strtotime("$last-01 UTC"))) . '.');
    }
    return $o ? $o . '<p class="mta-source">More data (data.ny.gov): <a href="https://data.ny.gov/d/jbxx-kxff" rel="noopener">miles driven</a>, <a href="https://data.ny.gov/d/c72y-2ai5" rel="noopener">greenhouse gas</a>, ' .
        '<a href="https://data.ny.gov/d/d3qp-nhf8" rel="noopener">taxi and Uber/Lyft trips</a>, <a href="https://data.ny.gov/d/r6db-kkzj" rel="noopener">bus speeds</a>, ' .
        '<a href="https://data.ny.gov/d/6p29-6xqn" rel="noopener">taxi speeds</a>.</p>' : '';
}

/* ---------- search titles, descriptions and FAQ for the new pages ---------- */

function mta_tm_seo()
{
    if (!mta_tm_live()) {
        return null;
    }
    if (is_page(MTA_TM_PAGES['aar']) && ($f = mta_tm_aar_facts())) {
        return ['title' => 'Access-A-Ride: On-Time Rates, Trips & Complaints (' . substr($f['x']['latest'], 0, 4) . ')',
                'description' => 'How Access-A-Ride performs: ' . number_format($f['per_day']) . ' trips a day, ' . $f['on20'] . '% of pickups on time, ride times, no-shows, complaints and phone wait times. From MTA open data.',
                'faq' => mta_tm_aar_faq($f)];
    }
    if (is_page(MTA_TM_PAGES['jobs']) && ($f = mta_tm_jobs_facts())) {
        return ['title' => 'MTA Jobs: What the Jobs Pay & How Many Work There',
                'description' => 'About ' . number_format($f['total']) . ' people work at the MTA. Typical pay for bus operators, train operators, conductors and 80 other jobs, hiring by year and overtime. From MTA data.',
                'faq' => mta_tm_jobs_faq($f)];
    }
    if (is_page(MTA_TM_PAGES['ezpass']) && ($e = get_option('mta_tm_ezpass'))) {
        return ['title' => 'Where to Buy an E-ZPass: New York Locations Map',
                'description' => 'Map and list of all ' . count($e['places']) . ' places that sell E-ZPass tags in New York, searchable by town or ZIP. From New York State open data.',
                'faq' => mta_tm_ezpass_faq($e)];
    }
    if (is_page(MTA_TM_PAGES['sir']) && ($f = mta_tm_sir_facts())) {
        return ['title' => 'Staten Island Railway: On-Time Rate, Ridership & Stations',
                'description' => 'Staten Island Railway report card: ' . $f['otp'] . '% of trains on time, ' . ($f['per_day'] ? number_format($f['per_day']) . ' rides a day, ' : '') . 'trains a day, breakdowns and all stations. From MTA open data.',
                'faq' => mta_tm_sir_faq($f)];
    }
    return null;
}

add_filter('wpseo_title', function ($t) { return ($s = mta_tm_seo()) ? mta_ridership_seo_title($s['title']) : $t; });
add_filter('wpseo_opengraph_title', function ($t) { return ($s = mta_tm_seo()) ? $s['title'] : $t; });
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($t) { return ($s = mta_tm_seo()) ? mta_ridership_seo_desc($s['description']) : $t; });
}
add_action('wp_head', function () {
    if (!($s = mta_tm_seo()) || !$s['faq']) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($i) {
            return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
        }, $s['faq']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});

/* ---------- link-preview images (share-images.php redraws them whenever a data step brings new numbers) ---------- */

/** Bars for a card from [label => value]: the first 6, long labels cut. */
function mta_tm_card_bars(array $items)
{
    $out = [];
    foreach (array_slice($items, 0, 6, true) as $label => $v) {
        $label = (string) $label;
        $out[] = [mb_strlen($label) > 30 ? mb_substr($label, 0, 29) . '…' : $label, $v];
    }
    return $out;
}

function mta_tm_card_pct($v)
{
    return $v . '%';
}

function mta_tm_card_dollars($v)
{
    return '$' . number_format($v);
}

function mta_tm_card_rail($k)
{
    $f = mta_more_rail_facts($k);
    if (!$f) {
        return null;
    }
    $br = [];
    foreach ($f['x']['otp']['branches'] as $b => $v) {
        if (is_numeric($v['otp'])) {
            $br[$b] = $v['otp'];
        }
    }
    asort($br);
    $stats = [[$f['otp']['otp'] . '%', 'trains on time']];
    if ($f['per_day']) {
        $stats[] = [number_format($f['per_day']), 'rides a day'];
    }
    return ['title' => $f['names'][0] . ' On-Time Performance', 'subtitle' => 'Delays, cancellations, ridership and stations', 'stats' => $stats,
            'bars_title' => 'Least on-time branches', 'bars' => mta_tm_card_bars($br), 'fmt' => 'mta_tm_card_pct', 'top' => 100,
            'footer' => 'mantiereidii.com/' . MTA_MORE_PAGES[$k] . '  ·  MTA open data, updated monthly'];
}

/** The biggest jobs and their typical pay, one row per job name. */
function mta_tm_card_jobs_pay()
{
    $out = [];
    foreach (get_option('mta_tm_jobs')['titles'] ?? [] as $t) {
        if (!isset($out[$t['title']])) {
            $out[$t['title']] = $t['med_pay'];
        }
    }
    return $out;
}

function mta_tm_card_pay()
{
    $p = get_option('mta_more_pay');
    if (empty($p['all']['med'])) {
        return null;
    }
    $a = $p['all'];
    return ['title' => 'MTA Salaries & Overtime (' . $p['year'] . ')', 'subtitle' => 'What MTA workers make, with no names shown',
            'stats' => [['$' . number_format($a['med']), 'typical (median) pay'], ['$' . round($a['total_ot'] / 1e9, 1) . 'B', 'paid in overtime']],
            'bars_title' => 'Workers who made…', 'fmt' => 'number_format',
            'bars' => [['$100,000 or more', $a['k100']], ['$200,000 or more', $a['k200']], ['$300,000 or more', $a['k300']]],
            'footer' => 'mantiereidii.com/' . MTA_MORE_PAGES['pay'] . '  ·  MTA payroll open data'];
}

function mta_tm_card_bridges()
{
    $b = get_option('mta_more_bridges');
    if (empty($b['facilities'])) {
        return null;
    }
    $days = max(1, $b['days']);
    $per = array_map(function ($n) use ($days) { return (int) round($n / $days); }, $b['facilities']);
    return ['title' => 'NYC Bridge & Tunnel Traffic', 'subtitle' => 'The busiest MTA crossings, hours and tolls',
            'stats' => [[number_format(round(array_sum($b['facilities']) / $days)), 'crossings a day'],
                        [round(($b['payment']['E-ZPass'] ?? 0) / max(1, array_sum($b['payment'])) * 100) . '%', 'paid with E-ZPass']],
            'bars_title' => 'Busiest crossings (a day)', 'bars' => mta_tm_card_bars($per), 'fmt' => 'number_format',
            'footer' => 'mantiereidii.com/' . MTA_MORE_PAGES['bridges'] . '  ·  MTA open data, updated monthly'];
}

function mta_tm_card_aar()
{
    $f = mta_tm_aar_facts();
    if (!$f || $f['on20'] === null) {
        return null;
    }
    return ['title' => 'Access-A-Ride Report Card', 'subtitle' => 'On-time pickups, trips, complaints and phone waits',
            'stats' => [[$f['on20'] . '%', 'pickups on time'], [number_format($f['per_day']), 'trips a day']],
            'bars_title' => 'Top complaints', 'bars' => mta_tm_card_bars($f['x']['complaints']), 'fmt' => 'number_format',
            'footer' => 'mantiereidii.com/' . MTA_TM_PAGES['aar'] . '  ·  MTA open data, updated monthly'];
}

function mta_tm_card_jobs()
{
    $f = mta_tm_jobs_facts();
    if (!$f) {
        return null;
    }
    $stats = [[number_format($f['total']), 'positions filled']];
    if ($f['hired']) {
        $stats[] = [number_format($f['hired']), 'hired in ' . $f['hire_year']];
    }
    return ['title' => 'MTA Jobs & What They Pay', 'subtitle' => 'Who works at the MTA, and the pay for each job', 'stats' => $stats,
            'bars_title' => 'Typical pay, biggest jobs', 'bars' => mta_tm_card_bars(mta_tm_card_jobs_pay()), 'fmt' => 'mta_tm_card_dollars',
            'footer' => 'mantiereidii.com/' . MTA_TM_PAGES['jobs'] . '  ·  MTA open data, no names'];
}

function mta_tm_card_ezpass()
{
    $e = get_option('mta_tm_ezpass');
    if (empty($e['places'])) {
        return null;
    }
    $cities = array_count_values(array_column($e['places'], 'city'));
    arsort($cities);
    return ['title' => 'Where to Get an E-ZPass', 'subtitle' => 'A map of every E-ZPass retailer in New York',
            'stats' => [[number_format(count($e['places'])), 'places to get a tag'], [number_format(count($cities)), 'cities and towns']],
            'bars_title' => 'Most places to get one', 'bars' => mta_tm_card_bars($cities), 'fmt' => 'number_format',
            'footer' => 'mantiereidii.com/' . MTA_TM_PAGES['ezpass'] . '  ·  New York State open data'];
}

function mta_tm_card_sir()
{
    $f = mta_tm_sir_facts();
    if (!$f || $f['otp'] === null) {
        return null;
    }
    $stats = [[$f['otp'] . '%', 'trains on time']];
    if ($f['per_day']) {
        $stats[] = [number_format($f['per_day']), 'rides a day'];
    }
    $bars = array_filter(['Weekdays' => $f['weekday'], 'Morning rush' => $f['am'], 'Evening rush' => $f['pm'], 'Weekends' => $f['weekend']], 'is_numeric');
    return ['title' => 'Staten Island Railway', 'subtitle' => 'On-time trains, riders, schedules and stations', 'stats' => $stats,
            'bars_title' => 'Trains on time', 'bars' => mta_tm_card_bars($bars), 'fmt' => 'mta_tm_card_pct', 'top' => 100,
            'footer' => 'mantiereidii.com/' . MTA_TM_PAGES['sir'] . '  ·  MTA open data, updated monthly'];
}

/** One card per railroad station: its tickets a day, its rank and where its riders go. */
function mta_tm_card_rail_station($k, $code)
{
    $names = [];
    $st = null;
    foreach (mta_tm_rail_stations($k) as $o) {
        $names[$o['code']] = $o['name'];
        if ($o['code'] === $code) {
            $st = $o;
        }
    }
    if (!$st) {
        return null;
    }
    $f = mta_tm_rail_station_facts($k, $st);
    $branch = $st['branch'] === 'City Terminal Zone' ? 'City Terminal Zone' : $st['branch'] . ' Branch';
    $card = ['title' => mb_strlen($st['name']) > 26 ? mb_substr($st['name'], 0, 25) . '…' : $st['name'],
             'subtitle' => $f['short'] . ' station · ' . $branch . ($st['zone'] !== '' ? ' · Zone ' . $st['zone'] : ''),
             'footer' => 'mantiereidii.com/' . MTA_TM_RAIL_SLUGS[$k] . '/' . $st['slug'] . '  ·  MTA open data'];
    $card['stats'] = $f['per_day'] ? [[number_format($f['per_day']), 'tickets a day from here'], ['#' . $st['rank'], 'busiest of ' . $f['count']]]
                                   : [[$st['zone'] !== '' ? $st['zone'] : '–', 'fare zone']];
    if (!empty($st['dest'])) {
        $bars = [];
        foreach ($st['dest'] as $c => $n) {
            $bars[$names[$c] ?? $c] = (int) round($n / max(1, $f['d']['days']));
        }
        $card += ['bars_title' => 'Where riders go (tickets a day)', 'bars' => mta_tm_card_bars($bars), 'fmt' => 'number_format'];
    } elseif ($f['crowd']) {
        $card['line'] = 'Trains to the city are fullest at ' . mta_ridership_hour_name($f['crowd']['hour']) . ' (' . $f['crowd']['occ'] . '% of seats)';
    }
    return $card;
}

/** Card name for a railroad station. */
function mta_tm_card_station_name($k, $code)
{
    return "rail-$k-" . strtolower($code);
}

add_filter('mta_share_cards', function ($cards) {
    $cards += ['lirr' => ['share.jpg', function () { return mta_tm_card_rail('lirr'); }],
               'mnr' => ['share.jpg', function () { return mta_tm_card_rail('mnr'); }],
               'pay' => ['share.jpg', 'mta_tm_card_pay'], 'bridges' => ['share.jpg', 'mta_tm_card_bridges']];
    if (!mta_tm_live()) {
        return $cards;
    }
    $cards += ['aar' => ['share.jpg', 'mta_tm_card_aar'], 'jobs' => ['share.jpg', 'mta_tm_card_jobs'],
               'ezpass' => ['share.jpg', 'mta_tm_card_ezpass'], 'sir' => ['share.jpg', 'mta_tm_card_sir']];
    foreach (MTA_TM_RAIL_SLUGS as $k => $slug) {
        foreach (mta_tm_rail_stations($k) as $st) {
            $code = $st['code'];
            $cards[mta_tm_card_station_name($k, $code)] = ['share.jpg', function () use ($k, $code) { return mta_tm_card_rail_station($k, $code); }];
        }
    }
    return $cards;
});

// Which page shows each card, so its cached copy is cleared when the card is redrawn.
add_filter('mta_share_page_urls', function ($urls, $card) {
    if (isset(MTA_MORE_PAGES[$card])) {
        return [mta_more_url($card)];
    }
    if (isset(MTA_TM_PAGES[$card])) {
        return [mta_tm_url($card)];
    }
    if (preg_match('/^rail-(lirr|mnr)-/', $card, $m)) {
        foreach (mta_tm_rail_stations($m[1]) as $st) {
            if (mta_tm_card_station_name($m[1], $st['code']) === $card) {
                return [mta_tm_rail_url($m[1], $st['slug'])];
            }
        }
    }
    return $urls;
}, 10, 2);

add_action('init', function () {
    foreach (['lirr', 'mnr', 'pay', 'bridges'] as $k) {
        mta_ridership_share_image(function () use ($k) { return is_page(MTA_MORE_PAGES[$k]) && !get_query_var('mta_rail_station'); }, $k);
    }
    foreach (array_keys(MTA_TM_PAGES) as $k) {
        mta_ridership_share_image(function () use ($k) { return mta_tm_live() && is_page(MTA_TM_PAGES[$k]); }, $k);
    }
    // Each station page shows its own card.
    mta_ridership_share_image(function () { return ($k = mta_tm_rail_key()) && mta_tm_rail_station_current($k); }, function () {
        $k = mta_tm_rail_key();
        return mta_tm_card_station_name($k, mta_tm_rail_station_current($k)['code']);
    });
});
