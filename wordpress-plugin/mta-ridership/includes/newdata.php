<?php
/**
 * Five pages from MTA open data the site didn't use before, fetched once a month after the "nt" step:
 *  - subway cars: miles between breakdowns by train model, "hot car" air-conditioning repairs by model, line and car,
 *    and every MTA train model with its age (Subway Mean Distance Between Failures, Hot Car Work Orders, Railcar Fleet Inventory);
 *  - subway planned work: work orders ("general orders") by line, station, borough, schedule and month;
 *  - LIRR crowding: the fullest trains, branches at rush hour, by hour and by train model (LIRR Trip-Level Occupancy);
 *  - buses: breakdowns by borough, bus age and fare evasion;
 *  - MTA money: the yearly statement of operations, debt outstanding and some of the taxes and fees that fund the MTA.
 * Each page is stored in its own option and kept from last month if its fetch fails. Ported from tools/preview_new_data.py.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_ND_HOOK = 'mta_ridership_nd_step';
const MTA_ND_PAGES = ['cars' => 'subway-cars', 'work' => 'subway-planned-work', 'lirr' => 'lirr-crowding', 'buses' => 'bus-reliability', 'money' => 'mta-money'];

add_action(MTA_ND_HOOK, function () { mta_ridership_run_step('nd'); });

add_filter('mta_ridership_steps', function ($steps) {
    $steps['nd'] = ['label' => 'Subway cars, planned work, LIRR crowding, bus reliability, MTA money', 'fn' => 'mta_nd_step', 'hook' => MTA_ND_HOOK, 'err' => 'mta_ridership_nd_error',
                    'through' => function () { $o = get_option('mta_nd_cars'); return empty($o['last']) ? null : gmdate('Y-m-t', strtotime($o['last'] . '-01 UTC')); }, 'public' => false,
                    'max_age' => (int) (((array) get_option('mta_ridership_max_age', []))['nd'] ?? 75)];
    return $steps;
});

// Runs after the "nt" step each month, and once by itself the first time.
add_action('mta_ridership_step_done', function ($key, $ok) {
    if ($key === 'nt' && $ok) {
        mta_nd_schedule(600);
    }
}, 10, 2);
add_action('init', function () {
    if (!get_option('mta_nd_cars') && !get_option('mta_ridership_nd_error')) {
        mta_nd_schedule(60);
    }
});

/** Live since 2026-11-10; return mta_ridership_preview() to put the pages back in review. */
function mta_nd_live()
{
    return true;
}

function mta_nd_url($key)
{
    return home_url('/' . MTA_ND_PAGES[$key] . '/');
}

function mta_nd_schedule($delay)
{
    if (!wp_next_scheduled(MTA_ND_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_ND_HOOK);
    }
}

add_action('template_redirect', function () {
    foreach (MTA_ND_PAGES as $slug) {
        if (is_page($slug) && !mta_nd_live()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
    }
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    if (mta_nd_live()) {
        return $ids;
    }
    foreach (MTA_ND_PAGES as $slug) {
        if ($p = get_page_by_path($slug, OBJECT, 'page')) {
            $ids[] = $p->ID;
        }
    }
    return $ids;
});

/** A SoQL query on data.ny.gov (50,000 rows unless the query sets its own LIMIT; the API's default is 1,000). */
function mta_nd_q($id, $soql)
{
    if (stripos($soql, ' LIMIT ') === false) {
        $soql .= ' LIMIT 50000';
    }
    return (array) mta_ridership_api("https://data.ny.gov/resource/$id.json", ['$query' => $soql]);
}

function mta_nd_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }
    $parts = ['mta_nd_cars' => 'mta_nd_cars_fetch', 'mta_nd_work' => 'mta_nd_work_fetch', 'mta_nd_lirr' => 'mta_nd_lirr_fetch',
              'mta_nd_buses' => 'mta_nd_buses_fetch', 'mta_nd_money' => 'mta_nd_money_fetch'];
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
        update_option('mta_ridership_nd_error', gmdate('c') . ' ' . implode(' · ', $errors), false);
        mta_ridership_retry_later(MTA_ND_HOOK, 'mta_nd_schedule');
    } else {
        delete_option('mta_ridership_nd_error');
    }
    if (function_exists('rocket_clean_post')) {
        foreach (MTA_ND_PAGES as $slug) {
            if ($p = get_page_by_path($slug, OBJECT, 'page')) {
                rocket_clean_post($p->ID);
            }
        }
    }
}

/* ---------- small helpers ---------- */

function mta_nd_num($n, $d = 0)
{
    return number_format((float) $n, $d);
}

function mta_nd_pct($x, $d = 0)
{
    return number_format((float) $x * 100, $d) . '%';
}

/** "Sep 2026" from "2026-09". */
function mta_nd_month_name($m)
{
    return gmdate('M Y', strtotime(substr($m, 0, 7) . '-01 UTC'));
}

/** "$5.2 billion" / "$331 million" from millions of dollars. */
function mta_nd_money($m)
{
    return abs($m) >= 1000 ? '$' . number_format($m / 1000, 1) . ' billion' : '$' . number_format($m) . ' million';
}

/** Subway line order for sorting. */
function mta_nd_line_sort(array $lines)
{
    $order = '1234567ACEBDFMGJZLNQRWS';
    usort($lines, function ($a, $b) use ($order) {
        $x = strpos($order, $a);
        $y = strpos($order, $b);
        return ($x === false ? 99 : $x) <=> ($y === false ? 99 : $y);
    });
    return $lines;
}

/** The MTA's codes for the three shuttles → "S". */
function mta_nd_shuttle($l)
{
    $l = strtoupper(trim($l));
    return in_array($l, ['FS', 'GCS', 'RS', 'H'], true) ? 'S' : $l;
}

function mta_nd_split_lines($s)
{
    return array_values(array_filter(preg_split('/[,\s\/]+/', (string) $s)));
}

/** Column chart: points = [[hover label, value, axis label or '']], like mta_more_month_chart. */
function mta_nd_chart(array $points, $label, $fmt = 'mta_nd_num')
{
    if (count($points) < 2) {
        return '';
    }
    wp_enqueue_script('mta-month-chart', plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/mta-month-chart.js', [], '1.0.0', true);
    $vals = array_column($points, 1);
    $max = max(1e-9, max($vals));
    $w = 720 / count($points);
    $svg = '';
    foreach (array_values($points) as $i => [$name, $v, $axis]) {
        $hgt = round(max(2, $v / $max * 160), 1);
        $x = round($i * $w + 1, 1);
        $shown = call_user_func($fmt, $v);
        $svg .= '<rect x="' . $x . '" y="' . round(175 - $hgt, 1) . '" width="' . round(max(1, $w - 2), 1) . '" height="' . $hgt . '" rx="1" fill="#8ab4f8" data-l="' . esc_attr($name) .
            '" data-v="' . esc_attr($shown) . '"><title>' . esc_html($name . ': ' . $shown) . '</title></rect>';
        if ($axis !== '') {
            $svg .= '<text x="' . $x . '" y="208" fill="#a3adbd" font-size="24">' . esc_html($axis) . '</text>';
        }
    }
    return '<div class="mta-mchart"><svg viewBox="0 0 720 215" width="100%" role="img" aria-label="' . esc_attr($label) . '">' . $svg . '</svg></div>';
}

/** [Y-m => value] → chart points with the year under each January (every other year on long charts). */
function mta_nd_month_points(array $series)
{
    ksort($series);
    $step = count($series) > 100 ? 2 : 1;
    $pts = [];
    $i = 0;
    foreach ($series as $m => $v) {
        $axis = '';
        if (substr($m, 5, 2) === '01' && ((int) substr($m, 0, 4)) % $step === 0) {
            $axis = substr($m, 0, 4);
        } elseif ($i === 0 && substr($m, 5, 2) < '10') {
            $axis = gmdate('M', strtotime("$m-01 UTC"));
        }
        $pts[] = [mta_nd_month_name($m), $v, $axis];
        $i++;
    }
    return $pts;
}

/** Hero, sections, links to the other pages and the source line, wrapped like the other data pages. */
function mta_nd_page($key, $title, $sub, array $tiles, $body, array $links, $updated, $extra = '')
{
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.18.1');
    $a = [];
    foreach ($links as $id => $name) {
        $a[] = '<a href="https://data.ny.gov/d/' . esc_attr($id) . '" rel="noopener">' . esc_html($name) . '</a>';
    }
    $o = '<header class="mta-hero"><h1>' . esc_html($title) . '</h1><p class="mta-hero-links">' . esc_html($sub) . ' · Not affiliated with the MTA</p><div class="mta-tiles">' .
        implode('', $tiles) . '</div></header>' . $body . mta_ridership_ssr_more('nd_' . $key) .
        '<p class="mta-source mta-source-ssr">Data (data.ny.gov): ' . implode(', ', $a) . '.' . ($extra ? ' ' . $extra : '') . ' Updated ' . esc_html(gmdate('M j, Y', strtotime($updated))) .
        '.<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
}

/* ---------- subway cars: breakdowns, hot cars, fleet ---------- */

const MTA_ND_CAR_INFO = [ // car class → [name, years delivered], from the fleet inventory (hjck-ty8r)
    'R46' => ['R46', '1975–78'], 'R62' => ['R62', '1984–85'], 'R62A' => ['R62A', '1984–87'], 'R68' => ['R68', '1986–88'],
    'R68A' => ['R68A', '1988–90'], 'R142' => ['R142', '2000–03'], 'R142A' => ['R142A', '2000–05'], 'R143' => ['R143', '2001–03'],
    'R160' => ['R160', '2007–10'], 'R188-CONVERSION' => ['R188 (rebuilt R142A)', '2000–05'], 'R188-NEW' => ['R188 (new)', '2014–16'],
    'R179' => ['R179', '2018–19'], 'R211A' => ['R211A', '2023–'], 'R211T' => ['R211T (open gangway)', '2023–'],
];

function mta_nd_car_name($cc)
{
    return MTA_ND_CAR_INFO[$cc][0] ?? $cc;
}

function mta_nd_cars_fetch()
{
    $md = mta_nd_q('e2qc-xgxs', 'SELECT month, car_class, number_of_cars, number_of_failures, _12_month_average_mdbf, mdbf ORDER BY month');
    if (!$md) {
        throw new Exception('No breakdown data');
    }
    $last = max(array_map(function ($r) { return substr($r['month'], 0, 7); }, $md));
    $latest = [];
    foreach ($md as $r) {
        if (substr($r['month'], 0, 7) === $last) {
            $latest[$r['car_class']] = $r;
        }
    }
    $hot = mta_nd_q('bx99-sb4y', "SELECT month, car_number, car_class, service_lines, number_of_cars_in_service, number_of_years_in_service, number_of_work_orders WHERE month >= '2015-01-01'");
    $hot_last = max(array_map(function ($r) { return substr($r['month'], 0, 7); }, $hot));
    $y0 = mta_more_month($hot_last, 11);

    // Which lines each model runs on, from the hot car data's service lines over the last year.
    $lines_by = [];
    foreach ($hot as $r) {
        if (substr($r['month'], 0, 7) >= $y0) {
            foreach (mta_nd_split_lines($r['service_lines'] ?? '') as $ln) {
                $lines_by[str_replace('-', '', $r['car_class'])][strtoupper($ln)] = true;
            }
        }
    }
    $lines_for = function ($cc) use ($lines_by) {
        $base = explode('-', $cc)[0];
        if (strpos($cc, 'R211') === 0) {
            $base = rtrim($base, 'T');
        }
        $ls = [];
        foreach (array_keys(($lines_by[$base] ?? []) + ($lines_by[str_replace('-', '', $cc)] ?? [])) as $l) {
            $ls[mta_nd_shuttle($l)] = true;
        }
        return implode(' ', mta_nd_line_sort(array_keys($ls)));
    };
    // Breakdowns a month: the MTA's own count of failures, averaged over the last 12 months of data.
    $fails = [];
    $from = mta_more_month($last, 11);
    foreach ($md as $r) {
        if (substr($r['month'], 0, 7) >= $from) {
            $fails[$r['car_class']][0] = ($fails[$r['car_class']][0] ?? 0) + (int) ($r['number_of_failures'] ?? 0);
            $fails[$r['car_class']][1] = ($fails[$r['car_class']][1] ?? 0) + 1;
        }
    }
    $classes = [];
    foreach ($latest as $cc => $r) {
        $classes[] = ['cc' => $cc, 'mdbf' => (float) $r['_12_month_average_mdbf'], 'cars' => (int) $r['number_of_cars'], 'lines' => $lines_for($cc),
                      'per_month' => ($fails[$cc][0] ?? 0) / max(1, $fails[$cc][1] ?? 0)];
    }
    usort($classes, function ($a, $b) { return $b['per_month'] <=> $a['per_month']; });

    // Whole fleet: car-weighted, then a 12-month rolling average since single months jump around.
    $sysm = [];
    foreach ($md as $r) {
        if (!empty($r['mdbf']) && (float) $r['mdbf'] > 0) {
            $m = substr($r['month'], 0, 7);
            $sysm[$m][0] = ($sysm[$m][0] ?? 0) + (float) $r['number_of_cars'];
            $sysm[$m][1] = ($sysm[$m][1] ?? 0) + (float) $r['number_of_cars'] / (float) $r['mdbf'];
        }
    }
    $sys = [];
    foreach ($sysm as $m => $v) {
        if ($v[1] > 0 && $m >= '2019-01') {
            $sys[$m] = $v[0] / $v[1];
        }
    }
    ksort($sys);
    $ks = array_keys($sys);
    $trend = [];
    for ($i = 11; $i < count($ks); $i++) {
        $s = 0;
        for ($k = $i - 11; $k <= $i; $k++) {
            $s += $sys[$ks[$k]];
        }
        $trend[$ks[$i]] = round($s / 12);
    }

    // Hot cars.
    $by_class = [];
    $by_line = [];
    $by_car = [];
    $car_meta = [];
    $years = [];
    foreach ($hot as $r) {
        $n = (int) $r['number_of_work_orders'];
        $y = substr($r['month'], 0, 4);
        $years[$y] = ($years[$y] ?? 0) + $n;
        if (substr($r['month'], 0, 7) < $y0) {
            continue;
        }
        $c = $by_class[$r['car_class']] ?? [0, 0, 0];
        $by_class[$r['car_class']] = [$c[0] + $n, max($c[1], (int) ($r['number_of_cars_in_service'] ?? 0)), max($c[2], (int) ($r['number_of_years_in_service'] ?? 0))];
        $ls = array_unique(array_map('mta_nd_shuttle', mta_nd_split_lines($r['service_lines'] ?? '')));
        foreach ($ls as $l) {
            $by_line[$l] = ($by_line[$l] ?? 0) + $n;
        }
        $car = preg_replace('/[^0-9]/', '', $r['car_number']);
        $by_car[$car] = ($by_car[$car] ?? 0) + $n;
        $car_meta[$car] = [$r['car_class'], $r['number_of_years_in_service'] ?? '?', implode(' ', $ls)];
    }
    $cars_now = [];
    foreach ($latest as $cc => $r) {
        $k = strpos($cc, 'R188') === 0 ? explode('-', $cc)[0] : $cc;
        $cars_now[$k] = ($cars_now[$k] ?? 0) + (int) $r['number_of_cars'];
    }
    $hot_classes = [];
    foreach ($by_class as $cc => $c) {
        $cars = $cars_now[$cc] ?? $c[1];
        $hot_classes[] = ['cc' => $cc, 'orders' => $c[0], 'cars' => $cars, 'age' => $c[2], 'per100' => $c[0] / max(1, $cars) * 100];
    }
    usort($hot_classes, function ($a, $b) { return $b['per100'] <=> $a['per100']; });
    arsort($by_line);
    arsort($by_car);
    ksort($years);
    $top_cars = [];
    foreach (array_slice($by_car, 0, 10, true) as $car => $n) {
        $top_cars[] = ['car' => (string) $car, 'cc' => $car_meta[$car][0], 'age' => $car_meta[$car][1], 'lines' => $car_meta[$car][2], 'n' => $n];
    }

    // Fleet inventory.
    $fleet = [];
    foreach (mta_nd_q('hjck-ty8r', 'SELECT * LIMIT 200') as $r) {
        $fleet[] = ['asset' => $r['asset'] ?? '', 'agency' => $r['agency'] ?? '', 'type' => $r['type'] ?? '', 'total' => (int) ($r['total'] ?? 0),
                    'first' => (string) ($r['acceptance_date_of_first_cars_accepted'] ?? ''), 'last' => (string) ($r['acceptance_date_of_last_cars_accepted'] ?? ''),
                    'life' => (int) ($r['useful_life'] ?? 0)];
    }
    $rank = ['NYCT' => 0, 'LIRR' => 1];
    usort($fleet, function ($a, $b) use ($rank) {
        return [$rank[$a['agency']] ?? 2, $a['first']] <=> [$rank[$b['agency']] ?? 2, $b['first']];
    });
    return ['last' => $last, 'hot_last' => $hot_last, 'y0' => $y0, 'classes' => $classes, 'trend' => $trend, 'hot' => $hot_classes,
            'years' => $years, 'lines' => array_slice($by_line, 0, 12, true), 'top_cars' => $top_cars, 'fleet' => $fleet];
}

function mta_nd_cars_page()
{
    $d = mta_nd_live() ? get_option('mta_nd_cars') : null;
    if (!$d) {
        return '<p>Subway car data is loading. Check back in a few minutes.</p>';
    }
    $items = [];
    foreach ($d['classes'] as $c) {
        $pm = $c['per_month'] ?? 0;
        $items[] = [mta_nd_car_name($c['cc']), $pm, ($pm >= 10 ? mta_nd_num($pm) : number_format($pm, 1)) . ' a month',
                    'Built ' . (MTA_ND_CAR_INFO[$c['cc']][1] ?? '?') . ' · ' . mta_nd_num($c['cars']) . ' cars' . ($c['lines'] ? ' · runs on ' . $c['lines'] : '') .
                    ' · ' . mta_nd_num($c['mdbf']) . ' miles between breakdowns'];
    }
    $fleet_pm = array_sum(array_column($d['classes'], 'per_month'));
    $body = mta_more_sec('Which subway trains break down the most?', mta_more_bars($items),
        esc_html('Breakdowns that delayed service, a month on average, over the 12 months to ' . mta_nd_month_name($d['last']) . '. Models with more cars run more miles, ' .
        'so they have more breakdowns; the miles between breakdowns (the MTA\'s own measure) compares models fairly, and higher is better there. ' .
        'Lines come from the MTA\'s air-conditioning repair records, so a model that had no repairs may show no lines.'));
    $body .= mta_more_sec('Are subway breakdowns getting better?',
        mta_nd_chart(mta_nd_month_points($d['trend']), 'Miles between breakdowns, whole fleet', function ($v) { return mta_nd_num($v) . ' mi'; }),
        'All models together, 12-month average, weighted by how many cars each model has. Each bar is one month.');

    $range = mta_nd_month_name($d['y0']) . ' – ' . mta_nd_month_name($d['hot_last']);
    $hot_total = array_sum(array_column($d['hot'], 'orders'));
    $items = [];
    foreach ($d['hot'] as $c) {
        $items[] = [$c['cc'], $c['per100'], mta_nd_num($c['per100'], 1), mta_nd_num($c['orders']) . ' repairs · ' . mta_nd_num($c['cars']) . ' cars · about ' . $c['age'] . ' years old'];
    }
    $body .= mta_more_sec('Which subway cars have broken air conditioning most often?', mta_more_bars($items),
        esc_html('Air-conditioning repair requests ("hot car" work orders) for every 100 cars of each model (cars in service in ' . mta_nd_month_name($d['last']) . '), ' . $range . '. ' .
        mta_nd_num($hot_total) . ' repairs in all.'));
    $first_year = (string) array_key_first($d['years']);
    $partial = substr($d['hot_last'], 5, 2) !== '12';
    $pts = [];
    foreach ($d['years'] as $y => $v) {
        $y = (string) $y;
        $pts[] = [$y . ($y === substr($d['hot_last'], 0, 4) && $partial ? ' (to ' . gmdate('M', strtotime($d['hot_last'] . '-01 UTC')) . ')' : ''), $v,
                  ((int) $y % 2 === 1 || $y === $first_year) ? $y : ''];
    }
    $body .= mta_more_sec('Hot car repairs by year', mta_nd_chart($pts, 'Hot car repairs by year'),
        esc_html('All air-conditioning repair requests each year since ' . $first_year . '. ' . substr($d['hot_last'], 0, 4) . ' runs to ' . mta_nd_month_name($d['hot_last']) . '.'));
    $items = [];
    foreach ($d['lines'] as $l => $n) {
        $items[] = [$l . ' train', $n, mta_nd_num($n), ''];
    }
    $body .= mta_more_sec('Lines with the most hot car repairs', mta_more_bars($items), esc_html($range . '. A car that runs on two lines counts for both.'));
    $rows = [];
    foreach ($d['top_cars'] as $c) {
        $rows[] = [esc_html($c['car']), esc_html($c['cc']), esc_html($c['lines']), esc_html($c['age'] . ' years'), mta_nd_num($c['n'])];
    }
    $body .= mta_more_sec('The 10 cars with the most AC repairs', mta_more_table(['Car number', 'Model', 'Lines', 'Age', 'Repairs'], $rows),
        'The number painted on the car, inside and out. Same 12 months.');

    $rows = [];
    $oldest = null;
    $running = array_flip(array_map(function ($c) { return explode('-', $c['cc'])[0]; }, $d['classes']));
    $year = (int) gmdate('Y');
    foreach ($d['fleet'] as $f) {
        $due = ctype_digit($f['last']) && $f['life'] ? (int) $f['last'] + $f['life'] : null;
        $where = ['NYCT' => 'Subway / SIR', 'LIRR' => 'LIRR', 'MNR' => 'Metro-North'][$f['agency']] ?? $f['agency'];
        $rows[] = [esc_html($f['asset']), esc_html($where) . '<br><small class="mta-note">' . esc_html($f['type']) . '</small>', mta_nd_num($f['total']),
                   esc_html($f['first'] !== $f['last'] ? $f['first'] . '–' . $f['last'] : $f['first']),
                   ($due ? esc_html((string) $due) : '') . ($due && $due < $year ? ' <small class="mta-note">past its planned life</small>' : '')];
        if ($f['agency'] === 'NYCT' && isset($running[$f['asset']]) && ctype_digit($f['first']) && (!$oldest || (int) $f['first'] < $oldest[1])) {
            $oldest = [$f['asset'], (int) $f['first']];
        }
    }
    $body .= mta_more_sec('Every MTA train model and how old it is', mta_more_table(['Model', 'Where', 'Cars', 'Delivered', 'Planned retirement'], $rows),
        'From the MTA\'s 2025–2044 needs assessment (a 2023 count, so models being retired show more cars than run today). ' .
        'Planned retirement = last delivery year plus the planned life the MTA gives the model.');

    $tiles = [mta_more_tile(mta_nd_num(array_sum(array_column($d['classes'], 'cars'))), 'subway cars in service'),
              mta_more_tile(mta_nd_num($fleet_pm), 'subway car breakdowns a month'),
              mta_more_tile(mta_nd_num($hot_total), 'AC repairs in 12 months')];
    if ($oldest) {
        $tiles[] = mta_more_tile((string) $oldest[1], 'oldest subway cars still running (' . $oldest[0] . ')');
    }
    return mta_nd_page('cars', 'NYC Subway Cars: Breakdowns, Broken AC and Age', 'Every subway train model compared, from MTA open data', $tiles, $body,
        ['e2qc-xgxs' => 'Subway Mean Distance Between Failures', 'bx99-sb4y' => 'Subway Hot Car Work Orders', 'hjck-ty8r' => '20-Year Needs Assessment Railcar Fleet Inventory'], $d['updated']);
}

add_shortcode('mta_subway_cars', 'mta_nd_cars_page');

/* ---------- subway planned work ---------- */

function mta_nd_work_fetch()
{
    $max = mta_nd_q('umx2-k5hm', 'SELECT max(start_time) AS m')[0]['m'] ?? null;
    if (!$max) {
        throw new Exception('No planned work data');
    }
    $last = substr($max, 0, 7);
    $start = mta_more_month($last, 11);
    $end_ex = gmdate('Y-m-d', strtotime("$last-01 +1 month UTC"));
    $rows = mta_nd_q('umx2-k5hm', "SELECT job_category, impacted_trains, impacted_stations, impacted_boroughs WHERE start_time >= '$start-01' AND start_time < '$end_ex'");
    $monthly = [];
    foreach (mta_nd_q('umx2-k5hm', "SELECT date_trunc_ym(start_time) AS m, count(*) AS n WHERE start_time < '$end_ex' GROUP BY m ORDER BY m") as $r) {
        $monthly[substr($r['m'], 0, 7)] = (int) $r['n'];
    }
    $by_line = [];
    $by_station = [];
    $by_boro = [];
    $by_cat = [];
    $with_st = 0;
    foreach ($rows as $r) {
        $ls = array_unique(array_filter(array_map(function ($x) { return strtoupper(trim($x)); }, explode(',', $r['impacted_trains'] ?? ''))));
        foreach ($ls as $l) {
            $by_line[$l] = ($by_line[$l] ?? 0) + 1;
        }
        if (!empty($r['impacted_stations'])) {
            $with_st++;
            preg_match_all('/\s*([^,(]+(?:\([^)]*\))?)/', $r['impacted_stations'], $mm);
            foreach ($mm[1] as $s) {
                if (($s = trim($s)) !== '') {
                    $by_station[$s] = ($by_station[$s] ?? 0) + 1;
                }
            }
        }
        foreach (array_unique(array_filter(array_map('trim', explode(',', $r['impacted_boroughs'] ?? '')))) as $b) {
            $by_boro[$b] = ($by_boro[$b] ?? 0) + 1;
        }
        $cat = $r['job_category'] ?? 'Not given';
        if (strpos($cat, 'Weekend') !== false && strpos(str_replace('Weekend', '', $cat), 'Week') === false) {
            $k = 'Weekends';
        } elseif (strpos($cat, 'Weeknight') === 0) {
            $k = 'Weeknights';
        } elseif (strpos($cat, 'Weekday') === 0) {
            $k = 'Weekdays';
        } elseif (strpos($cat, '24x7') !== false) {
            $k = 'Around the clock';
        } else {
            $k = $cat !== 'Not given' ? 'Mixed' : 'Not given';
        }
        $by_cat[$k] = ($by_cat[$k] ?? 0) + 1;
    }
    $order = '1234567ACEBDFMGJZLNQRWS';
    $by_line = array_filter($by_line, function ($n, $l) use ($order) { return (strlen($l) === 1 && strpos($order, $l) !== false) || in_array($l, ['SIR', 'S'], true); }, ARRAY_FILTER_USE_BOTH);
    arsort($by_line);
    arsort($by_station);
    arsort($by_boro);
    arsort($by_cat);
    return ['start' => $start, 'last' => $last, 'n' => count($rows), 'with_st' => $with_st, 'lines' => array_slice($by_line, 0, 15, true),
            'stations' => array_slice($by_station, 0, 15, true), 'boros' => $by_boro, 'cats' => $by_cat, 'monthly' => $monthly];
}

function mta_nd_work_page()
{
    $d = mta_nd_live() ? get_option('mta_nd_work') : null;
    if (!$d) {
        return '<p>Planned work data is loading. Check back in a few minutes.</p>';
    }
    $n = max(1, $d['n']);
    $range = mta_nd_month_name($d['start']) . ' – ' . mta_nd_month_name($d['last']);
    $bars = function ($arr, $sub = null) {
        $o = [];
        foreach ($arr as $k => $v) {
            $o[] = [(string) $k, $v, mta_nd_num($v), $sub ? $sub($v) : ''];
        }
        return mta_more_bars($o);
    };
    $lines = [];
    foreach ($d['lines'] as $l => $v) {
        $lines[] = [$l . ' train', $v, mta_nd_num($v), ''];
    }
    $body = mta_more_sec('Which subway lines have the most planned work?', mta_more_bars($lines),
        esc_html('Planned work orders ("general orders") that change a line\'s service, ' . $range . '. One job can affect several lines; it counts once for each.'));
    $body .= mta_more_sec('When the work happens', $bars($d['cats'], function ($v) use ($n) { return mta_nd_pct($v / $n); }), 'How each job was scheduled.');
    $body .= mta_more_sec('Stations hit by planned work most often', $bars($d['stations']),
        esc_html('Number of work orders that changed service at the station. Same 12 months. Only ' . mta_nd_num($d['with_st']) . ' of the ' . mta_nd_num($d['n']) .
        ' jobs list the stations they affect, so these are the jobs that do.'));
    $ws = max(1, $d['with_st']);
    $body .= mta_more_sec('Planned work by borough', $bars($d['boros'], function ($v) use ($ws) { return mta_nd_pct($v / $ws) . ' of jobs that list stations'; }),
        'A job that crosses boroughs counts in each.');
    $body .= mta_more_sec('Planned work orders by month', mta_nd_chart(mta_nd_month_points($d['monthly']), 'Planned work orders by month'),
        esc_html('Every month since ' . mta_nd_month_name(array_key_first($d['monthly'])) . '. Each bar is one month.'));
    if (function_exists('mta_nt_url')) {
        $body .= '<p class="mta-note">For this week\'s planned work and delays right now, see <a href="' . esc_url(mta_nt_url('changes')) . '">subway service changes and delays</a>.</p>';
    }
    $top_line = array_key_first($d['lines']);
    $top_st = array_key_first($d['stations']);
    $tiles = [mta_more_tile(mta_nd_num($d['n']), 'planned work orders in 12 months'), mta_more_tile($top_line . ' train', 'line with the most work orders'),
              mta_more_tile(mta_nd_pct(($d['cats']['Weekends'] ?? 0) / $n), 'scheduled for weekends')];
    if ($top_st) {
        $tiles[] = mta_more_tile($top_st, 'station hit most often');
    }
    return mta_nd_page('work', 'Subway Planned Work: Which Lines and Stations Get It Most', 'Every planned subway work order, ' . $range, $tiles, $body,
        ['umx2-k5hm' => 'MTA Subway General Orders'], $d['updated']);
}

add_shortcode('mta_planned_work', 'mta_nd_work_page');

/* ---------- LIRR crowding by train ---------- */

function mta_nd_branch($b)
{
    $map = ['HUNT. HICKS.' => 'Port Jefferson (Huntington)', 'RONK. (GREENPT.)' => 'Ronkonkoma', 'CITY TERMINAL ZONE' => 'City Terminal Zone (Brooklyn, Queens)'];
    return $map[$b] ?? ucwords(strtolower((string) $b));
}

function mta_nd_station($s)
{
    return str_replace('NY Penn Station', 'Penn Station', preg_replace('/\s+Psgr$/', '', (string) $s));
}

function mta_nd_t12($h, $mi = 0)
{
    $h = (int) $h;
    return (($h % 12) ?: 12) . ':' . sprintf('%02d', (int) $mi) . ' ' . ($h < 12 ? 'am' : 'pm');
}

function mta_nd_lirr_fetch()
{
    $max = mta_nd_q('73th-g5ad', 'SELECT max(service_date) AS m')[0]['m'] ?? null;
    if (!$max) {
        throw new Exception('No LIRR occupancy data');
    }
    $d1 = substr($max, 0, 10);
    $d0 = gmdate('Y-m-01', strtotime(gmdate('Y-m-01', strtotime("$d1 UTC")) . ' -62 days UTC'));
    $w = "service_date >= '$d0' AND service_date <= '$d1' AND max_occupancy IS NOT NULL";
    $wd = 'date_extract_dow(service_date) BETWEEN 1 AND 5';
    $trains = mta_nd_q('73th-g5ad', "SELECT trip_id, trip_origin_station_name AS o, trip_destination_station AS d, branch_name AS b, date_extract_hh(scheduled_time_start) AS h, " .
        "date_extract_mm(scheduled_time_start) AS mi, avg(max_occupancy) AS occ, avg(max_passengers) AS pax, count(*) AS n " .
        "WHERE $w GROUP BY trip_id, o, d, b, h, mi HAVING n >= 20 ORDER BY occ DESC LIMIT 15");
    $branches = mta_nd_q('73th-g5ad', "SELECT branch_name AS b, avg(max_occupancy) AS occ, count(*) AS n WHERE $w AND " .
        "((date_extract_hh(scheduled_time_start) >= 6 AND date_extract_hh(scheduled_time_start) < 9) OR (date_extract_hh(scheduled_time_start) >= 16 AND date_extract_hh(scheduled_time_start) < 19)) " .
        "AND $wd GROUP BY b HAVING n >= 50 ORDER BY occ DESC");
    $hours = mta_nd_q('73th-g5ad', "SELECT date_extract_hh(scheduled_time_start) AS h, avg(max_occupancy) AS occ WHERE $w AND $wd GROUP BY h ORDER BY h");
    $models = mta_nd_q('73th-g5ad', "SELECT model, avg(max_occupancy) AS occ, avg(car_count) AS cars, count(*) AS n WHERE $w GROUP BY model HAVING n >= 100 ORDER BY occ DESC");
    $full = mta_nd_q('73th-g5ad', "SELECT count(*) AS n, sum(case(max_occupancy >= 1, 1, true, 0)) AS over WHERE $w")[0] ?? [];
    if (!$trains) {
        throw new Exception('No LIRR trains');
    }
    $num = function ($rows, $keys) {
        return array_map(function ($r) use ($keys) {
            foreach ($keys as $k) {
                $r[$k] = (float) ($r[$k] ?? 0);
            }
            return $r;
        }, $rows);
    };
    return ['from' => $d0, 'to' => $d1, 'trains' => $num($trains, ['occ', 'pax', 'h', 'mi']),
            'branches' => array_values(array_filter($num($branches, ['occ', 'n']), function ($r) { return !empty($r['b']); })),
            'hours' => $num($hours, ['h', 'occ']), 'models' => $num($models, ['occ', 'cars', 'n']), 'n' => (int) ($full['n'] ?? 0), 'over' => (int) ($full['over'] ?? 0)];
}

function mta_nd_lirr_page()
{
    $d = mta_nd_live() ? get_option('mta_nd_lirr') : null;
    if (!$d) {
        return '<p>LIRR crowding data is loading. Check back in a few minutes.</p>';
    }
    $range = gmdate('M Y', strtotime($d['from'] . ' UTC')) . ' – ' . gmdate('M Y', strtotime($d['to'] . ' UTC'));
    $rows = [];
    foreach ($d['trains'] as $r) {
        $rows[] = [esc_html(mta_nd_t12($r['h'], $r['mi'])), esc_html(mta_nd_station($r['o'] ?? '')) . ' → ' . esc_html(mta_nd_station($r['d'] ?? '')) .
                   '<br><small class="mta-note">' . esc_html(mta_nd_branch($r['b'] ?? '')) . ' branch</small>', mta_nd_pct($r['occ']), mta_nd_num($r['pax'])];
    }
    $body = mta_more_sec('The most crowded LIRR trains', mta_more_table(['Leaves', 'Train', 'Seats filled', 'Riders on board'], $rows),
        esc_html('Trains that ran at least 20 times, ' . $range . '. "Seats filled" is riders on board at the train\'s fullest point, as a share of its seats, averaged over every run. ' .
        'Over 100% means people standing.'));
    $items = [];
    foreach ($d['branches'] as $r) {
        $items[] = [mta_nd_branch($r['b']), $r['occ'], mta_nd_pct($r['occ']), mta_nd_num($r['n']) . ' trips'];
    }
    $body .= mta_more_sec('Most crowded branches at rush hour', mta_more_bars($items),
        'Weekday trains leaving 6–9 am or 4–7 pm. Average share of seats filled at each train\'s fullest point.');
    $pts = [];
    foreach ($d['hours'] as $r) {
        $l = str_replace(':00', '', mta_nd_t12($r['h']));
        $pts[] = [$l, $r['occ'], ((int) $r['h']) % 3 === 0 ? $l : ''];
    }
    $body .= mta_more_sec('How full LIRR trains get by hour', mta_nd_chart($pts, 'LIRR seats filled by hour', 'mta_nd_pct'), 'Weekdays, by the hour the train leaves its first station.');
    $items = [];
    foreach ($d['models'] as $r) {
        $items[] = [$r['model'] ?? '', $r['occ'], mta_nd_pct($r['occ']), mta_nd_num($r['n']) . ' trips · ' . number_format($r['cars'], 1) . ' cars on average'];
    }
    $body .= mta_more_sec('Crowding by train model', mta_more_bars($items), 'Average share of seats filled at each train\'s fullest point, by the model of train.');
    $top = $d['trains'][0];
    $tiles = [mta_more_tile(mta_nd_pct($top['occ']), 'seats filled on the ' . mta_nd_t12($top['h'], $top['mi']) . ' ' . mta_nd_station($top['o'] ?? '') . ' → ' . mta_nd_station($top['d'] ?? '')),
              mta_more_tile(mta_nd_num($top['pax']), 'riders on that train at its fullest'),
              mta_more_tile(mta_nd_pct($d['over'] / max(1, $d['n']), 1), 'of trips had more riders than seats'),
              mta_more_tile(mta_nd_num($d['n']), 'trips counted, ' . $range)];
    $more = function_exists('mta_more_url') ? 'For on-time rates, see <a href="' . esc_url(mta_more_url('lirr')) . '">LIRR on-time performance</a>.' : '';
    return mta_nd_page('lirr', 'LIRR Crowding: The Most Packed Trains', 'How full every LIRR train gets, ' . $range, $tiles, $body, ['73th-g5ad' => 'LIRR Trip-Level Occupancy'], $d['updated'], $more);
}

add_shortcode('mta_lirr_crowding', 'mta_nd_lirr_page');

/* ---------- buses: breakdowns, fleet age, fare evasion ---------- */

function mta_nd_buses_fetch()
{
    $md = mta_nd_q('7mt2-y7ip', 'SELECT month, borough, monthly_miles, monthly_road_call_count ORDER BY month');
    if (!$md) {
        throw new Exception('No bus breakdown data');
    }
    $last = max(array_map(function ($r) { return substr($r['month'], 0, 7); }, $md));
    $y0 = mta_more_month($last, 11);
    $boro = [];
    $sysm = [];
    foreach ($md as $r) {
        $m = substr($r['month'], 0, 7);
        $mi = (float) ($r['monthly_miles'] ?? 0);
        $rc = (int) (float) ($r['monthly_road_call_count'] ?? 0);
        $sysm[$m] = [($sysm[$m][0] ?? 0) + $mi, ($sysm[$m][1] ?? 0) + $rc];
        if ($m >= $y0) {
            $b = $r['borough'] ?? '';
            $boro[$b] = [($boro[$b][0] ?? 0) + $mi, ($boro[$b][1] ?? 0) + $rc];
        }
    }
    ksort($sysm);
    $ks = array_keys($sysm);
    $trend = [];
    for ($i = 11; $i < count($ks); $i++) {
        if ($ks[$i] < '2019-01') {
            continue;
        }
        $mi = 0;
        $rc = 0;
        for ($k = $i - 11; $k <= $i; $k++) {
            $mi += $sysm[$ks[$k]][0];
            $rc += $sysm[$ks[$k]][1];
        }
        $trend[$ks[$i]] = round($mi / max(1, $rc));
    }

    $fl = mta_nd_q('kf6s-w5a7', 'SELECT month, company, fleet_age, total_of_buses, _of_buses_over_12_years ORDER BY month');
    $fl_last = max(array_map(function ($r) { return substr($r['month'], 0, 7); }, $fl));
    $now = [];
    $age = [];
    foreach ($fl as $r) {
        $m = substr($r['month'], 0, 7);
        $age[$m] = [($age[$m][0] ?? 0) + (float) $r['fleet_age'] * (int) $r['total_of_buses'], ($age[$m][1] ?? 0) + (int) $r['total_of_buses']];
        if ($m === $fl_last) {
            $now[] = ['company' => $r['company'], 'age' => (float) $r['fleet_age'], 'buses' => (int) $r['total_of_buses'], 'old' => (int) $r['_of_buses_over_12_years']];
        }
    }
    $ages = [];
    foreach ($age as $m => $v) {
        if ($v[1]) {
            $ages[$m] = $v[0] / $v[1];
        }
    }

    $fe = mta_nd_q('uv5h-dfhp', 'SELECT time_period, trip_type, fare_evasion ORDER BY time_period');
    $tot = [];
    $types = [];
    $q_last = max(array_column($fe, 'time_period'));
    foreach ($fe as $r) {
        if ($r['trip_type'] === 'Total') {
            $tot[$r['time_period']] = (float) $r['fare_evasion'];
        } elseif ($r['time_period'] === $q_last) {
            $types[$r['trip_type']] = (float) $r['fare_evasion'];
        }
    }
    ksort($tot);
    arsort($types);
    return ['last' => $last, 'y0' => $y0, 'boro' => $boro, 'trend' => $trend, 'fl_last' => $fl_last, 'fleet' => $now, 'ages' => $ages,
            'q_last' => $q_last, 'fare' => $tot, 'fare_types' => $types];
}

function mta_nd_buses_page()
{
    $d = mta_nd_live() ? get_option('mta_nd_buses') : null;
    if (!$d) {
        return '<p>Bus data is loading. Check back in a few minutes.</p>';
    }
    $items = [];
    foreach ($d['boro'] as $b => $v) {
        $items[] = [$b, $v[1] / 12, mta_nd_num($v[1] / 12) . ' a month', mta_nd_num($v[0] / max(1, $v[1])) . ' miles between breakdowns · ' . mta_nd_num($v[0] / 1e6, 1) . ' million miles in 12 months'];
    }
    usort($items, function ($a, $b) { return $b[1] <=> $a[1]; });
    $worst = $items[0][0] ?? '';
    $body = mta_more_sec('Where buses break down the most', mta_more_bars($items),
        esc_html('Bus breakdowns that needed a road call, a month on average, by the borough of the bus\'s depot, ' . mta_nd_month_name($d['y0']) . ' – ' . mta_nd_month_name($d['last']) .
        '. Boroughs with more buses run more miles, so they have more breakdowns; the miles between breakdowns compares them fairly.'));
    $body .= mta_more_sec('Are bus breakdowns getting better?', mta_nd_chart(mta_nd_month_points($d['trend']), 'Miles between bus breakdowns', function ($v) { return mta_nd_num($v) . ' mi'; }),
        'All boroughs, 12-month average. Each bar is one month.');

    $names = ['NYCT' => 'New York City Transit', 'MTA Bus' => 'MTA Bus Company'];
    $items = [];
    foreach ($d['fleet'] as $r) {
        $items[] = [$names[$r['company']] ?? $r['company'], $r['age'], number_format($r['age'], 1) . ' years', mta_nd_num($r['buses']) . ' buses · ' . mta_nd_num($r['old']) . ' over 12 years old'];
    }
    $body .= mta_more_sec('How old are NYC buses?', mta_more_bars($items) . mta_more_h3('Average bus age, by month') .
        mta_nd_chart(mta_nd_month_points($d['ages']), 'Average bus age', function ($v) { return number_format($v, 1) . ' yrs'; }),
        esc_html('Average age of the bus fleet, ' . mta_nd_month_name($d['fl_last']) . '. The MTA plans to replace a bus after about 12 years. ' .
        'NYC Transit runs most local buses; MTA Bus Company runs routes the city took over from private companies, mostly in Queens.'));

    $pts = [];
    $i = 0;
    foreach ($d['fare'] as $q => $v) {
        $name = str_replace('-', ' ', $q);
        $pts[] = [$name, $v, substr($q, -2) === 'Q1' ? substr($q, 0, 4) : ($i === 0 && in_array(substr($q, -1), ['1', '2'], true) ? $name : '')];
        $i++;
    }
    $tn = ['Local' => 'Local buses', 'SBS' => 'Select Bus Service', 'Express' => 'Express buses'];
    $items = [];
    foreach ($d['fare_types'] as $t => $v) {
        $items[] = [$tn[$t] ?? $t, $v, mta_nd_pct($v), ''];
    }
    $ql = str_replace('-', ' ', $d['q_last']);
    $body .= mta_more_sec('How many bus riders don\'t pay?', mta_nd_chart($pts, 'Bus fare evasion by quarter', 'mta_nd_pct') . mta_more_h3('By kind of bus, ' . $ql) . mta_more_bars($items),
        'Share of bus riders who boarded without paying. Since late 2020 the MTA estimates it by comparing riders counted by sensors at the bus doors with fares paid by OMNY and ' .
        'MetroCard (before that, staff counted riders on a sample of trips). The MTA publishes only the citywide rate, not routes or boroughs. Each bar is three months.');

    $first_q = array_key_first($d['fare']);
    $tiles = [mta_more_tile(mta_nd_pct($d['fare'][$d['q_last']]), 'of bus riders didn\'t pay (' . $ql . ')'),
              mta_more_tile(mta_nd_pct($d['fare'][$first_q]), 'in ' . str_replace('-', ' ', $first_q)),
              mta_more_tile($worst, 'borough with the most bus breakdowns'),
              mta_more_tile(mta_nd_num(array_sum(array_column($d['fleet'], 'old'))), 'of ' . mta_nd_num(array_sum(array_column($d['fleet'], 'buses'))) . ' buses are over 12 years old')];
    return mta_nd_page('buses', 'NYC Buses: Breakdowns, Bus Age and Fare Evasion', 'How reliable NYC buses are, how old they are and how many riders pay', $tiles, $body,
        ['7mt2-y7ip' => 'Bus Mean Distance Between Failures', 'kf6s-w5a7' => 'Bus System Fleet Age', 'uv5h-dfhp' => 'Bus Fare Evasion'], $d['updated']);
}

add_shortcode('mta_bus_reliability', 'mta_nd_buses_page');

/* ---------- MTA money: income and spending, debt, subsidies ---------- */

function mta_nd_money_fetch()
{
    // Statement of operations: actual results, day-to-day (non-reimbursable) side, in millions.
    $rows = mta_nd_q('yg77-3tkj', "SELECT fiscal_year AS y, type, subtype, general_ledger AS g, sum(amount)/1e6 AS m WHERE scenario='Actual' AND expense_type='NREIMB' GROUP BY y, type, subtype, g");
    $full = [];
    foreach (mta_nd_q('yg77-3tkj', "SELECT fiscal_year AS y, count(distinct month) AS n WHERE scenario='Actual' GROUP BY y") as $r) {
        if ((int) $r['n'] === 12) {
            $full[] = (string) $r['y'];
        }
    }
    sort($full);
    if (!$full) {
        throw new Exception('No full year in the statement of operations');
    }
    $by = [];
    $add = function ($y, $k, $v) use (&$by) {
        $by[$y][$k] = ($by[$y][$k] ?? 0) + $v;
    };
    foreach ($rows as $r) {
        $y = (string) $r['y'];
        if (!in_array($y, $full, true)) {
            continue;
        }
        $m = (float) ($r['m'] ?? 0);
        $t = $r['type'] ?? '';
        $st = $r['subtype'] ?? '';
        $g = $r['g'] ?? '';
        if ($t === 'Total Revenue') {
            $add($y, 'rev:' . $g, $m);
            $add($y, 'rev', $m);
        } elseif (strpos($t, 'Total Expenses Before') === 0) {
            $add($y, 'cost', $m);
            $add($y, 'cost:' . $st, $m);
            if ($st === 'Labor Expenses') {
                $add($y, 'labor:' . $g, $m);
            }
        } elseif ($t === 'Debt Service Expenses') {
            $add($y, 'debt', $m);
        }
    }
    $yr = end($full);

    // Debt: the latest snapshot, by what pays the bonds back, and the total at each snapshot.
    $dates = mta_nd_q('sze3-m8qh', 'SELECT posted_date AS d GROUP BY d ORDER BY d');
    $last = $dates ? ($dates[count($dates) - 1]['d'] ?? null) : null;
    if (!$last) {
        throw new Exception('No debt data');
    }
    $bonds = mta_nd_q('sze3-m8qh', "SELECT type_of_credit AS t, total_outstanding_par AS p, tic, series_original_final_maturity AS mat WHERE posted_date = '$last'");
    $by_t = [];
    $rate_n = 0;
    $rate_d = 0;
    $lastmat = '';
    foreach ($bonds as $b) {
        $p = (float) ($b['p'] ?? 0);
        $by_t[$b['t'] ?? ''] = ($by_t[$b['t'] ?? ''] ?? 0) + $p;
        if (!empty($b['tic'])) {
            $rate_n += $p * (float) $b['tic'];
            $rate_d += $p;
        }
        if (!empty($b['mat'])) {
            $lastmat = max($lastmat, substr($b['mat'], 0, 4));
        }
    }
    arsort($by_t);
    $hist = [];
    foreach (mta_nd_q('sze3-m8qh', 'SELECT posted_date AS d, sum(total_outstanding_par) AS p GROUP BY d ORDER BY d') as $r) {
        $hist[substr($r['d'], 0, 7)] = (float) $r['p'];
    }

    // Subsidies: only the sources this dataset lists clearly (it leaves out the biggest taxes).
    $labels = ['City Subsidy for MTA Bus Company|NaN' => 'New York City, for MTA Bus Company routes',
               'Capital Lockbox Fund|Central Business District Tolling Program (CBDTP)' => 'Congestion pricing tolls (for building projects)',
               'Casino License and Gaming Tax Revenues|Casino License Fees' => 'Casino license fees',
               'Capital Lockbox Fund|Payroll Mobility Tax (PMT) for Capital Funding' => 'Payroll tax set aside for building projects',
               'Capital Lockbox Fund|Real Estate Transfer Tax' => 'Tax on expensive home sales ("mansion tax")',
               'Capital Lockbox Fund|Internet Marketplace Tax - State & City' => 'Sales tax on online shopping',
               'For-Hire Vehicle (FHV) Surcharge|Subway Action Plan Account' => 'Surcharge on taxi and Uber/Lyft rides in Manhattan',
               'PMT and MTA Aid|Payroll Mobility Tax Replacement Funds' => 'State payments replacing a cut payroll tax',
               'Automated Camera Enforcement (ACE)|NaN' => 'Bus lane camera tickets'];
    $subs = [];
    foreach (mta_nd_q('dfg9-c5rx', "SELECT general_ledger AS g, account_category AS c, sum(amount)/1e6 AS m WHERE scenario='Actual' AND expense_type='Cash' AND fiscal_year='$yr' GROUP BY g, c") as $r) {
        $k = ($r['g'] ?? '') . '|' . ($r['c'] ?? 'NaN');
        if (isset($labels[$k]) && (float) $r['m'] > 1) {
            $subs[$labels[$k]] = ($subs[$labels[$k]] ?? 0) + (float) $r['m'];
        }
    }
    arsort($subs);
    return ['year' => $yr, 'full' => $full, 'by' => $by, 'debt_date' => substr($last, 0, 10), 'debt_types' => $by_t, 'rate' => $rate_d ? $rate_n / $rate_d : 0,
            'lastmat' => $lastmat, 'debt_hist' => $hist, 'subs' => $subs];
}

function mta_nd_money_page()
{
    $d = mta_nd_live() ? get_option('mta_nd_money') : null;
    if (!$d) {
        return '<p>MTA money data is loading. Check back in a few minutes.</p>';
    }
    $yr = $d['year'];
    $x = $d['by'][$yr];
    $g = function ($k) use ($x) { return (float) ($x[$k] ?? 0); };
    $fares = $g('rev:Farebox Revenue');
    $cost = $g('cost');
    $debt_svc = $g('debt');
    $gap = $cost + $debt_svc - $g('rev');
    $body = mta_more_sec('Where the MTA\'s money came from in ' . $yr, mta_more_bars([
        ['Fares (subway, bus, LIRR, Metro-North)', $fares, mta_nd_money($fares), ''],
        ['Bridge and tunnel tolls', $g('rev:Toll Revenue'), mta_nd_money($g('rev:Toll Revenue')), ''],
        ['Other income', $g('rev:Other Revenue'), mta_nd_money($g('rev:Other Revenue')), 'advertising, rents, parking and other sources'],
        ['Gap filled by taxes and government aid', $gap, mta_nd_money($gap), 'what fares, tolls and other income didn\'t cover']]),
        esc_html('Day-to-day operations only, actual results for ' . $yr . '. Building new things (the capital program) is paid for separately.'));
    $names = ['Payroll' => 'Pay (before overtime)', 'Health and Welfare' => 'Health insurance', 'Pension' => 'Pensions', 'Overtime' => 'Overtime',
              'Other Fringe Benefits' => 'Other benefits', 'OPEB Current Payments' => 'Retiree health care'];
    $spend = [];
    foreach ($x as $k => $v) {
        if (strpos($k, 'labor:') === 0 && $v > 0) {
            $spend[] = [$names[substr($k, 6)] ?? substr($k, 6), $v, mta_nd_money($v), 'workers'];
        }
    }
    $spend[] = ['Everything else (power, fuel, parts, contracts, insurance…)', $g('cost:Non-Labor Expenses'), mta_nd_money($g('cost:Non-Labor Expenses')), ''];
    $spend[] = ['Paying off debt', $debt_svc, mta_nd_money($debt_svc), 'interest and principal on bonds'];
    usort($spend, function ($a, $b) { return $b[1] <=> $a[1]; });
    $body .= mta_more_sec('Where the money went in ' . $yr, mta_more_bars($spend),
        esc_html('Total day-to-day costs of ' . mta_nd_money($cost) . ' plus ' . mta_nd_money($debt_svc) . ' of debt payments. Workers\' pay and benefits were ' .
        mta_nd_pct($g('cost:Labor Expenses') / max(1, $cost)) . ' of day-to-day costs.'));
    $pts = [];
    $rows = [];
    foreach ($d['full'] as $y) {
        $v = $d['by'][$y] ?? [];
        $c = (float) ($v['cost'] ?? 0);
        if (!$c) {
            continue;
        }
        $f = (float) ($v['rev:Farebox Revenue'] ?? 0);
        $pts[] = [$y, $f / $c, $y];
        $rows[] = [esc_html($y), mta_nd_money($f), mta_nd_money((float) ($v['rev:Toll Revenue'] ?? 0)), mta_nd_money($c), mta_nd_money((float) ($v['debt'] ?? 0)), mta_nd_pct($f / $c)];
    }
    $body .= mta_more_sec('How much of the cost do fares cover?', mta_nd_chart($pts, 'Share of day-to-day costs covered by fares', 'mta_nd_pct') .
        mta_more_table(['Year', 'Fares', 'Tolls', 'Day-to-day costs', 'Debt payments', 'Fares cover'], $rows),
        'Fares as a share of day-to-day costs, each full year. Fares fell sharply in 2020 when ridership collapsed.');

    $total = array_sum($d['debt_types']);
    $items = [];
    foreach ($d['debt_types'] as $t => $v) {
        $items[] = [$t === 'Transportation Revenue Bonds' ? 'Backed by fares and subsidies (Transportation Revenue Bonds)' : $t, $v, mta_nd_money($v), ''];
    }
    $debt = mta_more_bars($items);
    if (count($d['debt_hist']) > 2) {
        $debt .= mta_more_h3('Total debt over time') . mta_nd_chart(mta_nd_month_points($d['debt_hist']), 'MTA debt outstanding', 'mta_nd_money');
    }
    $body .= mta_more_sec('How much does the MTA owe?', $debt,
        esc_html('Bonds still to be repaid on ' . gmdate('M j, Y', strtotime($d['debt_date'] . ' UTC')) . ', by what pays them back. Average interest rate about ' .
        number_format($d['rate'] * 100, 1) . '%; the last bonds are due in ' . $d['lastmat'] . '.'));
    $items = [];
    foreach ($d['subs'] as $k => $v) {
        $items[] = [$k, $v, mta_nd_money($v), ''];
    }
    $body .= mta_more_sec('Some of the taxes and fees that fund the MTA (' . $yr . ')', mta_more_bars($items),
        'From the MTA\'s subsidy data. <strong>This dataset leaves out the MTA\'s biggest taxes</strong> (the main payroll tax, mortgage recording taxes, ' .
        'petroleum taxes and state operating aid), so these are only some of the sources. The "gap" above shows the full amount taxes and aid had to cover.');
    $tiles = [mta_more_tile(mta_nd_money($cost + $debt_svc), 'spent running the MTA in ' . $yr), mta_more_tile(mta_nd_pct($fares / max(1, $cost)), 'of day-to-day costs paid by fares'),
              mta_more_tile(mta_nd_money($total), 'in debt still owed'), mta_more_tile(mta_nd_money($g('labor:Overtime')), 'spent on overtime in ' . $yr)];
    $more = function_exists('mta_more_url') ? 'All amounts are actual results, not budgets. For pay by job, see <a href="' . esc_url(mta_more_url('pay')) . '">MTA salaries and overtime</a>.' : '';
    return mta_nd_page('money', 'MTA Money: Where It Comes From, Where It Goes', 'The MTA\'s income, spending and debt, ' . $yr, $tiles, $body,
        ['yg77-3tkj' => 'MTA Statement of Operations', 'sze3-m8qh' => 'MTA Debt Outstanding', 'dfg9-c5rx' => 'MTA Subsidies'], $d['updated'], $more);
}

add_shortcode('mta_money', 'mta_nd_money_page');

/* ---------- search titles and descriptions ---------- */

function mta_nd_seo()
{
    if (!mta_nd_live()) {
        return null;
    }
    if (is_page(MTA_ND_PAGES['cars']) && ($d = get_option('mta_nd_cars'))) {
        return ['title' => 'NYC Subway Cars: Breakdowns, Broken AC and Age',
                'description' => 'Which NYC subway trains break down most, which cars have broken air conditioning most often, and how old every MTA train model is, from MTA open data.'];
    }
    if (is_page(MTA_ND_PAGES['work']) && ($d = get_option('mta_nd_work'))) {
        return ['title' => 'NYC Subway Planned Work: Lines and Stations Hit Most',
                'description' => number_format($d['n']) . ' planned subway work orders in 12 months: which lines and stations get the most weekend and night work, by borough and month.'];
    }
    if (is_page(MTA_ND_PAGES['lirr']) && ($d = get_option('mta_nd_lirr'))) {
        return ['title' => 'LIRR Crowding: The Most Packed Trains',
                'description' => 'The most crowded LIRR trains, branches and hours, and how often riders have to stand, from the LIRR\'s own trip-by-trip counts.'];
    }
    if (is_page(MTA_ND_PAGES['buses']) && ($d = get_option('mta_nd_buses'))) {
        return ['title' => 'NYC Bus Breakdowns, Bus Age and Fare Evasion',
                'description' => 'Where NYC buses break down most, how old the bus fleet is and how many bus riders don\'t pay (' . mta_nd_pct($d['fare'][$d['q_last']] ?? 0) . '), from MTA open data.'];
    }
    if (is_page(MTA_ND_PAGES['money']) && ($d = get_option('mta_nd_money'))) {
        return ['title' => 'MTA Budget ' . $d['year'] . ': Where the Money Comes From and Goes',
                'description' => 'How the MTA paid for itself in ' . $d['year'] . ': fares, tolls, taxes and aid, what it spent on workers and overtime, and how much debt it owes.'];
    }
    return null;
}

add_filter('wpseo_title', function ($t) { return ($s = mta_nd_seo()) ? mta_ridership_seo_title($s['title']) : $t; });
add_filter('wpseo_opengraph_title', function ($t) { return ($s = mta_nd_seo()) ? $s['title'] : $t; });
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($t) { return ($s = mta_nd_seo()) ? mta_ridership_seo_desc($s['description']) : $t; });
}
