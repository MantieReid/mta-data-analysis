<?php
/**
 * Station pages at /stations/<station>/: riders a day, rank, change vs last year, the busiest hours,
 * the lines that stop there and the nearest stations. Built only from data the ridership page already
 * stores (the "Find your station" list and the map's hourly riders), so it adds no API calls.
 * Public since 2026-10-29; each page is in Yoast's page sitemap.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_STATIONS_SLUG = 'stations';
const MTA_STATIONS_ALL  = '_all'; // query value for the ranking page at /stations/ (no station slug starts with _)
const MTA_STATIONS_HOOK   = 'mta_ridership_stations_step';
const MTA_STATIONS_OPTION = 'mta_ridership_stations';

add_action(MTA_STATIONS_HOOK, function () { mta_ridership_run_step('stations'); });

function mta_stations_schedule($delay)
{
    if (!wp_next_scheduled(MTA_STATIONS_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_STATIONS_HOOK);
    }
}

/**
 * Report card data per station complex (the same id as the ridership data), refreshed monthly after the
 * bus step: accessibility (MTA Subway Stations, 39hk-dx4f), elevator and escalator availability over the
 * last 12 months (rc78-7x78), ridership vs 2019 (Subway Station Monthly Ridership, ak4z-sape) and the
 * top destinations of trips starting there (Origin-Destination Ridership Estimate, 28vm-gjqr).
 */
function mta_stations_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }
    try {
        $api = function ($id, array $p) {
            return mta_ridership_api("https://data.ny.gov/resource/$id.json", $p + ['$limit' => 50000]);
        };
        $c = [];

        // Accessibility: each platform-level station has ada 0 (no), 1 (yes) or 2 (partly).
        foreach ($api('39hk-dx4f', ['$select' => 'complex_id, stop_name, daytime_routes, ada, ada_notes']) as $r) {
            $c[$r['complex_id']]['ada'][] = ['name' => $r['stop_name'], 'routes' => $r['daytime_routes'] ?? '', 'ada' => (int) $r['ada'],
                                             'notes' => ($r['ada_notes'] ?? 'NaN') === 'NaN' ? '' : $r['ada_notes']];
        }

        // Elevators and escalators, last 12 months.
        $max = $api('rc78-7x78', ['$select' => 'max(month) AS m']);
        $e_to = substr($max[0]['m'] ?? '', 0, 7);
        if (!$e_to) {
            throw new Exception('No elevator data returned');
        }
        $e_from = gmdate('Y-m', strtotime("$e_to-01 -11 month UTC"));
        foreach ($api('rc78-7x78', ['$select' => 'station_complex_mrn AS id, equipment_type AS t, count(distinct equipment_code) AS units, ' .
                                        'sum(_24_hour_hours_available) AS a, sum(_24_hour_total_hours) AS h, sum(unscheduled_outages) AS o, sum(entrapments) AS e',
                                    '$where' => "month >= '$e_from-01'", '$group' => 'id, t']) as $r) {
            if ((float) $r['h'] > 0) {
                $c[$r['id']]['equip'][strtolower($r['t'])] = ['units' => (int) $r['units'], 'avail' => round($r['a'] / $r['h'] * 100, 1),
                                                              'outages' => (int) $r['o'], 'entrapments' => (int) $r['e']];
            }
        }

        // Ridership: the last 12 months vs 2019, the last full year before the pandemic.
        $max = $api('ak4z-sape', ['$select' => 'max(month) AS m']);
        $m_to = substr($max[0]['m'] ?? '', 0, 7);
        $m_from = gmdate('Y-m', strtotime("$m_to-01 -11 month UTC"));
        foreach ($api('ak4z-sape', ['$select' => 'station_complex_id AS id, sum(ridership) AS r', '$where' => "month >= '2019-01-01' AND month < '2020-01-01'", '$group' => 'id']) as $r) {
            $c[$r['id']]['r2019'] = (float) $r['r'];
        }
        foreach ($api('ak4z-sape', ['$select' => 'station_complex_id AS id, sum(ridership) AS r', '$where' => "month >= '$m_from-01'", '$group' => 'id']) as $r) {
            $c[$r['id']]['r12'] = (float) $r['r'];
        }

        // Where trips starting here go: the latest month, as shares of all trips from the station.
        $max = $api('28vm-gjqr', ['$select' => 'max(timestamp) AS m']);
        $od_m = substr($max[0]['m'] ?? '', 0, 7);
        if ($od_m) {
            [$y, $mo] = array_map('intval', explode('-', $od_m));
            $where = "year = '$y' AND month = '$mo'";
            $tot = [];
            foreach ($api('28vm-gjqr', ['$select' => 'origin_station_complex_id AS o, sum(estimated_average_ridership) AS r', '$where' => $where, '$group' => 'o']) as $r) {
                $tot[$r['o']] = (float) $r['r'];
            }
            // Pairs under 100 never reach a top five, and leaving them out keeps the answer to one page.
            $od = [];
            foreach ($api('28vm-gjqr', ['$select' => 'origin_station_complex_id AS o, destination_station_complex_id AS d, sum(estimated_average_ridership) AS r',
                                        '$where' => $where, '$group' => 'o, d', '$having' => 'r > 100']) as $r) {
                if ($r['o'] !== $r['d']) {
                    $od[$r['o']][$r['d']] = (float) $r['r'];
                }
            }
            foreach ($od as $o => $ds) {
                arsort($ds);
                $c[$o]['to'] = [];
                foreach (array_slice($ds, 0, 5, true) as $d => $v) {
                    $c[$o]['to'][] = ['id' => (string) $d, 'share' => round($v / max(1, $tot[$o] ?? 0) * 100, 1)];
                }
            }
        }

        $out = [];
        foreach ($c as $id => $x) {
            $row = ['equip' => $x['equip'] ?? null, 'to' => $x['to'] ?? null,
                    'vs2019' => !empty($x['r2019']) && !empty($x['r12']) ? round($x['r12'] / $x['r2019'] * 100) : null];
            if (!empty($x['ada'])) {
                $a = array_column($x['ada'], 'ada');
                $row['ada'] = min($a) === 1 ? 'yes' : (max($a) === 0 ? 'no' : 'partly');
                $row['ada_parts'] = $x['ada'];
            }
            $out[(string) $id] = $row;
        }
        update_option(MTA_STATIONS_OPTION, [
            'updated' => gmdate('c'), 'latest' => $e_to, 'equip_from' => $e_from, 'equip_to' => $e_to,
            'months_from' => $m_from, 'months_to' => $m_to, 'od_month' => $od_m, 'stations' => $out,
        ], false);
        delete_option('mta_ridership_stations_error');
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
    } catch (Throwable $e) {
        update_option('mta_ridership_stations_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_STATIONS_HOOK, 'mta_stations_schedule');
    }
}

add_action('init', function () {
    add_rewrite_rule('^' . MTA_STATIONS_SLUG . '/([a-z0-9-]+)/?$', 'index.php?mta_station=$matches[1]', 'top');
    add_rewrite_rule('^' . MTA_STATIONS_SLUG . '/?$', 'index.php?mta_station=' . MTA_STATIONS_ALL, 'top');
    if (get_option('mta_stations_rewrite') !== '2') {
        flush_rewrite_rules(false);
        update_option('mta_stations_rewrite', '2');
    }
    if (!get_option(MTA_STATIONS_OPTION) && get_option(MTA_RIDERSHIP_EXTRAS_OPTION)) {
        mta_stations_schedule(10);
    }
});
add_filter('query_vars', function ($v) {
    $v[] = 'mta_station';
    return $v;
});

function mta_stations_url($slug = '')
{
    return home_url('/' . MTA_STATIONS_SLUG . '/' . ($slug ? $slug . '/' : ''));
}

function mta_stations_link($slug = '')
{
    return mta_stations_url($slug);
}

/** Every line served, from all the brackets in the MTA's name, e.g. "Times Sq-42 St (N,Q,R,W,S,1,2,3,7)/42 St (A,C,E)". */
function mta_stations_lines_of($name)
{
    preg_match_all('/\(([^)]*)\)/', $name, $m);
    $out = [];
    foreach ($m[1] as $grp) {
        foreach (explode(',', $grp) as $x) {
            $x = trim($x);
            if ($x !== '' && !in_array($x, $out, true)) {
                $out[] = $x;
            }
        }
    }
    return $out;
}

/** The display name without the line brackets: "Times Sq-42 St/42 St". */
function mta_stations_display($name)
{
    return trim(preg_replace('/\s*\([^)]*\)/', '', $name));
}

/** All stations with a slug each (station id => row), merged with the map's hours and location. */
function mta_stations_all()
{
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    $all = [];
    if (empty($x['lookup'])) {
        return $all;
    }
    $map = [];
    foreach ($x['stations'] ?? [] as $s) {
        $map[$s['id']] = $s;
    }
    $used = [];
    foreach ($x['lookup'] as $row) {
        $slug = sanitize_title(mta_stations_display($row['name']));
        if (isset($used[$slug])) { // e.g. three different "Canal St" stations: add the lines
            $slug .= '-' . sanitize_title(implode('-', mta_stations_lines_of($row['name'])));
        }
        if (isset($used[$slug])) {
            $slug .= '-' . $row['id'];
        }
        $used[$slug] = true;
        $m = $map[$row['id']] ?? [];
        $all[$row['id']] = $row + ['slug' => $slug, 'hours' => $m['hours'] ?? null, 'lat' => $m['lat'] ?? null, 'lon' => $m['lon'] ?? null];
    }
    return $all;
}

function mta_stations_current()
{
    $slug = get_query_var('mta_station');
    if (!$slug) {
        return null;
    }
    foreach (mta_stations_all() as $s) {
        if ($s['slug'] === $slug) {
            return $s;
        }
    }
    return null;
}

function mta_stations_nearest($st, $n)
{
    if ($st['lat'] === null) {
        return [];
    }
    $d = [];
    foreach (mta_stations_all() as $id => $s) {
        if ((string) $id !== $st['id'] && $s['lat'] !== null) { // numeric ids come back as int array keys
            $dy = ($s['lat'] - $st['lat']) * 111.0;
            $dx = ($s['lon'] - $st['lon']) * 84.0; // km per degree of longitude in NYC
            $d[$id] = sqrt($dx * $dx + $dy * $dy);
        }
    }
    asort($d);
    $all = mta_stations_all();
    $out = [];
    foreach (array_slice($d, 0, $n, true) as $id => $km) {
        $out[] = $all[$id] + ['km' => $km];
    }
    return $out;
}

function mta_stations_answers($st, $total, $x)
{
    $name = mta_stations_display($st['name']);
    $range = mta_ridership_nice_date($x['from']) . ' – ' . mta_ridership_nice_date($x['to']);
    $a = ['riders' => 'About ' . number_format($st['avg']) . ' riders a day enter ' . $name . ' (' . $range . '). It is the #' . $st['rank'] .
        ' busiest of ' . $total . ' subway stations.'];
    if ($st['hours']) {
        $h = $st['hours'];
        $peak = array_search(max($h), $h);
        $quiet = array_search(min($h), $h);
        $a['busy'] = 'The busiest hour is ' . mta_ridership_hour_name($peak) . ', with about ' . number_format($h[$peak]) . ' riders entering on an average day. ' .
            'The quietest hour is ' . mta_ridership_hour_name($quiet) . ' (about ' . number_format($h[$quiet]) . ').';
    }
    if ($st['yoy'] !== null && !empty($x['yoy'])) {
        $a['trend'] = 'Ridership at ' . $name . ' is ' . ($st['yoy'] >= 0 ? 'up ' : 'down ') . abs($st['yoy']) . '% in ' . $x['yoy']['year'] .
            ' so far compared with the same days of ' . $x['yoy']['prev_year'] . ' (whole subway: ' . ($x['yoy']['total_pct'] >= 0 ? '+' : '') . $x['yoy']['total_pct'] . '%).';
    }
    $lines = mta_stations_lines_of($st['name']);
    if ($lines) {
        $a['lines'] = $name . ' is served by the ' . implode(', ', $lines) . (count($lines) > 1 ? ' trains.' : ' train.');
    }
    return $a;
}

/** Questions and answers shown on a station page and sent to search engines as FAQ data. */
function mta_stations_faq($st)
{
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    $a = mta_stations_answers($st, count($x['lookup']), $x);
    $name = mta_stations_display($st['name']);
    $q = ['riders' => "How many people use $name station?", 'busy' => "When is $name station busiest?",
          'trend' => "Is $name getting busier?", 'lines' => "Which trains stop at $name?"];
    $out = [];
    foreach ($q as $k => $question) {
        if (isset($a[$k])) {
            $out[] = [$question, $a[$k]];
        }
    }
    return $out;
}

// These pages have no WordPress page behind them: render them here. An unknown station is a 404.
add_action('template_redirect', function () {
    if (!get_query_var('mta_station')) {
        return;
    }
    $ranking = get_query_var('mta_station') === MTA_STATIONS_ALL;
    $st = $ranking ? null : mta_stations_current();
    if (!$st && !($ranking && mta_stations_all())) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        return;
    }
    status_header(200);
    $total = count(mta_stations_all());
    $name = $st ? mta_stations_display($st['name']) : '';
    $seo_title = $st ? "$name Station Ridership: Riders a Day & Busiest Hours" : "Busiest NYC Subway Stations: All $total Stations Ranked";
    $desc = $st ? "$name station ($st[borough]): about " . number_format($st['avg']) . " riders a day, #$st[rank] of $total NYC subway stations. " .
                  'Busiest hours, accessibility, elevator and escalator reliability, and where riders go. From MTA open data.'
                : "All $total NYC subway stations ranked by riders a day, with search, a borough filter and the fastest-growing stations. From MTA open data.";
    $url = mta_stations_url($st['slug'] ?? '');
    $title = function () use ($seo_title) { return $seo_title . ' - ' . get_bloginfo('name'); };
    add_filter('pre_get_document_title', $title, 99);
    add_filter('wpseo_title', $title, 99); // Yoast sets the title on its own
    add_filter('wpseo_opengraph_title', function () use ($seo_title) { return $seo_title; }, 99);
    foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
        add_filter($f, function () use ($desc) { return $desc; }, 99);
    }
    foreach (['wpseo_canonical', 'wpseo_opengraph_url'] as $f) {
        add_filter($f, function () use ($url) { return $url; }, 99); // otherwise Yoast points these at the home page
    }
    add_filter('wpseo_robots', function () { return 'index, follow'; }, 99);
    if ($st) {
        add_action('wp_head', function () use ($st) {
            echo '<script type="application/ld+json">' . wp_json_encode([
                '@context' => 'https://schema.org', '@type' => 'FAQPage',
                'mainEntity' => array_map(function ($i) {
                    return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
                }, mta_stations_faq($st)),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
        });
    }
    add_filter('body_class', function ($c) { $c[] = 'page'; return $c; });
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.14.2');
    get_header();
    echo '<main id="primary" class="site-main"><article class="page type-page"><div class="entry-content">' . ($st ? mta_stations_render($st) : mta_stations_render_ranking()) . '</div></article></main>';
    get_footer();
    exit;
});

function mta_stations_render($st)
{
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    $total = count($x['lookup']);
    $name = mta_stations_display($st['name']);
    $a = mta_stations_answers($st, $total, $x);
    $range = esc_html(mta_ridership_nice_date($x['from']) . ' – ' . mta_ridership_nice_date($x['to']));
    $tile = function ($big, $small) {
        return '<div class="mta-tile"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>';
    };
    $sec = function ($title, $body, $note = '') {
        return '<section class="mta-section"><h3>' . esc_html($title) . '</h3>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
    };
    $lines = mta_stations_lines_of($st['name']);
    $known = function_exists('mta_lines_list') ? mta_lines_list() : [];
    $line_url = function ($code) use ($known) {
        $k = in_array($code, ['J', 'Z'], true) ? 'JZ' : ($code === 'S' ? null : $code);
        return $k && isset($known[$k]) ? mta_lines_url($known[$k][0]) : null;
    };

    $o = '';
    $o .= '<header class="mta-hero"><h1>' . esc_html($name) . ' Station: Ridership &amp; Busiest Hours</h1>' .
        '<p class="mta-hero-links">' . esc_html($st['borough']) . ($lines ? ' · ' . esc_html(implode(' ', $lines)) . ' trains' : '') .
        ' · From MTA open data, ' . $range . ' · Not affiliated with the MTA</p><div class="mta-tiles mta-tiles-find">' .
        $tile(number_format($st['avg']), 'riders a day') .
        $tile('#' . $st['rank'] . ' of ' . $total, 'busiest stations') .
        ($st['yoy'] !== null ? $tile(($st['yoy'] >= 0 ? '+' : '') . $st['yoy'] . '%', 'vs last year so far') : '') .
        ($st['hours'] ? $tile(mta_ridership_hour_name(array_search(max($st['hours']), $st['hours'])), 'busiest hour') : '') .
        '</div></header>';

    $o .= $sec('How busy is ' . $name . '?', '<p>' . esc_html($a['riders']) . (isset($a['trend']) ? ' ' . esc_html($a['trend']) : '') . '</p>');

    if ($st['hours']) {
        $max = max(1, max($st['hours']));
        $bars = '';
        foreach ($st['hours'] as $h => $v) {
            $bars .= '<li><div class="mta-top-row"><span class="mta-top-name">' . esc_html(mta_ridership_hour_name($h)) . '</span><span class="mta-top-num">' . number_format($v) .
                '</span></div><div class="mta-bar"><span style="width:' . round($v / $max * 100, 1) . '%"></span></div></li>';
        }
        $o .= $sec('When is it busiest?', '<p>' . esc_html($a['busy']) . '</p><ul class="mta-top" style="list-style:none;padding-left:0">' . $bars . '</ul>',
            'Average riders entering per hour, last ' . (int) $x['map_days'] . ' days (from ' . esc_html(mta_ridership_nice_date($x['map_from'])) . ').');
    }

    $o .= mta_stations_report_card($st, $sec);

    if ($lines) {
        // Each line's on-time rate from its own report card: the MTA measures delays per line, not per station.
        $ld = get_option(MTA_LINES_OPTION);
        $otp = [];
        foreach ($ld['lines'] ?? [] as $code => $l) {
            $otp[$code] = $l['otp'];
        }
        $pills = '<p class="mta-find-picks">';
        foreach ($lines as $c) {
            $u = $line_url($c);
            $k = in_array($c, ['J', 'Z'], true) ? 'JZ' : $c;
            $pills .= $u ? '<a class="mta-find-pick" href="' . esc_url($u) . '">' . esc_html($c) . ' train' . (isset($otp[$k]) ? ' · ' . esc_html($otp[$k]) . '% on time' : '') . '</a> '
                         : '<span class="mta-find-pick">' . esc_html($c) . '</span> ';
        }
        $o .= $sec('Lines at this station', '<p>' . esc_html($a['lines']) . '</p>' . $pills,
            isset($ld['from']) ? 'On time = share of each line\'s trips reaching the last stop within 5 minutes of schedule (MTA terminal on-time performance), ' .
                esc_html(mta_lines_month($ld['from']) . ' – ' . mta_lines_month($ld['latest'])) . '. The MTA measures this per line, not per station.' : '');
    }

    $near = mta_stations_nearest($st, 5);
    if ($near) {
        $tr = '';
        foreach ($near as $n) {
            $tr .= '<tr><td><a href="' . esc_url(mta_stations_link($n['slug'])) . '">' . esc_html(mta_stations_display($n['name'])) .
                '</a></td><td>' . esc_html(implode(' ', mta_stations_lines_of($n['name']))) . '</td><td>' . number_format($n['avg']) . '</td><td>' .
                esc_html(number_format($n['km'] * 0.621, 1)) . ' mi</td></tr>';
        }
        $o .= $sec('Nearby stations', '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th>Station</th><th>Lines</th><th>Riders a day</th><th>Distance</th></tr></thead><tbody>' .
            $tr . '</tbody></table></div>', 'Straight-line distance.');
    }

    $faq = '';
    foreach (mta_stations_faq($st) as [$question, $answer]) {
        $faq .= '<details><summary>' . esc_html($question) . '</summary><p>' . esc_html($answer) . '</p></details>';
    }
    $o .= '<section class="mta-section mta-faq"><h3>Questions</h3>' . $faq . '</section>';
    $o .= '<p class="mta-source mta-source-ssr">Data: <a href="https://data.ny.gov/d/5wq4-mkjj" rel="noopener">MTA Subway Hourly Ridership</a> (data.ny.gov). ' .
        'Riders are entries at the turnstiles and OMNY readers, so transfers inside the station are not counted. ' .
        'Updated ' . esc_html(gmdate('M j, Y', strtotime($x['updated']))) . '. <a href="' . esc_url(mta_stations_link()) . '">All stations ranked &rarr;</a> · <a href="' . esc_url(home_url('/mta-ridership/')) . '">All subway ridership &rarr;</a><br>' .
        'Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership mta-stations">' . $o . mta_ridership_disclaimer() . '</div>';
}

/** /stations/: every station ranked by riders a day, with a name search, borough filter and the biggest movers. */
function mta_stations_render_ranking()
{
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    $all = array_values(mta_stations_all());
    $total = count($all);
    $range = esc_html(mta_ridership_nice_date($x['from']) . ' – ' . mta_ridership_nice_date($x['to']));
    $sec = function ($title, $body, $note = '') {
        return '<section class="mta-section"><h3>' . esc_html($title) . '</h3>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
    };
    $tile = function ($big, $small) {
        return '<div class="mta-tile"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>';
    };
    $link = function ($s) {
        return '<a href="' . esc_url(mta_stations_link($s['slug'])) . '">' . esc_html(mta_stations_display($s['name'])) . '</a>';
    };
    $pct = function ($v) {
        return $v === null ? '–' : ($v > 0 ? '+' : '') . $v . '%';
    };
    $boroughs = array_values(array_unique(array_column($all, 'borough')));
    sort($boroughs);
    $top = $all[0];

    $o = '';
    $o .= '<header class="mta-hero"><h1>Busiest NYC Subway Stations: All ' . $total . ' Ranked</h1>' .
        '<p class="mta-hero-links">Average riders a day from MTA open data, ' . $range . ' · Not affiliated with the MTA</p><div class="mta-tiles">' .
        $tile(number_format($top['avg']), 'riders a day at the busiest, ' . mta_stations_display($top['name'])) .
        $tile(number_format(array_sum(array_column($all, 'avg'))), 'riders a day, all stations') .
        $tile((string) $total, 'stations') . '</div></header>';

    // Biggest movers: this year so far vs the same days last year, stations with 2,000+ riders a day.
    $movers = array_values(array_filter($all, function ($s) { return $s['yoy'] !== null && $s['avg'] >= 2000; }));
    usort($movers, function ($a, $b) { return $b['yoy'] <=> $a['yoy']; });
    if (count($movers) >= 10) {
        $list = function ($rows) use ($link, $pct) {
            $out = '<ol class="mta-top">';
            foreach ($rows as $s) {
                $out .= '<li><div class="mta-top-row"><span class="mta-top-name">' . $link($s) . ' <span class="mta-lines">' .
                    esc_html(implode(' ', mta_stations_lines_of($s['name']))) . '</span></span><span class="mta-top-num">' . $pct($s['yoy']) . '</span></div></li>';
            }
            return $out . '</ol>';
        };
        $yr = $x['yoy']['year'] ?? '';
        $o .= $sec('Growing fastest', $list(array_slice($movers, 0, 5)), esc_html("$yr so far vs the same days last year, stations with 2,000+ riders a day."));
        $o .= $sec('Shrinking fastest', $list(array_slice(array_reverse($movers), 0, 5)));
    }

    $opts = '<option value="">All boroughs</option>';
    foreach ($boroughs as $b) {
        $opts .= '<option>' . esc_html($b) . '</option>';
    }
    $tr = '';
    foreach ($all as $s) {
        $tr .= '<tr data-b="' . esc_attr($s['borough']) . '" data-n="' . esc_attr(strtolower($s['name'])) . '"><td>' . (int) $s['rank'] . '</td><td>' . $link($s) .
            '</td><td>' . esc_html(implode(' ', mta_stations_lines_of($s['name']))) . '</td><td>' . esc_html($s['borough']) . '</td><td>' . number_format($s['avg']) .
            '</td><td class="' . ($s['yoy'] !== null && $s['yoy'] < 0 ? 'neg' : 'pos') . '">' . $pct($s['yoy']) . '</td></tr>';
    }
    $o .= $sec('Every station ranked',
        '<p><input type="search" id="mta-st-q" placeholder="Search a station" aria-label="Search a station" style="max-width:16em"> ' .
        '<select id="mta-st-b" aria-label="Borough">' . $opts . '</select> <span id="mta-st-n" class="mta-note"></span></p>' .
        '<div class="mta-table-wrap"><table class="mta-table" id="mta-st-t"><thead><tr><th>#</th><th>Station</th><th>Lines</th><th>Borough</th><th>Riders a day</th><th>vs last year</th></tr></thead><tbody>' .
        $tr . '</tbody></table></div>' .
        '<script>(function(){var q=document.getElementById("mta-st-q"),b=document.getElementById("mta-st-b"),n=document.getElementById("mta-st-n"),' .
        'r=document.querySelectorAll("#mta-st-t tbody tr");function f(){var t=q.value.trim().toLowerCase(),v=b.value,c=0;r.forEach(function(x){' .
        'var ok=(!v||x.dataset.b===v)&&(!t||x.dataset.n.indexOf(t)>-1);x.hidden=!ok;c+=ok?1:0;});n.textContent=c+" stations";}q.oninput=b.onchange=f;f();})();</script>',
        $range . '. Riders = entries at the turnstiles and OMNY readers. "vs last year" compares this year so far with the same days last year (stations with 100+ riders a day then).');

    $o .= mta_ridership_ssr_more('stations');
    $o .= '<p class="mta-source mta-source-ssr">Data: <a href="https://data.ny.gov/d/5wq4-mkjj" rel="noopener">MTA Subway Hourly Ridership</a> (data.ny.gov). ' .
        'Updated ' . esc_html(gmdate('M j, Y', strtotime($x['updated']))) . '. <a href="' . esc_url(home_url('/mta-ridership/')) . '">All subway ridership &rarr;</a><br>' .
        'Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership mta-stations">' . $o . mta_ridership_disclaimer() . '</div>';
}

/** Accessibility, elevators and escalators, ridership vs 2019 and where trips go. Empty until the monthly step has run. */
function mta_stations_report_card($st, $sec)
{
    $d = get_option(MTA_STATIONS_OPTION);
    $r = $d['stations'][$st['id']] ?? null;
    if (!$r) {
        return '';
    }
    $all = mta_stations_all();
    $month = function ($ym) { return gmdate('F Y', strtotime("$ym-01 UTC")); };
    $rows = [];
    if (isset($r['ada'])) {
        $label = ['yes' => 'Yes', 'partly' => 'Partly', 'no' => 'No'][$r['ada']];
        $notes = array_filter(array_map(function ($p) { return $p['notes'] ? $p['name'] . ' (' . $p['routes'] . '): ' . $p['notes'] : ''; }, $r['ada_parts']));
        $rows[] = ['Accessible (ADA)', $label . ($notes ? ' · ' . implode('; ', $notes) : '')];
    }
    // Every station's equipment, weighted by units, for a citywide figure to compare with.
    $city = [];
    foreach ($d['stations'] as $s) {
        foreach ((array) ($s['equip'] ?? []) as $t => $e) {
            $city[$t][0] = ($city[$t][0] ?? 0) + $e['avail'] * $e['units'];
            $city[$t][1] = ($city[$t][1] ?? 0) + $e['units'];
        }
    }
    foreach (['elevator' => 'Elevators', 'escalator' => 'Escalators'] as $t => $label) {
        $e = $r['equip'][$t] ?? null;
        if ($e) {
            $rows[] = [$label . ' working (' . $e['units'] . ')', $e['avail'] . '% of the time · all stations: ' . round($city[$t][0] / max(1, $city[$t][1]), 1) . '%'];
            $rows[] = [$label . ': unplanned outages', number_format($e['outages']) . ($t === 'elevator' ? ' · entrapments: ' . number_format($e['entrapments']) : '')];
        }
    }
    if ($r['vs2019'] !== null) {
        $rows[] = ['Riders vs 2019', $r['vs2019'] . '% of the 2019 level (' . $month($d['months_from']) . ' – ' . $month($d['months_to']) . ')'];
    }
    $tr = '';
    foreach ($rows as [$k, $v]) {
        $tr .= '<tr><td>' . esc_html($k) . '</td><td>' . esc_html($v) . '</td></tr>';
    }
    $o = $sec('Station report card', '<div class="mta-table-wrap"><table class="mta-table"><tbody>' . $tr . '</tbody></table></div>',
        'Elevators and escalators: share of hours in service over 24 hours, ' . esc_html($month($d['equip_from']) . ' – ' . $month($d['equip_to'])) .
        ' (MTA availability). Entrapments = times riders were stuck inside an elevator. Riders vs 2019 compares the last 12 months with 2019, the last full year before the pandemic.');

    if (!empty($r['to'])) {
        $li = '';
        foreach ($r['to'] as $t) {
            $s = $all[$t['id']] ?? null;
            if ($s) {
                $li .= '<li><div class="mta-top-row"><span class="mta-top-name"><a href="' . esc_url(mta_stations_link($s['slug'])) . '">' . esc_html(mta_stations_display($s['name'])) .
                    '</a> <span class="mta-lines">' . esc_html(implode(' ', mta_stations_lines_of($s['name']))) . '</span></span><span class="mta-top-num">' . $t['share'] . '%</span></div></li>';
            }
        }
        $o .= $sec('Where do riders from here go?', '<ol class="mta-top">' . $li . '</ol>',
            'Share of subway trips starting here that end at each station, ' . esc_html($month($d['od_month'])) . ' (MTA origin-destination ridership estimate).');
    }
    return $o;
}

// List the ranking page and every station in Yoast's page sitemap.
add_filter('wpseo_sitemap_page_content', function ($xml) {
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    if (empty($x['updated'])) {
        return $xml;
    }
    $mod = esc_html(gmdate('c', strtotime($x['updated'])));
    $xml .= '<url><loc>' . esc_url(mta_stations_url()) . "</loc><lastmod>$mod</lastmod></url>\n";
    foreach (mta_stations_all() as $s) {
        $xml .= '<url><loc>' . esc_url(mta_stations_url($s['slug'])) . "</loc><lastmod>$mod</lastmod></url>\n";
    }
    return $xml;
});
