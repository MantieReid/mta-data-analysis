<?php
/**
 * Bus route report cards, [mta_buses]: the busiest, slowest and least reliable NYC bus routes, plus a
 * report card per route at /bus-routes/<route>/ (riders, busiest hours, speed, spacing, service run,
 * busiest stops, bus-lane camera tickets), which the MTA publishes only as separate dashboards.
 * Data (data.ny.gov): Bus Hourly Ridership (gxb3-akrn), Bus Speeds (cudb-vcni), Wait Assessment (v4z4-2h6n),
 * Service Delivered (6qwi-vjde), Customer Journey-Focused Metrics (8mkn-d32t), Stop Ridership Estimates
 * (fvdm-uavx), Automated Camera Enforcement Violations (kh8p-hcbm), Bus Routes (bzwk-3hb4), Bus Stops (2ucp-7wg5).
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_BUSES_HOOK   = 'mta_ridership_buses_step';
const MTA_BUSES_OPTION = 'mta_ridership_buses';
const MTA_BUSES_SLUG   = 'bus-routes';

add_action(MTA_BUSES_HOOK, 'mta_buses_step');

add_action('init', function () {
    add_rewrite_rule('^' . MTA_BUSES_SLUG . '/([a-z0-9-]+)/?$', 'index.php?pagename=' . MTA_BUSES_SLUG . '&mta_bus=$matches[1]', 'top');
    if (get_option('mta_buses_rewrite') !== '1') {
        flush_rewrite_rules(false);
        update_option('mta_buses_rewrite', '1');
    }
    if (!get_option(MTA_BUSES_OPTION) && get_option(MTA_LINES_OPTION)) {
        mta_buses_schedule(10);
    }
});
add_filter('query_vars', function ($v) {
    $v[] = 'mta_bus';
    return $v;
});

function mta_buses_schedule($delay)
{
    if (!wp_next_scheduled(MTA_BUSES_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_BUSES_HOOK);
    }
}

/** "M15+" => "m15-sbs", "BX12" => "bx12". */
function mta_buses_slug($id)
{
    return strtolower(str_replace('+', '-sbs', $id));
}

/** "M15+" => "M15-SBS", "BX12" => "Bx12" (the way the MTA writes route names). */
function mta_buses_label($id)
{
    $l = preg_replace('/^BX/', 'Bx', $id);
    return str_replace('+', '-SBS', $l);
}

function mta_buses_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }
    try {
        $api = function ($id, $params) { return mta_ridership_api("https://data.ny.gov/resource/$id.json", $params + ['$limit' => 50000]); };
        $max = $api('cudb-vcni', ['$select' => 'max(month) AS m']);
        $latest = substr($max[0]['m'] ?? '', 0, 7);
        if (!$latest) {
            throw new Exception('No bus speed data returned');
        }
        $month = function ($n) use ($latest) { return gmdate('Y-m', strtotime("$latest-01 -$n month UTC")); };
        $from = $month(11);
        $to_excl = gmdate('Y-m-d', strtotime("$latest-01 +1 month UTC"));
        $in12 = "month >= '$from-01' AND month < '$to_excl'";
        $days = (int) round((strtotime("$to_excl UTC") - strtotime("$from-01 UTC")) / 86400);

        // Route names and types (current version of each route).
        $meta = [];
        foreach ($api('bzwk-3hb4', ['$select' => 'route_id, route_short_name, route_long_name, route_type',
                                    '$where' => "in_effect = 'true'", '$group' => 'route_id, route_short_name, route_long_name, route_type']) as $r) {
            $id = strtoupper($r['route_id']);
            if (!isset($meta[$id]) || !in_array($r['route_type'], ['School', 'School Limited'], true)) {
                $meta[$id] = [$r['route_short_name'], $r['route_long_name'], $r['route_type']];
            }
        }

        $routes = [];
        $add = function ($id, $k, $v) use (&$routes) {
            $id = strtoupper($id);
            if (preg_match('/^(T\d|SHUTTLE)/', $id)) { // temporary shuttles (e.g. T321, a 7-train replacement bus)
                return;
            }
            $routes[$id][$k] = $v;
        };

        // Riders a day over the last 12 months. The hourly ridership table is large, so ask one month at a time.
        $riders = [];
        for ($i = 11; $i >= 0; $i--) {
            $a = $month($i) . '-01';
            $b = gmdate('Y-m-d', strtotime("$a +1 month UTC"));
            foreach ($api('gxb3-akrn', ['$select' => 'bus_route AS r, sum(ridership) AS n',
                                        '$where' => "transit_timestamp >= '$a' AND transit_timestamp < '$b'", '$group' => 'r']) as $r) {
                $riders[strtoupper($r['r'])] = ($riders[strtoupper($r['r'])] ?? 0) + (float) $r['n'];
            }
        }
        foreach ($riders as $id => $n) {
            $add($id, 'riders', (int) round($n / $days));
        }
        // Riders per hour on an average weekday, last 8 weeks of ridership data.
        $rmax = $api('gxb3-akrn', ['$select' => 'max(transit_timestamp) AS m']);
        $r_latest = substr($rmax[0]['m'] ?? $to_excl, 0, 10);
        $h_from = gmdate('Y-m-d', strtotime("$r_latest -55 day UTC"));
        $wd = 0;
        for ($d = $h_from; $d <= $r_latest; $d = gmdate('Y-m-d', strtotime("$d +1 day UTC"))) {
            $wd += gmdate('N', strtotime("$d UTC")) <= 5 ? 1 : 0;
        }
        $hsum = [];
        for ($lo = $h_from; $lo <= $r_latest; $lo = $hi) { // one week per call (8 weeks at once takes over 3 minutes)
            $hi = gmdate('Y-m-d', strtotime("$lo +7 day UTC"));
            foreach ($api('gxb3-akrn', ['$select' => 'bus_route AS r, date_extract_hh(transit_timestamp) AS h, sum(ridership) AS n',
                                        '$where' => "transit_timestamp >= '$lo' AND transit_timestamp < '$hi' AND date_extract_dow(transit_timestamp) BETWEEN 1 AND 5",
                                        '$group' => 'r, h']) as $r) {
                $hsum[strtoupper($r['r'])][(int) $r['h']] = ($hsum[strtoupper($r['r'])][(int) $r['h']] ?? 0) + (float) $r['n'];
            }
        }
        $hours = [];
        foreach ($hsum as $id => $hs) {
            foreach ($hs as $h => $n) {
                $hours[$id][$h] = (int) round($n / max(1, $wd));
            }
        }
        // Average speed on weekdays, this year and the 12 months before.
        $speed = function ($a, $b) use ($api) {
            $o = [];
            foreach ($api('cudb-vcni', ['$select' => 'route_id AS r, sum(total_mileage) AS mi, sum(total_operating_time) AS t',
                                        '$where' => "month >= '$a-01' AND month <= '$b-01' AND day_type = '1'", '$group' => 'r']) as $r) {
                if ((float) $r['t'] > 0) {
                    $o[strtoupper($r['r'])] = round($r['mi'] / $r['t'], 1);
                }
            }
            return $o;
        };
        foreach ($speed($from, $latest) as $id => $v) {
            $add($id, 'mph', $v);
        }
        foreach ($speed($month(23), $month(12)) as $id => $v) {
            $add($id, 'mph_prev', $v);
        }
        // Buses keeping their spacing (wait assessment) and service actually run.
        foreach ($api('v4z4-2h6n', ['$select' => 'route_id AS r, sum(number_of_trips_passing_wait) AS a, sum(number_of_scheduled_trips) AS b',
                                    '$where' => $in12, '$group' => 'r']) as $r) {
            if ((float) $r['b'] > 0) {
                $add($r['r'], 'wait', round($r['a'] / $r['b'] * 100, 1));
            }
        }
        foreach ($api('6qwi-vjde', ['$select' => 'route_id AS r, sum(actual_number_of_buses) AS a, sum(scheduled_number_of_buses) AS b',
                                    '$where' => $in12, '$group' => 'r']) as $r) {
            if ((float) $r['b'] > 0) {
                $add($r['r'], 'service', round(min(1, $r['a'] / $r['b']) * 100, 1));
            }
        }
        // Extra time at stops and on board vs schedule (minutes, weighted by customers).
        foreach ($api('8mkn-d32t', ['$select' => 'route_id AS r, sum(number_of_customers) AS c, sum(number_of_customers * additional_bus_stop_time) AS s, sum(number_of_customers * additional_travel_time) AS t',
                                    '$where' => $in12, '$group' => 'r']) as $r) {
            if ((float) $r['c'] > 0) {
                $add($r['r'], 'extra_stop', round($r['s'] / $r['c'], 1));
                $add($r['r'], 'extra_ride', round($r['t'] / $r['c'], 1));
            }
        }
        // Bus-lane and bus-stop camera tickets (Automated Camera Enforcement), last 12 months.
        foreach ($api('kh8p-hcbm', ['$select' => 'bus_route_id AS r, count(*) AS n',
                                    '$where' => "first_occurrence >= '$from-01' AND first_occurrence < '$to_excl'", '$group' => 'r']) as $r) {
            foreach (explode('/', $r['r']) as $id) { // a few camera routes are shared, e.g. "BX12/BX22"
                $add(trim($id), 'tickets', ($routes[strtoupper(trim($id))]['tickets'] ?? 0) + (int) $r['n']);
            }
        }
        // Busiest stops: estimated boardings on an average weekday, latest month of the estimates.
        $smax = $api('fvdm-uavx', ['$select' => 'max(month) AS m']);
        $s_month = substr($smax[0]['m'] ?? '', 0, 7);
        $stops = [];
        if ($s_month) {
            // Each row adds up every Monday (Tuesday…) of the month, so divide by the number of weekdays in it.
            $s_days = 0;
            for ($d = "$s_month-01"; substr($d, 0, 7) === $s_month; $d = gmdate('Y-m-d', strtotime("$d +1 day UTC"))) {
                $s_days += gmdate('N', strtotime("$d UTC")) <= 5 ? 1 : 0;
            }
            foreach ($api('fvdm-uavx', ['$select' => 'route_id AS r, stop_id AS s, sum(estimated_ridership) AS n',
                                        '$where' => "month = '$s_month-01' AND day_type = 'weekday'", '$group' => 'r, s', '$order' => 'n DESC']) as $r) {
                $id = strtoupper($r['r']);
                if (isset($r['n']) && count($stops[$id] ?? []) < 5) { // no estimate for some stops
                    $stops[$id][] = [$r['s'], (int) round($r['n'] / max(1, $s_days))];
                }
            }
            $names = [];
            foreach ($api('2ucp-7wg5', ['$select' => 'stop_id, stop_name', '$group' => 'stop_id, stop_name']) as $r) {
                $names[$r['stop_id']] = ucwords(strtolower($r['stop_name']), " /-");
            }
            foreach ($stops as $id => $list) {
                $add($id, 'stops', array_map(function ($x) use ($names) { return [$names[$x[0]] ?? ('Stop ' . $x[0]), $x[1]]; }, $list));
            }
        }

        // Keep real routes with ridership; name, type, borough letter, slug, hours.
        $boroughs = ['B' => 'Brooklyn', 'BX' => 'Bronx', 'M' => 'Manhattan', 'Q' => 'Queens', 'S' => 'Staten Island',
                     'BM' => 'Express (Brooklyn)', 'BXM' => 'Express (Bronx)', 'QM' => 'Express (Queens)', 'SIM' => 'Express (Staten Island)', 'X' => 'Express'];
        $out = [];
        foreach ($routes as $id => $r) {
            if (empty($r['riders']) || $r['riders'] < 50 || !isset($r['mph'])) {
                continue;
            }
            preg_match('/^[A-Z]+/', $id, $pm);
            $m = $meta[$id] ?? [mta_buses_label($id), '', ''];
            $type = $m[2] === 'SBS' || substr($id, -1) === '+' ? 'Select Bus Service' : ($m[2] ?: 'Local');
            $out[$id] = $r + ['id' => $id, 'slug' => mta_buses_slug($id), 'label' => mta_buses_label($id), 'name' => $m[1],
                              'type' => $type, 'express' => $type === 'Express', 'group' => $boroughs[$pm[0] ?? ''] ?? 'Other',
                              'hours' => array_values(array_replace(array_fill(0, 24, 0), $hours[$id] ?? []))];
        }
        // Ranks: riders (1 = busiest); speed and spacing among local, limited and SBS routes (1 = fastest / most reliable).
        $rank = function ($key, $desc, $filter) use (&$out) {
            $ids = array_keys(array_filter($out, function ($r) use ($key, $filter) { return isset($r[$key]) && $filter($r); }));
            usort($ids, function ($a, $b) use ($out, $key, $desc) { return $desc ? $out[$b][$key] <=> $out[$a][$key] : $out[$a][$key] <=> $out[$b][$key]; });
            foreach ($ids as $i => $id) {
                $out[$id]["{$key}_rank"] = $i + 1;
                $out[$id]["{$key}_of"] = count($ids);
            }
        };
        $rank('riders', true, function () { return true; });
        $rank('mph', true, function ($r) { return !$r['express']; });
        $rank('wait', true, function ($r) { return !$r['express']; });
        uasort($out, function ($a, $b) { return $b['riders'] <=> $a['riders']; });

        $local = array_filter($out, function ($r) { return !$r['express']; });
        $avg = function ($rows, $k, $w = 'riders') {
            $n = $d = 0;
            foreach ($rows as $r) {
                if (isset($r[$k])) {
                    $n += $r[$k] * $r[$w];
                    $d += $r[$w];
                }
            }
            return $d ? round($n / $d, 1) : null;
        };
        update_option(MTA_BUSES_OPTION, [
            'updated' => gmdate('c'), 'from' => $from, 'latest' => $latest, 'stops_month' => $s_month, 'hours_from' => $h_from, 'hours_to' => $r_latest,
            'system' => ['riders' => array_sum(array_column($out, 'riders')), 'mph' => $avg($local, 'mph'), 'wait' => $avg($local, 'wait'),
                         'service' => $avg($out, 'service'), 'tickets' => array_sum(array_column($out, 'tickets')), 'routes' => count($out)],
            'routes' => $out,
        ], false);
        delete_option('mta_ridership_buses_error');
        mta_buses_clear_cache();
    } catch (Throwable $e) {
        update_option('mta_ridership_buses_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_BUSES_HOOK, 'mta_buses_schedule');
    }
}

function mta_buses_page()
{
    return get_page_by_path(MTA_BUSES_SLUG, OBJECT, 'page');
}

function mta_buses_url($slug = '')
{
    return home_url('/' . MTA_BUSES_SLUG . '/' . ($slug ? $slug . '/' : ''));
}

function mta_buses_clear_cache()
{
    $page = mta_buses_page();
    if (!$page || !function_exists('rocket_clean_post')) {
        return;
    }
    rocket_clean_post($page->ID);
    $d = get_option(MTA_BUSES_OPTION);
    if ($d && function_exists('rocket_clean_files')) {
        rocket_clean_files(array_map('mta_buses_url', array_column($d['routes'], 'slug')));
    }
}

/** The route being viewed (/bus-routes/<slug>/), or null on the ranking page. */
function mta_buses_current()
{
    $slug = get_query_var('mta_bus');
    $d = get_option(MTA_BUSES_OPTION);
    if (!$slug || !$d) {
        return null;
    }
    foreach ($d['routes'] as $r) {
        if ($r['slug'] === $slug) {
            return $r;
        }
    }
    return null;
}

add_action('template_redirect', function () {
    if (get_query_var('mta_bus') && !mta_buses_current()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    }
});

function mta_buses_title($r)
{
    return $r['label'] . ' bus' . ($r['name'] ? ' (' . $r['name'] . ')' : '');
}

function mta_buses_answers($d, $r = null)
{
    $s = $d['system'];
    $range = mta_lines_month($d['from']) . ' – ' . mta_lines_month($d['latest']);
    $routes = $d['routes'];
    $local = array_filter($routes, function ($x) { return !$x['express'] && isset($x['mph_rank']); });
    if (!$r) {
        $busy = array_values($routes);
        uasort($local, function ($a, $b) { return $a['mph'] <=> $b['mph']; });
        $slow = array_values($local);
        return [
            'busiest' => 'The busiest NYC bus route is the ' . mta_buses_title($busy[0]) . ', with about ' . number_format($busy[0]['riders']) .
                ' riders a day (' . $range . '), followed by the ' . $busy[1]['label'] . ' (' . number_format($busy[1]['riders']) . ') and the ' .
                $busy[2]['label'] . ' (' . number_format($busy[2]['riders']) . ').',
            'slowest' => 'The slowest bus route is the ' . mta_buses_title($slow[0]) . ', averaging ' . $slow[0]['mph'] . ' mph on weekdays, about walking pace. ' .
                'Local and Select Bus Service routes average ' . $s['mph'] . ' mph.',
            'riders' => 'NYC buses carry about ' . number_format($s['riders']) . ' riders a day across ' . $s['routes'] . ' routes.',
            'tickets' => 'Bus-lane and bus-stop cameras issued about ' . number_format($s['tickets']) . ' violations over the same 12 months.',
        ];
    }
    $a = [
        'riders' => 'The ' . $r['label'] . ' carries about ' . number_format($r['riders']) . ' riders a day (' . $range . '), #' . $r['riders_rank'] . ' of ' .
            $r['riders_of'] . ' NYC bus routes.',
    ];
    $peak = max($r['hours']);
    if ($peak > 0) {
        $a['busy'] = 'On weekdays it is busiest around ' . mta_ridership_hour_name(array_search($peak, $r['hours'])) . ', with about ' . number_format($peak) . ' riders that hour.';
    }
    if (isset($r['mph'])) {
        $a['speed'] = 'It averages ' . $r['mph'] . ' mph on weekdays' . (isset($r['mph_rank']) ? ', #' . $r['mph_rank'] . ' fastest of ' . $r['mph_of'] . ' local and Select Bus routes' : '') .
            (isset($r['mph_prev']) ? ($r['mph'] == $r['mph_prev'] ? ', the same as the year before'
                : ', ' . ($r['mph'] > $r['mph_prev'] ? 'up' : 'down') . ' from ' . $r['mph_prev'] . ' mph the year before') : '') . '.';
    }
    if (isset($r['wait'])) {
        $a['reliable'] = $r['wait'] . '% of its trips kept to their scheduled spacing (the MTA\'s "wait assessment"; local routes overall: ' . $s['wait'] . '%)' .
            (isset($r['service']) ? ', and ' . $r['service'] . '% of scheduled buses actually ran' : '') . '.';
    }
    if (!empty($r['tickets'])) {
        $a['tickets'] = 'Bus cameras on the ' . $r['label'] . ' issued about ' . number_format($r['tickets']) . ' violations to vehicles blocking its bus lanes or stops in the last 12 months.';
    }
    return $a;
}

function mta_buses_faq($d, $r = null)
{
    $a = mta_buses_answers($d, $r);
    $q = $r ? ['riders' => "How many people ride the {$r['label']} bus?", 'busy' => "When is the {$r['label']} busiest?",
               'speed' => "How fast is the {$r['label']} bus?", 'reliable' => "Is the {$r['label']} bus reliable?",
               'tickets' => "How many bus-lane tickets are issued on the {$r['label']}?"]
            : ['busiest' => 'What is the busiest bus route in NYC?', 'slowest' => 'What is the slowest bus in NYC?',
               'riders' => 'How many people ride NYC buses?', 'tickets' => 'How many bus-lane camera tickets does NYC issue?'];
    $out = [];
    foreach ($q as $k => $question) {
        if (isset($a[$k])) {
            $out[] = [$question, $a[$k]];
        }
    }
    return $out;
}

add_shortcode('mta_buses', function () {
    $d = get_option(MTA_BUSES_OPTION);
    if (!$d) {
        mta_buses_schedule(5);
        return '<p>Bus route data is loading. Check back in a few minutes.</p>';
    }
    $r = mta_buses_current();
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.10.0');
    wp_enqueue_script('mta-buses', $base . 'assets/mta-buses.js', ['chartjs'], '1.10.0', true);
    $js = $r ? ['route' => ['label' => $r['label'], 'hours' => $r['hours']]]
             : ['routes' => array_values(array_map(function ($x) {
                   return [$x['label'], $x['name'], $x['slug'], $x['riders'], $x['mph'] ?? null, $x['group']];
               }, $d['routes']))];
    wp_add_inline_script('mta-buses', 'window.MTA_BUSES = ' . wp_json_encode($js) . ';', 'before');

    $s = $d['system'];
    $range = esc_html(mta_lines_month($d['from']) . ' – ' . mta_lines_month($d['latest']));
    $tile = function ($big, $small) { return '<div class="mta-tile"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>'; };
    $sec = function ($title, $body, $note = '') {
        return '<section class="mta-section"><h3>' . esc_html($title) . '</h3>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
    };
    $link = function ($x) { return '<a href="' . esc_url(mta_buses_url($x['slug'])) . '">' . esc_html($x['label']) . '</a>'; };
    $table = function ($head, $rows) {
        $o = '<div class="mta-table-wrap"><table class="mta-table"><thead><tr>';
        foreach ($head as $h) {
            $o .= '<th>' . esc_html($h) . '</th>';
        }
        $o .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $o .= '<tr><td>' . implode('</td><td>', $row) . '</td></tr>';
        }
        return $o . '</tbody></table></div>';
    };
    $a = mta_buses_answers($d, $r);
    $o = '';

    if ($r) {
        $o .= '<header class="mta-hero"><h1>' . esc_html($r['label']) . ' Bus: Riders, Speed &amp; Reliability</h1>' .
            '<p class="mta-hero-links">' . esc_html(trim($r['name'] . ' · ' . $r['type'], ' ·')) . ' · Report card from MTA open data, ' . $range .
            ' · Not affiliated with the MTA · <a href="' . esc_url('https://bustime.mta.info/m/?q=' . rawurlencode(str_replace('+', '-SBS', $r['id']))) . '" rel="noopener">Live bus times on MTA Bus Time &rarr;</a></p>' .
            '<div class="mta-tiles mta-tiles-find">' .
            $tile(number_format($r['riders']), 'riders a day (#' . $r['riders_rank'] . ' of ' . $r['riders_of'] . ')') .
            (isset($r['mph']) ? $tile($r['mph'] . ' mph', 'average weekday speed' . (isset($r['mph_rank']) ? ' (#' . $r['mph_rank'] . ' fastest)' : '')) : '') .
            (isset($r['wait']) ? $tile($r['wait'] . '%', 'trips kept their spacing') : '') .
            (isset($r['service']) ? $tile($r['service'] . '%', 'scheduled buses that ran') : '') .
            '</div></header>';
        $o .= $sec('How busy is the ' . $r['label'] . '?', '<p>' . esc_html($a['riders'] . (isset($a['busy']) ? ' ' . $a['busy'] : '')) . '</p><div data-slot="hours"></div>',
            'Average riders per hour on weekdays, ' . esc_html(mta_ridership_nice_date($d['hours_from']) . ' – ' . mta_ridership_nice_date($d['hours_to'])) . '.');
        if (!empty($r['stops'])) {
            $o .= $sec('Busiest stops', $table(['Stop', 'Boardings on an average weekday'], array_map(function ($x) {
                return [esc_html($x[0]), number_format($x[1])];
            }, $r['stops'])), 'MTA estimates for ' . esc_html(mta_lines_month($d['stops_month'])) . ' from passenger counters on the buses, both directions. ' .
                'They count everyone who boards, so they can run higher than fare-based ridership.');
        }
        $o .= $sec('Speed and reliability', '<p>' . esc_html(trim(($a['speed'] ?? '') . ' ' . ($a['reliable'] ?? ''))) . '</p>' .
            $table(['', $r['label'], 'All local and SBS routes'], [
                ['Average weekday speed', isset($r['mph']) ? $r['mph'] . ' mph' : '–', $s['mph'] . ' mph'],
                ['Trips that kept their scheduled spacing', isset($r['wait']) ? $r['wait'] . '%' : '–', $s['wait'] . '%'],
                ['Scheduled buses that actually ran', isset($r['service']) ? $r['service'] . '%' : '–', $s['service'] . '%'],
                ['Extra time at stops vs schedule', isset($r['extra_stop']) ? $r['extra_stop'] . ' min' : '–', '–'],
                ['Extra time on board vs schedule', isset($r['extra_ride']) ? $r['extra_ride'] . ' min' : '–', '–'],
                ['Bus-lane camera violations (12 months)', !empty($r['tickets']) ? number_format($r['tickets']) : '–', number_format($s['tickets'])],
            ]), $range . '. Speed includes time at stops and lights. "Spacing" is the MTA\'s wait assessment: the gap between buses stays within a few minutes of schedule.');
        if (isset($a['tickets'])) {
            $o .= '<p>' . esc_html($a['tickets']) . '</p>';
        }
        $same = array_filter($d['routes'], function ($x) use ($r) { return $x['group'] === $r['group'] && $x['id'] !== $r['id']; });
        $o .= $sec('Other ' . $r['group'] . ' routes', '<p class="mta-find-picks"><a class="mta-find-pick" href="' . esc_url(mta_buses_url()) . '">All routes</a> ' .
            implode(' ', array_map(function ($x) { return '<a class="mta-find-pick" href="' . esc_url(mta_buses_url($x['slug'])) . '">' . esc_html($x['label']) . '</a>'; }, $same)) . '</p>');
    } else {
        $o .= '<header class="mta-hero"><h1>NYC Bus Routes: Busiest, Slowest &amp; Least Reliable</h1>' .
            '<p class="mta-hero-links">Report cards for every NYC bus route from MTA open data, ' . $range . ' · Not affiliated with the MTA</p><div class="mta-tiles">' .
            $tile(number_format($s['riders']), 'bus riders a day') . $tile($s['mph'] . ' mph', 'average local bus speed') . $tile((string) $s['routes'], 'routes') .
            '</div></header>';
        $o .= $sec('Find your bus route', '<div data-slot="find"></div>', 'Type a route (M15, Bx12, B46, SIM22…) or a street.');
        $routes = $d['routes'];
        $o .= $sec('Busiest bus routes', '<p>' . esc_html($a['busiest']) . '</p>' . $table(['#', 'Route', 'Riders a day', 'Avg speed'],
            array_map(function ($x, $i) use ($link) { return [$i + 1, $link($x) . ' <span class="mta-lines">' . esc_html($x['name']) . '</span>', number_format($x['riders']), isset($x['mph']) ? $x['mph'] . ' mph' : '–']; },
                array_slice(array_values($routes), 0, 15), range(0, 14))));
        $local = array_values(array_filter($routes, function ($x) { return isset($x['mph_rank']); }));
        usort($local, function ($x, $y) { return $x['mph'] <=> $y['mph']; });
        $o .= $sec('Slowest bus routes', '<p>' . esc_html($a['slowest']) . '</p>' . $table(['Route', 'Avg weekday speed', 'Riders a day'],
            array_map(function ($x) use ($link) { return [$link($x) . ' <span class="mta-lines">' . esc_html($x['name']) . '</span>', $x['mph'] . ' mph', number_format($x['riders'])]; }, array_slice($local, 0, 15))),
            'Local, limited and Select Bus Service routes. Express buses are left out because they mostly run on highways.');
        $rel = array_values(array_filter($routes, function ($x) { return isset($x['wait_rank']) && $x['riders'] >= 2000; }));
        usort($rel, function ($x, $y) { return $x['wait'] <=> $y['wait']; });
        $o .= $sec('Least reliable bus routes', $table(['Route', 'Trips that kept their spacing', 'Riders a day'],
            array_map(function ($x) use ($link) { return [$link($x) . ' <span class="mta-lines">' . esc_html($x['name']) . '</span>', $x['wait'] . '%', number_format($x['riders'])]; }, array_slice($rel, 0, 15))),
            'Routes with 2,000+ riders a day. All local and SBS routes: ' . $s['wait'] . '% of trips kept their scheduled spacing.');
        $tix = array_values(array_filter($routes, function ($x) { return !empty($x['tickets']); }));
        usort($tix, function ($x, $y) { return $y['tickets'] <=> $x['tickets']; });
        $o .= $sec('Most bus-lane camera violations', '<p>' . esc_html($a['tickets']) . '</p>' . $table(['Route', 'Violations (12 months)'],
            array_map(function ($x) use ($link) { return [$link($x), number_format($x['tickets'])]; }, array_slice($tix, 0, 10))),
            'Cameras on buses ticket vehicles parked or driving in bus lanes and at bus stops.');
        $groups = [];
        foreach ($routes as $x) {
            $groups[$x['group']][] = $x;
        }
        ksort($groups);
        $all = '';
        foreach ($groups as $g => $xs) {
            usort($xs, function ($x, $y) { return strnatcasecmp($x['label'], $y['label']); });
            $all .= '<h4>' . esc_html($g) . '</h4><p class="mta-find-picks">' . implode(' ', array_map(function ($x) {
                return '<a class="mta-find-pick" href="' . esc_url(mta_buses_url($x['slug'])) . '">' . esc_html($x['label']) . '</a>';
            }, $xs)) . '</p>';
        }
        $o .= $sec('Every route', $all);
    }

    $faq = '';
    foreach (mta_buses_faq($d, $r) as [$q, $ans]) {
        $faq .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($ans) . '</p></details>';
    }
    $o .= '<section class="mta-section mta-faq"><h3>Questions</h3>' . $faq . '</section>';
    $o .= mta_ridership_ssr_more('buses');
    $o .= mta_ridership_ssr_talk(null, $r ? "How busy, fast and reliable is the {$r['label']} bus? Report card from MTA open data" : 'NYC bus routes ranked: busiest, slowest and least reliable, from MTA open data');
    $o .= '<p class="mta-source mta-source-ssr">Data (data.ny.gov): <a href="https://data.ny.gov/d/gxb3-akrn" rel="noopener">Bus Hourly Ridership</a>, ' .
        '<a href="https://data.ny.gov/d/cudb-vcni" rel="noopener">Bus Speeds</a>, <a href="https://data.ny.gov/d/v4z4-2h6n" rel="noopener">Wait Assessment</a>, ' .
        '<a href="https://data.ny.gov/d/6qwi-vjde" rel="noopener">Service Delivered</a>, <a href="https://data.ny.gov/d/8mkn-d32t" rel="noopener">Customer Journey Metrics</a>, ' .
        '<a href="https://data.ny.gov/d/fvdm-uavx" rel="noopener">Stop Ridership Estimates</a>, <a href="https://data.ny.gov/d/kh8p-hcbm" rel="noopener">Camera Enforcement Violations</a>. ' .
        'Updated ' . esc_html(gmdate('M j, Y', strtotime($d['updated']))) . '. The MTA\'s own charts: <a href="https://metrics.mta.info/?bus/servicedelivered" rel="noopener">metrics.mta.info</a>.<br>' .
        'Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership mta-buses">' . $o . '</div>';
});

/* ---------- SEO: one title, description and canonical URL per route ---------- */

function mta_buses_seo()
{
    $d = get_option(MTA_BUSES_OPTION);
    if (!$d || !is_singular()) {
        return null;
    }
    $post = get_queried_object();
    if (!$post || !has_shortcode($post->post_content ?? '', 'mta_buses')) {
        return null;
    }
    $r = mta_buses_current();
    $s = $d['system'];
    if ($r) {
        return ['d' => $d, 'r' => $r, 'url' => mta_buses_url($r['slug']),
                'title' => $r['label'] . ' Bus' . ($r['name'] ? ' (' . $r['name'] . ')' : '') . ': Riders, Speed & Reliability',
                'description' => "{$r['label']} bus report card: " . number_format($r['riders']) . ' riders a day' .
                    (isset($r['mph']) ? ", {$r['mph']} mph average speed" : '') . (isset($r['wait']) ? ", {$r['wait']}% of trips on schedule spacing" : '') .
                    ', busiest hours and stops. From MTA open data.'];
    }
    return ['d' => $d, 'r' => null, 'url' => mta_buses_url(),
            'title' => 'NYC Bus Routes Ranked: Busiest, Slowest & Least Reliable Buses',
            'description' => 'Every NYC bus route ranked by riders, speed and reliability, with a report card for each route. ' .
                number_format($s['riders']) . " bus riders a day; local buses average {$s['mph']} mph. From MTA open data."];
}

add_filter('wpseo_title', function ($t) { return ($s = mta_buses_seo()) ? $s['title'] . ' - ' . get_bloginfo('name') : $t; });
add_filter('wpseo_opengraph_title', function ($t) { return ($s = mta_buses_seo()) ? $s['title'] : $t; });
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($t) { return ($s = mta_buses_seo()) ? $s['description'] : $t; });
}
foreach (['wpseo_canonical', 'wpseo_opengraph_url'] as $f) {
    add_filter($f, function ($u) { return ($s = mta_buses_seo()) ? $s['url'] : $u; });
}
add_action('wp_head', function () {
    if (!($s = mta_buses_seo())) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($i) {
            return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
        }, mta_buses_faq($s['d'], $s['r'])),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});

add_filter('wpseo_sitemap_page_content', function ($xml) {
    $page = mta_buses_page();
    $d = get_option(MTA_BUSES_OPTION);
    if (!$page || $page->post_status !== 'publish' || !$d) {
        return $xml;
    }
    foreach ($d['routes'] as $r) {
        $xml .= '<url><loc>' . esc_url(mta_buses_url($r['slug'])) . '</loc><lastmod>' . esc_html(gmdate('c', strtotime($d['updated']))) . "</lastmod></url>\n";
    }
    return $xml;
});
