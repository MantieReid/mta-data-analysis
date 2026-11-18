<?php
/**
 * NYC transit beyond the MTA, plus fares and tolls, fetched once a month after the "tm" step:
 *  - a Staten Island Ferry page: riders a day, by month, day of the week and year, and against 2019 (NYC DOT data);
 *  - an NYC Ferry page: boardings by route, stop, month and hour (NYC Economic Development Corporation data);
 *  - a PATH train page: rides a month, against the other systems around New York, stations and fares;
 *  - an OMNY page: how fast tap-to-pay replaced the MetroCard on subways and buses, who pays which fare, Fair Fares
 *    sign-ups, and the fare cap;
 *  - a bridge and tunnel tolls page: what each MTA crossing costs, with its traffic.
 * Prices (fares, tolls, the fare cap) aren't in any open data, so they are written here from the official pages, with
 * the date they took effect; update MTA_NT_PRICES_CHECKED and the numbers when they change.
 * Each part is stored in its own option and kept from last month if its fetch fails.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_NT_HOOK = 'mta_ridership_nt_step';
const MTA_NT_PAGES = ['sif' => 'staten-island-ferry', 'ferry' => 'nyc-ferry', 'path' => 'path-train', 'omny' => 'omny', 'tolls' => 'nyc-bridge-tolls',
                      'changes' => 'subway-service-changes', 'projects' => 'mta-construction-projects', 'fares' => 'lirr-metro-north-fares'];
const MTA_NT_PRICES_CHECKED = '2026-11-03'; // the day the prices below were read from mta.info, omny.info and panynj.gov

add_action(MTA_NT_HOOK, function () { mta_ridership_run_step('nt'); });

add_filter('mta_ridership_steps', function ($steps) {
    $steps['nt'] = ['label' => 'Ferries, PATH, OMNY and tolls', 'fn' => 'mta_nt_step', 'hook' => MTA_NT_HOOK, 'err' => 'mta_ridership_nt_error',
                    'through' => function () { $o = get_option('mta_nt_sif'); return $o['latest'] ?? null; }, 'public' => false,
                    'max_age' => (int) (((array) get_option('mta_ridership_max_age', []))['nt'] ?? 35)];
    return $steps;
});

// Runs after the "tm" step each month, and once by itself the first time.
add_action('mta_ridership_step_done', function ($key, $ok) {
    if ($key === 'tm' && $ok) {
        mta_nt_schedule(600);
    }
}, 10, 2);
add_action('init', function () {
    if (!get_option('mta_nt_sif') && !get_option('mta_ridership_nt_error')) {
        mta_nt_schedule(120);
    }
});

/** Live since 2026-11-04; return mta_ridership_preview() to put the pages back in review. */
function mta_nt_live()
{
    return true;
}

function mta_nt_url($key)
{
    return home_url('/' . MTA_NT_PAGES[$key] . '/');
}

function mta_nt_schedule($delay)
{
    if (!wp_next_scheduled(MTA_NT_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_NT_HOOK);
    }
}

/** The pages are a 404 and out of the sitemap until they go live, and noindex while in preview. */
add_action('template_redirect', function () {
    foreach (MTA_NT_PAGES as $slug) {
        if (is_page($slug) && !mta_nt_live()) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            return;
        }
    }
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    if (mta_nt_live() && !mta_ridership_preview()) {
        return $ids;
    }
    foreach (MTA_NT_PAGES as $slug) {
        if ($p = get_page_by_path($slug, OBJECT, 'page')) {
            $ids[] = $p->ID;
        }
    }
    return $ids;
});
add_filter('wp_robots', function ($r) {
    foreach (MTA_NT_PAGES as $slug) {
        if (is_page($slug) && mta_ridership_preview()) {
            return ['noindex' => true, 'nofollow' => true] + $r;
        }
    }
    return $r;
});

function mta_nt_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(900);
    }
    $parts = ['mta_nt_sif' => 'mta_nt_sif_fetch', 'mta_nt_ferry' => 'mta_nt_ferry_fetch', 'mta_nt_regional' => 'mta_nt_regional_fetch', 'mta_nt_omny' => 'mta_nt_omny_fetch',
              'mta_nt_alerts' => 'mta_nt_alerts_fetch', 'mta_nt_projects' => 'mta_nt_projects_fetch'];
    $errors = [];
    $changed = false;
    foreach ($parts as $opt => $fn) {
        try {
            $x = $fn();
            if ($x && mta_ridership_save_changed($opt, $x)) {
                $changed = true;
            }
        } catch (Throwable $e) {
            $errors[] = "$opt: " . $e->getMessage();
        }
    }
    if ($errors) {
        update_option('mta_ridership_nt_error', gmdate('c') . ' ' . implode(' · ', $errors), false);
        mta_ridership_retry_later(MTA_NT_HOOK, 'mta_nt_schedule');
    } else {
        delete_option('mta_ridership_nt_error');
    }
    if ($changed && mta_nt_live() && !mta_ridership_preview()) { // only its own pages, and only when the data changed
        mta_ridership_clean_urls(array_map('mta_nt_url', array_keys(MTA_NT_PAGES)));
    }
}

/* ---------- fetching ---------- */

/** NYC Open Data (data.cityofnewyork.us), same as mta_more_api() for data.ny.gov. */
function mta_nt_nyc($id, array $p)
{
    return mta_ridership_api("https://data.cityofnewyork.us/resource/$id.json", $p + ['$limit' => 50000]);
}

/** Staten Island Ferry riders each day, both terminals, since 2019. */
function mta_nt_sif_fetch()
{
    $days = [];
    foreach (mta_nt_nyc('6eng-46dm', ['$select' => 'date, whitehall_terminal AS w, stgeorge_terminal AS s', '$where' => 'whitehall_terminal IS NOT NULL', '$order' => 'date']) as $r) {
        $days[substr($r['date'], 0, 10)] = [(int) $r['w'], (int) ($r['s'] ?? 0)];
    }
    if (count($days) < 400) {
        throw new Exception('Too few ferry days');
    }
    return ['days' => $days, 'latest' => array_key_last($days)];
}

/** The NYC Ferry's stop names change spelling over the years; one name per stop. */
function mta_nt_ferry_stop($s)
{
    $s = trim($s);
    $map = ['dumbo' => 'Dumbo/Fulton Ferry', 'dumbo/fulton ferry' => 'Dumbo/Fulton Ferry', 'midtown west 39th st-pier 79' => 'Midtown West/W 39th St–Pier 79',
            'midtown west/w 39th st-pier 79' => 'Midtown West/W 39th St–Pier 79'];
    return $map[strtolower($s)] ?? $s;
}

/** NYC Ferry boardings: every month since 2017, and by route, stop and hour for the last 12 full months. */
function mta_nt_ferry_fetch()
{
    $months = [];
    foreach (mta_nt_nyc('t5n6-gx8c', ['$select' => 'date_trunc_ym(date) AS m, sum(boardings) AS n, count(distinct date) AS d', '$group' => 'm', '$order' => 'm']) as $r) {
        $months[mta_more_month($r['m'])] = ['n' => (int) $r['n'], 'days' => (int) $r['d']];
    }
    if (!$months) {
        throw new Exception('No ferry months');
    }
    $latest = array_key_last($months);
    if ($months[$latest]['days'] < (int) gmdate('t', strtotime("$latest-01 UTC"))) { // part-way through: use the month before
        $latest = mta_more_month("$latest-01", 1);
    }
    $from = mta_more_month("$latest-01", 11);
    $w = "date >= '$from-01' AND date < '" . mta_tm_next($latest) . "-01'";
    $x = ['months' => $months, 'latest' => $latest, 'from' => $from, 'routes' => [], 'stops' => [], 'route_stops' => [], 'hours' => []];
    foreach (mta_nt_nyc('t5n6-gx8c', ['$select' => 'route, stop, sum(boardings) AS n', '$where' => $w, '$group' => 'route, stop']) as $r) {
        $stop = mta_nt_ferry_stop($r['stop'] ?? '');
        if ($stop === '' || $stop === '(blank)') {
            continue;
        }
        $n = (int) $r['n'];
        $x['routes'][$r['route']] = ($x['routes'][$r['route']] ?? 0) + $n;
        $x['stops'][$stop] = ($x['stops'][$stop] ?? 0) + $n;
        $x['route_stops'][$r['route']][$stop] = ($x['route_stops'][$r['route']][$stop] ?? 0) + $n;
    }
    arsort($x['routes']);
    arsort($x['stops']);
    foreach (mta_nt_nyc('t5n6-gx8c', ['$select' => 'typeday, hour, sum(boardings) AS n, count(distinct date) AS d', '$where' => $w, '$group' => 'typeday, hour']) as $r) {
        $x['hours'][$r['typeday']][(int) $r['hour']] = ['n' => (int) $r['n'], 'days' => (int) $r['d']];
    }
    return $x;
}

/** Rides a month on every system in the New York area's shared ridership data (PATH, the ferries, NJ Transit...). */
function mta_nt_regional_fetch()
{
    $x = ['months' => []];
    foreach (mta_more_api('sv2g-g4mz', ['$where' => 'ridership > 0', '$order' => 'month']) as $r) {
        $x['months'][$r['mode']][mta_more_month($r['month'])] = (int) $r['ridership'];
    }
    if (empty($x['months']['PATH'])) {
        throw new Exception('No PATH ridership');
    }
    $x['latest'] = array_key_last($x['months']['PATH']);
    return $x;
}

/**
 * OMNY: subway and bus rides by payment method each month, and Fair Fares sign-ups. Subway months since 2025 come from
 * the ridership step (extras.php). Older months never change and adding up the hourly tables takes the API over 9
 * minutes, so they ship with the plugin (data/omny-*.json, downloaded once on 2026-11-03). Bus months after the shipped
 * ones are asked for each month; if that times out, the months already kept are used and it tries again next month.
 */
function mta_nt_omny_fetch()
{
    $old = get_option('mta_nt_omny') ?: [];
    $api = function ($id, array $p) {
        $res = wp_remote_get(add_query_arg(array_map('rawurlencode', $p + ['$limit' => 5000]), "https://data.ny.gov/resource/$id.json"),
            ['timeout' => 600] + (($t = get_option('mta_ridership_app_token')) ? ['headers' => ['X-App-Token' => $t]] : []));
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            throw new Exception("$id: " . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res)));
        }
        return json_decode(wp_remote_retrieve_body($res), true) ?: [];
    };
    $monthly = function ($id, array $keep) use ($api) {
        $since = $keep ? mta_more_month(array_key_last($keep) . '-01', 1) : null;
        foreach ($keep as $m => $v) {
            if ($since && $m >= $since) {
                unset($keep[$m]);
            }
        }
        foreach ($api($id, array_filter(['$select' => 'date_trunc_ym(transit_timestamp) AS m, payment_method AS p, sum(ridership) AS n',
            '$where' => $since ? "transit_timestamp >= '$since-01'" : null, '$group' => 'm, p'])) as $r) {
            $keep[mta_more_month($r['m'])][strtolower($r['p'])] = (int) round((float) $r['n']);
        }
        ksort($keep);
        return $keep;
    };
    $file = function ($name) {
        $p = dirname(__DIR__) . '/data/' . $name;
        return file_exists($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];
    };
    $x = [];
    $x['subway_old'] = $file('omny-subway-2020-2024.json');
    $x['subway'] = [];
    foreach ((get_option('mta_ridership_extras')['payment'] ?? []) as $p) {
        $x['subway'][$p['month']] = ['omny' => (int) $p['omny'], 'metrocard' => (int) $p['metrocard']];
    }
    if (!$x['subway']) {
        throw new Exception('No subway payment months yet');
    }
    $bus = ($old['bus'] ?? []) + $file('omny-bus-2020-2024.json') + $file('omny-bus-seed.json');
    ksort($bus);
    try {
        $x['bus'] = $monthly('gxb3-akrn', $bus);
    } catch (Throwable $e) {
        $x['bus'] = $bus;
        $x['bus_note'] = gmdate('c') . ' ' . $e->getMessage();
    }
    $x['fair_fares'] = [];
    foreach (mta_nt_nyc('3tw8-6si8', ['$order' => 'month']) as $r) {
        $x['fair_fares'][mta_more_month($r['month'])] = (int) $r['total_fair_fares_enrollees'];
    }
    $x['latest'] = array_key_last($x['subway']);
    return $x;
}

/* ---------- shared bits ---------- */

/** "Data: ..." line for sources on either open data site: [url => title]. */
function mta_nt_source(array $links, $updated, $extra = '')
{
    $a = [];
    foreach ($links as $url => $t) {
        $a[] = '<a href="' . esc_url($url) . '" rel="noopener">' . esc_html($t) . '</a>';
    }
    return '<p class="mta-source mta-source-ssr">Data: ' . implode(', ', $a) . '. Updated ' . esc_html(gmdate('M j, Y', strtotime($updated))) . '.' . $extra .
        '<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
}

/** The page with its disclaimer: the MTA one, or the same wording for another agency ($who = [agency, official site name, url, data portal name, url]). */
function mta_nt_wrap($o, array $who = [])
{
    if (!$who) {
        return '<div class="mta-ridership">' . $o . mta_ridership_disclaimer() . '</div>';
    }
    [$agency, $site, $site_url, $portal, $portal_url] = $who;
    return '<div class="mta-ridership">' . $o . '<div class="mta-disclaimer"><p>' . esc_html('This is an independent data analysis project and is not affiliated with, sponsored by, or endorsed by ' . $agency . ' or the Metropolitan Transportation Authority (MTA).') . '</p>' .
        '<p>Data is sourced from the <a href="' . esc_url($portal_url) . '" rel="noopener">' . esc_html($portal) . '</a>. Visualizations, calculations, and interpretations are independently produced. ' .
        'Data may be revised, incomplete, or delayed. For official information, visit <a href="' . esc_url($site_url) . '" rel="noopener">' . esc_html($site) . '</a>.</p></div></div>';
}

function mta_nt_hero($h1, $sub, array $tiles)
{
    return '<header class="mta-hero"><h1>' . esc_html($h1) . '</h1><p class="mta-hero-links">' . esc_html($sub) . '</p><div class="mta-tiles">' . implode('', $tiles) . '</div></header>';
}

/** Full calendar months among [Y-m-d => n] days, as [Y-m => ['n' => total, 'days' => days]]. */
function mta_nt_day_months(array $days)
{
    $m = [];
    foreach ($days as $d => $n) {
        $k = substr($d, 0, 7);
        $m[$k]['n'] = ($m[$k]['n'] ?? 0) + $n;
        $m[$k]['days'] = ($m[$k]['days'] ?? 0) + 1;
    }
    return array_filter($m, function ($v, $k) { return $v['days'] >= (int) gmdate('t', strtotime("$k-01 UTC")); }, ARRAY_FILTER_USE_BOTH);
}

function mta_nt_hour($h)
{
    return mta_ridership_hour_name($h);
}

/* ---------- Staten Island Ferry ---------- */

function mta_nt_sif_facts()
{
    $x = get_option('mta_nt_sif');
    if (!$x || empty($x['days'])) {
        return null;
    }
    $tot = array_map(function ($v) { return $v[0] + $v[1]; }, $x['days']);
    $months = mta_nt_day_months($tot);
    $l12 = array_slice($months, -12, 12, true);
    $p12 = array_slice($months, -24, 12, true);
    $sum = function ($a) { return array_sum(array_column($a, 'n')); };
    $dd = function ($a) { return max(1, array_sum(array_column($a, 'days'))); };
    $per_day = count($l12) === 12 ? (int) round($sum($l12) / $dd($l12)) : null;
    // The same 12 months in 2019, before the pandemic.
    $n19 = $d19 = 0;
    foreach ($l12 as $m => $v) {
        $k = '2019' . substr($m, 4);
        if (isset($months[$k])) {
            $n19 += $months[$k]['n'];
            $d19 += $months[$k]['days'];
        }
    }
    $last365 = array_slice($tot, -365, 365, true);
    $wd = [];
    foreach ($last365 as $d => $n) {
        $w = (int) gmdate('N', strtotime("$d UTC"));
        $wd[$w][] = $n;
    }
    ksort($wd);
    $weekday = [];
    foreach ($wd as $w => $ns) {
        $weekday[$w] = (int) round(array_sum($ns) / count($ns));
    }
    arsort($last365);
    $years = [];
    foreach ($tot as $d => $n) {
        $y = substr($d, 0, 4);
        $years[$y]['n'] = ($years[$y]['n'] ?? 0) + $n;
        $years[$y]['days'] = ($years[$y]['days'] ?? 0) + 1;
    }
    $whitehall = array_sum(array_column(array_slice($x['days'], -365, 365, true), 0));
    $stgeorge = array_sum(array_column(array_slice($x['days'], -365, 365, true), 1));
    $l12k = array_keys($l12);
    return ['x' => $x, 'per_day' => $per_day, 'chg' => count($p12) === 12 ? mta_tm_change($sum($l12), $sum($p12)) : '',
            'vs2019' => $d19 ? round(($sum($l12) / $dd($l12)) / ($n19 / $d19) * 100) : null, 'per_day_2019' => $d19 ? (int) round($n19 / $d19) : null,
            'year12' => $sum($l12), 'weekday' => $weekday, 'top' => array_slice($last365, 0, 5, true),
            'months' => array_map(function ($v) { return (int) round($v['n'] / $v['days']); }, array_slice($months, -36, 36, true)),
            'years' => $years, 'whitehall' => $whitehall, 'stgeorge' => $stgeorge,
            'range' => $l12k ? mta_more_range($l12k[0], end($l12k)) : ''];
}

function mta_nt_sif_faq($f)
{
    $out = [['Is the Staten Island Ferry free?', 'Yes. The Staten Island Ferry is free for everyone. It runs 24 hours a day, 7 days a week, between the Whitehall Terminal in Lower Manhattan and the St. George Terminal on Staten Island, and the ride takes about 25 minutes.']];
    if ($f['per_day']) {
        $out[] = ['How many people ride the Staten Island Ferry?', 'About ' . number_format($f['per_day']) . ' people a day from ' . $f['range'] . ', counting boardings at both terminals' .
            ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before' : '') . '. That is about ' . number_format(round($f['year12'] / 1e6, 1), 1) . ' million rides a year.'];
    }
    if ($f['vs2019']) {
        $out[] = ['Is Staten Island Ferry ridership back to pre-pandemic levels?', 'It is at about ' . $f['vs2019'] . '% of 2019 levels: ' . number_format($f['per_day']) . ' riders a day now, against ' .
            number_format($f['per_day_2019']) . ' a day in the same months of 2019.'];
    }
    if ($f['weekday']) {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $w = $f['weekday'];
        arsort($w);
        $busy = array_key_first($w);
        $quiet = array_key_last($w);
        $out[] = ['What is the busiest day on the Staten Island Ferry?', $names[$busy] . 's, with about ' . number_format($w[$busy]) . ' riders on average over the last year. ' .
            $names[$quiet] . 's are the quietest (' . number_format($w[$quiet]) . ').'];
    }
    return $out;
}

function mta_nt_sif_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    $f = mta_nt_sif_facts();
    if (!$f) {
        return '<p>Staten Island Ferry data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $x = $f['x'];
    $o = mta_nt_hero('Staten Island Ferry: Ridership, Busiest Days & Schedule Info', 'How many people ride the free ferry between Manhattan and Staten Island, from NYC DOT open data, updated monthly · Not affiliated with NYC DOT',
        array_filter([$f['per_day'] ? mta_more_tile(number_format($f['per_day']), 'riders a day') : '', mta_more_tile('Free', 'for everyone, 24/7'),
            $f['vs2019'] ? mta_more_tile($f['vs2019'] . '%', 'of 2019 riders') : '', mta_more_tile('25 min', 'ride, about 5 miles')]));
    $o .= '<p class="mta-note">For the timetable and service changes, see the official <a href="https://www.siferry.com/" rel="noopener">Staten Island Ferry site</a>. Boats run around the clock, most often at rush hours.</p>';

    if ($f['per_day']) {
        $o .= mta_more_sec('How many people ride the Staten Island Ferry?', '<p>' . esc_html('About ' . number_format($f['per_day']) . ' riders a day from ' . $f['range'] .
            ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before' : '') . ($f['vs2019'] ? ', and ' . $f['vs2019'] . '% of the riders in the same months of 2019, before the pandemic.' : '.')) . '</p>' .
            mta_more_h3('Riders a day, by month') . mta_more_month_chart($f['months'], 'Staten Island Ferry riders a day by month'),
            'Boardings at the Whitehall (Manhattan) and St. George (Staten Island) terminals together, averaged over each month.');
    }

    if ($f['weekday']) {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $items = [];
        foreach ($f['weekday'] as $w => $n) {
            $items[] = [$names[$w], $n, number_format($n), ''];
        }
        $o .= mta_more_sec('Busiest days of the week', mta_more_bars($items), 'Average riders a day, last 365 days.');
    }

    if ($f['top']) {
        $rows = [];
        foreach ($f['top'] as $d => $n) {
            $rows[] = [esc_html(gmdate('l, F j, Y', strtotime("$d UTC"))), number_format($n)];
        }
        $o .= mta_more_sec('Busiest days of the last year', mta_more_table(['Day', 'Riders'], $rows));
    }

    $rows = [];
    foreach ($f['years'] as $y => $v) {
        $full = $v['days'] >= 365;
        $rows[] = [esc_html($y . ($full ? '' : ($y === substr($x['latest'], 0, 4) ? ' (so far)' : ' (some days missing)'))), number_format(round($v['n'] / $v['days'])), $full ? number_format(round($v['n'] / 1e6, 1), 1) . ' million' : '–'];
    }
    $o .= mta_more_sec('Staten Island Ferry ridership by year', mta_more_table(['Year', 'Riders a day', 'Rides in the year'], $rows), 'Both terminals.');

    if ($f['whitehall'] + $f['stgeorge']) {
        $t = $f['whitehall'] + $f['stgeorge'];
        $o .= mta_more_sec('Which way do people ride?', mta_more_bars([
            ['From Whitehall (Manhattan)', $f['whitehall'], round($f['whitehall'] / $t * 100) . '%', number_format(round($f['whitehall'] / 365)) . ' a day'],
            ['From St. George (Staten Island)', $f['stgeorge'], round($f['stgeorge'] / $t * 100) . '%', number_format(round($f['stgeorge'] / 365)) . ' a day']]),
            'Boardings at each terminal, last 365 days. The <a href="' . esc_url(mta_tm_url('sir')) . '">Staten Island Railway</a> meets the ferry at St. George.');
    }

    $o .= mta_tm_faq_html(mta_nt_sif_faq($f));
    $o .= mta_ridership_ssr_more('sif');
    $o .= mta_nt_source(['https://data.cityofnewyork.us/d/6eng-46dm' => 'Staten Island Ferry Ridership Counts (NYC Open Data)'], $x['updated']);
    return mta_nt_wrap($o, ['the NYC Department of Transportation (NYC DOT)', 'siferry.com', 'https://www.siferry.com/', 'NYC Open Data portal', 'https://opendata.cityofnewyork.us/']);
}

add_shortcode('mta_sif', 'mta_nt_sif_page');

/* ---------- NYC Ferry ---------- */

function mta_nt_ferry_route($r)
{
    $n = ['ER' => 'East River', 'AS' => 'Astoria', 'SB' => 'South Brooklyn', 'RS' => 'Rockaway-Soundview', 'SG' => 'St. George', 'GI' => 'Governors Island Shuttle',
          'RW' => 'Rockaway (before it joined Soundview)', 'SV' => 'Soundview (before it joined Rockaway)'];
    return $n[$r] ?? $r . ' route';
}

function mta_nt_ferry_facts()
{
    $x = get_option('mta_nt_ferry');
    if (!$x || empty($x['routes'])) {
        return null;
    }
    $full = array_filter($x['months'], function ($v, $m) use ($x) { return $m <= $x['latest']; }, ARRAY_FILTER_USE_BOTH);
    $l12 = array_slice($full, -12, 12, true);
    $p12 = array_slice($full, -24, 12, true);
    $sum = function ($a) { return array_sum(array_column($a, 'n')); };
    $days = mta_tm_days($x['from'], $x['latest']);
    $years = [];
    foreach ($full as $m => $v) {
        $years[substr($m, 0, 4)] = ($years[substr($m, 0, 4)] ?? 0) + $v['n'];
    }
    return ['x' => $x, 'year12' => $sum($l12), 'per_day' => (int) round($sum($l12) / max(1, $days)), 'days' => $days,
            'chg' => count($p12) === 12 ? mta_tm_change($sum($l12), $sum($p12)) : '',
            'months' => array_map(function ($v) { return (int) round($v['n'] / max(1, $v['days'])); }, array_slice($full, -36, 36, true)),
            'years' => $years, 'range' => mta_more_range($x['from'], $x['latest'])];
}

function mta_nt_ferry_faq($f)
{
    $x = $f['x'];
    $r = array_key_first($x['routes']);
    $s = array_key_first($x['stops']);
    $out = [['How many people ride NYC Ferry?', 'About ' . number_format($f['per_day']) . ' boardings a day from ' . $f['range'] . ', or ' . number_format(round($f['year12'] / 1e6, 1), 1) . ' million in 12 months' .
        ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before.' : '.')],
            ['What is the busiest NYC Ferry route?', 'The ' . mta_nt_ferry_route($r) . ' route, with ' . number_format($x['routes'][$r]) . ' boardings in 12 months (' .
                round($x['routes'][$r] / max(1, array_sum($x['routes'])) * 100) . '% of all riders).'],
            ['What is the busiest NYC Ferry stop?', $s . ', with ' . number_format(round($x['stops'][$s] / max(1, $f['days']))) . ' boardings a day on average.']];
    $summer = $winter = [];
    foreach (array_slice($x['months'], -12, 12, true) as $m => $v) {
        $mm = (int) substr($m, 5, 2);
        if (in_array($mm, [6, 7, 8], true)) {
            $summer[] = $v['n'] / max(1, $v['days']);
        } elseif (in_array($mm, [12, 1, 2], true)) {
            $winter[] = $v['n'] / max(1, $v['days']);
        }
    }
    if ($summer && $winter) {
        $out[] = ['Is NYC Ferry busier in summer?', 'Yes. Summer days average about ' . number_format(round(array_sum($summer) / count($summer))) . ' boardings, against ' .
            number_format(round(array_sum($winter) / count($winter))) . ' in winter.'];
    }
    return $out;
}

function mta_nt_ferry_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    $f = mta_nt_ferry_facts();
    if (!$f) {
        return '<p>NYC Ferry data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $x = $f['x'];
    $o = mta_nt_hero('NYC Ferry: Ridership by Route, Busiest Stops & Times', 'NYC Ferry boardings by route, stop and hour, from NYC Open Data, ' . $f['range'] . ', updated monthly · Not affiliated with NYC Ferry',
        array_filter([mta_more_tile(number_format($f['per_day']), 'boardings a day'), mta_more_tile(number_format(round($f['year12'] / 1e6, 1), 1) . 'M', 'rides in 12 months'),
            mta_more_tile((string) count($x['stops']), 'stops'), mta_more_tile((string) count(array_filter($x['routes'], function ($n) { return $n > 10000; })), 'routes')]));
    $o .= '<p class="mta-note">For fares, timetables and service alerts, see the official <a href="https://www.ferry.nyc/" rel="noopener">NYC Ferry site</a>.</p>';
    $o .= mta_tt_picker('ferry', 'NYC Ferry', 'wall-st-pier-11');

    $o .= mta_more_sec('How many people ride NYC Ferry?', '<p>' . esc_html('About ' . number_format($f['per_day']) . ' boardings a day from ' . $f['range'] . ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before.' : '.')) . '</p>' .
        mta_more_h3('Boardings a day, by month') . mta_more_month_chart($f['months'], 'NYC Ferry boardings a day by month'), 'Every boarding counts once, so a round trip is two.');

    $items = [];
    foreach ($x['routes'] as $r => $n) {
        $stops = array_keys($x['route_stops'][$r] ?? []);
        $items[] = [mta_nt_ferry_route($r), $n, number_format(round($n / max(1, $f['days']))) . ' a day', implode(' · ', array_slice($stops, 0, 8))];
    }
    $o .= mta_more_sec('NYC Ferry routes ranked', mta_more_bars($items), 'Boardings a day by route, ' . esc_html($f['range']) . ', with each route\'s stops (busiest first).');

    $items = [];
    foreach (array_slice($x['stops'], 0, 25, true) as $s => $n) {
        $items[] = [$s, $n, number_format(round($n / max(1, $f['days']))) . ' a day', ''];
    }
    $o .= mta_more_sec('Busiest NYC Ferry stops', mta_more_bars($items), 'Boardings a day at each landing, all routes.');

    if ($x['hours']) {
        $rows = [];
        $types = array_keys($x['hours']);
        sort($types);
        for ($h = 5; $h <= 23; $h++) {
            $row = [esc_html(mta_nt_hour($h))];
            foreach ($types as $t) {
                $v = $x['hours'][$t][$h] ?? null;
                $row[] = $v ? number_format(round($v['n'] / max(1, $v['days']))) : '–';
            }
            $rows[] = $row;
        }
        $o .= mta_more_sec('When is NYC Ferry busiest?', mta_more_table(array_merge(['Hour'], array_map(function ($t) { return $t . ' (a day)'; }, $types)), $rows),
            'Average boardings in each hour, all routes.');
    }

    $rows = [];
    foreach ($f['years'] as $y => $n) {
        $rows[] = [esc_html($y . ($y === substr($x['latest'], 0, 4) ? ' (so far)' : '')), number_format($n)];
    }
    $o .= mta_more_sec('NYC Ferry ridership by year', mta_more_table(['Year', 'Boardings'], $rows), 'The service started in May 2017.');

    $o .= mta_tm_faq_html(mta_nt_ferry_faq($f));
    $o .= mta_ridership_ssr_more('ferry');
    $o .= mta_nt_source(['https://data.cityofnewyork.us/d/t5n6-gx8c' => 'NYC Ferry Ridership (NYC Open Data)'], $x['updated']);
    return mta_nt_wrap($o, ['NYC Ferry or the NYC Economic Development Corporation', 'ferry.nyc', 'https://www.ferry.nyc/', 'NYC Open Data portal', 'https://opendata.cityofnewyork.us/']);
}

add_shortcode('mta_nyc_ferry', 'mta_nt_ferry_page');

/* ---------- PATH ---------- */

/** PATH fares, from panynj.gov (checked MTA_NT_PRICES_CHECKED). */
function mta_nt_path_fares()
{
    return [['One ride (TAPP, contactless card or phone)', '$3.25'], ['10 rides on a TAPP card', '$31 ($3.10 a ride)'], ['Unlimited 1 day', '$12.50'],
            ['Unlimited 7 days', '$42.75'], ['Unlimited 30 days', '$131.50'], ['Seniors 65+ and riders with disabilities', '$1.60'], ['Children 5 and under', 'Free']];
}

/** PATH stations and routes (they rarely change). */
function mta_nt_path_lines()
{
    return ['Newark – World Trade Center' => ['Newark Penn Station', 'Harrison', 'Journal Square', 'Grove Street', 'Exchange Place', 'World Trade Center'],
            'Hoboken – World Trade Center' => ['Hoboken', 'Newport', 'Exchange Place', 'World Trade Center'],
            'Journal Square – 33rd Street' => ['Journal Square', 'Grove Street', 'Newport', 'Christopher Street', '9th Street', '14th Street', '23rd Street', '33rd Street'],
            'Hoboken – 33rd Street' => ['Hoboken', 'Christopher Street', '9th Street', '14th Street', '23rd Street', '33rd Street']];
}

/** Rides a day for a system over the last 12 months it has, and the 12 before. */
function mta_nt_regional_stats(array $months)
{
    $l12 = array_slice($months, -12, 12, true);
    $p12 = array_slice($months, -24, 12, true);
    $keys = array_keys($l12);
    $days = $keys ? mta_tm_days($keys[0], end($keys)) : 1;
    return ['per_day' => (int) round(array_sum($l12) / max(1, $days)), 'year12' => array_sum($l12), 'chg' => count($p12) === 12 ? mta_tm_change(array_sum($l12), array_sum($p12)) : '',
            'range' => $keys ? mta_more_range($keys[0], end($keys)) : '', 'latest' => end($keys)];
}

function mta_nt_path_facts()
{
    $x = get_option('mta_nt_regional');
    if (!$x || empty($x['months']['PATH'])) {
        return null;
    }
    $months = $x['months']['PATH'];
    $s = mta_nt_regional_stats($months);
    $series = [];
    foreach (array_slice($months, -36, 36, true) as $m => $n) {
        $series[$m] = (int) round($n / (int) gmdate('t', strtotime("$m-01 UTC")));
    }
    return ['x' => $x, 'series' => $series] + $s;
}

function mta_nt_path_faq($f)
{
    return [['How much is a PATH train ride?', 'A one-way PATH ride is $3.25, paid with a TAPP card or a contactless credit card, debit card or phone. Seniors 65 and over and riders with disabilities pay $1.60. ' .
                'PATH does not take OMNY cards, and MetroCards stopped working on PATH on August 31, 2026.'],
            ['How many people ride PATH?', 'About ' . number_format($f['per_day']) . ' rides a day from ' . $f['range'] . ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before.' : '.')],
            ['Where does the PATH train go?', 'PATH runs between New Jersey (Newark, Harrison, Jersey City and Hoboken) and Manhattan (the World Trade Center, and 6th Avenue from Christopher Street up to 33rd Street). It has 13 stations on 4 routes.']];
}

function mta_nt_path_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    $f = mta_nt_path_facts();
    if (!$f) {
        return '<p>PATH data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $x = $f['x'];
    $o = mta_nt_hero('PATH Train: Ridership, Fares, Stations & Map', 'PATH train rides between New Jersey and Manhattan, from the New York region\'s open ridership data, updated monthly · Not affiliated with the Port Authority',
        [mta_more_tile(number_format($f['per_day']), 'rides a day'), mta_more_tile('$3.25', 'one ride'), mta_more_tile('13', 'stations'), mta_more_tile('4', 'routes')]);
    $o .= '<p class="mta-note">For schedules, the map and service alerts, see the official <a href="https://www.panynj.gov/path/en/index.html" rel="noopener">PATH site</a>.</p>';

    $o .= mta_more_sec('How many people ride PATH?', '<p>' . esc_html('About ' . number_format($f['per_day']) . ' rides a day from ' . $f['range'] . ($f['chg'] ? ', ' . $f['chg'] . ' than the 12 months before.' : '.')) . '</p>' .
        mta_more_h3('Rides a day, by month') . mta_more_month_chart($f['series'], 'PATH rides a day by month'), 'Monthly rides, divided by the days in the month.');

    $rows = [];
    foreach (mta_nt_path_fares() as [$k, $v]) {
        $rows[] = [esc_html($k), esc_html($v)];
    }
    $o .= mta_more_sec('How much does PATH cost?', mta_more_table(['Fare', 'Price'], $rows),
        'From the Port Authority, ' . esc_html(gmdate('F Y', strtotime(MTA_NT_PRICES_CHECKED))) . '. Pay with a TAPP card or a contactless card or phone. PATH does not take OMNY cards or MetroCards.');

    $li = '';
    foreach (mta_nt_path_lines() as $line => $stops) {
        $li .= '<li><strong>' . esc_html($line) . '</strong>: ' . esc_html(implode(' · ', $stops)) . '</li>';
    }
    $o .= mta_more_sec('PATH routes and stations', '<ul>' . $li . '</ul>', 'Late nights and weekends, Journal Square – 33rd Street trains run by way of Hoboken.');

    // PATH against the other systems in the same data.
    $items = [];
    foreach ($x['months'] as $mode => $months) {
        $s = mta_nt_regional_stats($months);
        if ($s['per_day'] > 0 && $mode !== 'Demand Response' && $mode !== 'Access-A-Ride') {
            $items[$mode] = $s['per_day'];
        }
    }
    arsort($items);
    $bars = [];
    foreach ($items as $mode => $n) {
        $bars[] = [$mode === 'PATH' ? ['html' => '<strong>PATH</strong>'] : $mode, $n, number_format($n) . ' a day', ''];
    }
    $o .= mta_more_sec('PATH against other New York transit', mta_more_bars($bars), 'Rides a day over each system\'s last 12 reported months. "Bus" is MTA buses; NJ Transit buses are counted separately.');

    $o .= mta_tm_faq_html(mta_nt_path_faq($f));
    $o .= mta_ridership_ssr_more('path');
    $o .= mta_nt_source(['https://data.ny.gov/d/sv2g-g4mz' => 'MTA Regional Transit Ridership', 'https://www.panynj.gov/path/en/fares.html' => 'PATH fares (panynj.gov)'], $x['updated']);
    return mta_nt_wrap($o, ['the Port Authority of New York and New Jersey', 'panynj.gov/path', 'https://www.panynj.gov/path/en/index.html', 'New York State Open Data Portal', 'https://data.ny.gov/']);
}

add_shortcode('mta_path', 'mta_nt_path_page');

/* ---------- OMNY ---------- */

/** OMNY prices, from omny.info (checked MTA_NT_PRICES_CHECKED). */
function mta_nt_omny_prices()
{
    return ['fare' => 3.00, 'cap' => 35, 'cap_express' => 67, 'cap_reduced' => 17.50];
}

function mta_nt_omny_facts()
{
    $x = get_option('mta_nt_omny');
    if (!$x || empty($x['subway'])) {
        return null;
    }
    $share = function (array $months) {
        $out = [];
        foreach ($months as $m => $p) {
            $t = ($p['omny'] ?? 0) + ($p['metrocard'] ?? 0);
            if ($t) {
                $out[$m] = round(($p['omny'] ?? 0) / $t * 100, 1);
            }
        }
        return $out;
    };
    $sub = $share($x['subway_old'] + $x['subway']);
    $bus = $share($x['bus']);
    $full = function ($m) { return $m < gmdate('Y-m'); };
    $sub = array_filter($sub, $full, ARRAY_FILTER_USE_KEY);
    $bus = array_filter($bus, $full, ARRAY_FILTER_USE_KEY);
    // The first month OMNY passed half of subway rides.
    $half = null;
    foreach ($sub as $m => $v) {
        if ($v >= 50) {
            $half = $m;
            break;
        }
    }
    $ex = get_option('mta_ridership_extras');
    return ['x' => $x, 'sub' => $sub, 'bus' => $bus, 'sub_now' => end($sub), 'bus_now' => $bus ? end($bus) : null, 'month' => array_key_last($sub),
            'half' => $half, 'fares' => $ex['fares'] ?? [], 'fare_range' => !empty($ex['from']) ? mta_ridership_nice_date($ex['from']) . ' – ' . mta_ridership_nice_date($ex['to']) : '',
            'ff' => $x['fair_fares'], 'p' => mta_nt_omny_prices()];
}

function mta_nt_omny_faq($f)
{
    $p = $f['p'];
    $out = [['What is the OMNY fare cap?', 'If you pay $' . $p['cap'] . ' in subway and local bus fares within 7 days using the same card or phone, the rest of your rides in that 7 days are free. ' .
                'The cap is $' . $p['cap_express'] . ' if you also ride express buses, and $' . number_format($p['cap_reduced'], 2) . ' for Reduced-Fare riders. Your first tap starts the 7 days.'],
            ['How much is the subway fare with OMNY?', 'A subway or local bus ride is $' . number_format($p['fare'], 2) . ' with OMNY, with a free transfer within 2 hours.']];
    $out[] = ['How many riders use OMNY?', $f['sub_now'] . '% of subway rides were paid with OMNY in ' . gmdate('F Y', strtotime($f['month'] . '-01 UTC')) .
        ($f['bus_now'] !== null ? ', and ' . $f['bus_now'] . '% of bus rides.' : '.') . ($f['half'] ? ' OMNY first passed half of subway rides in ' . gmdate('F Y', strtotime($f['half'] . '-01 UTC')) . '.' : '')];
    if ($f['ff']) {
        $out[] = ['How many New Yorkers have Fair Fares?', number_format(end($f['ff'])) . ' people were enrolled in Fair Fares NYC in ' . gmdate('F Y', strtotime(array_key_last($f['ff']) . '-01 UTC')) .
            '. Fair Fares gives New Yorkers with low incomes half-price subway and bus fares.'];
    }
    $out[] = ['Can I use OMNY on PATH or the LIRR?', 'Not on PATH, which uses its own TAPP system. OMNY works on subways and MTA buses; the LIRR and Metro-North use their own tickets.'];
    return $out;
}

function mta_nt_omny_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    $f = mta_nt_omny_facts();
    if (!$f) {
        return '<p>OMNY data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $x = $f['x'];
    $p = $f['p'];
    $mon = gmdate('F Y', strtotime($f['month'] . '-01 UTC'));
    $o = mta_nt_hero('OMNY: Fare Cap, How It Works & How Many Riders Use It', 'Tap-to-pay on NYC subways and buses: prices from omny.info and ridership from MTA open data, updated monthly · Not affiliated with the MTA',
        array_filter([mta_more_tile($f['sub_now'] . '%', 'of subway rides, ' . $mon), $f['bus_now'] !== null ? mta_more_tile($f['bus_now'] . '%', 'of bus rides') : '',
            mta_more_tile('$' . number_format($p['fare'], 2), 'a ride'), mta_more_tile('$' . $p['cap'], 'weekly fare cap')]));

    $rows = [['Subway or local bus ride', '$' . number_format($p['fare'], 2)], ['Weekly cap: subways and local buses', '$' . $p['cap'] . ', then free for the rest of the 7 days'],
             ['Weekly cap: with express buses', '$' . $p['cap_express']], ['Weekly cap: Reduced-Fare riders', '$' . number_format($p['cap_reduced'], 2)]];
    $o .= mta_more_sec('How the OMNY fare cap works', '<p>' . esc_html('Tap the same card or phone every time. Once you have paid $' . $p['cap'] . ' within 7 days (12 rides at $' . number_format($p['fare'], 2) .
        ' are $36, so the 12th ride costs only $2), every ride after that is free until the 7 days are up. Your first tap starts the 7 days. Transfers and group fares do not count toward the cap.') . '</p>' .
        mta_more_table(['Fare', 'Price'], array_map(function ($r) { return array_map('esc_html', $r); }, $rows)),
        'From <a href="https://omny.info/fares" rel="noopener">omny.info</a>, ' . esc_html(gmdate('F Y', strtotime(MTA_NT_PRICES_CHECKED))) . '.');

    $o .= mta_more_sec('How fast OMNY replaced the MetroCard', '<p>' . esc_html($f['sub_now'] . '% of subway rides were paid with OMNY in ' . $mon .
        ($f['half'] ? '. OMNY first passed half of subway rides in ' . gmdate('F Y', strtotime($f['half'] . '-01 UTC')) . '.' : '.')) . '</p>' .
        mta_more_h3('Subway rides paid with OMNY, by month') . mta_more_month_chart($f['sub'], 'Share of subway rides paid with OMNY', function ($v) { return $v . '%'; }) .
        ($f['bus'] ? mta_more_h3('Bus rides paid with OMNY, by month') . mta_more_month_chart($f['bus'], 'Share of bus rides paid with OMNY', function ($v) { return $v . '%'; }) : ''),
        'Paid entries by OMNY and by MetroCard. OMNY includes tap-to-pay cards, phones and watches and the OMNY card.');

    if ($f['fares']) {
        $items = [];
        foreach ($f['fares'] as $r) {
            $items[] = [$r['category'], $r['riders'], round($r['share'] * 100, 1) . '%', number_format($r['riders']) . ' rides'];
        }
        $o .= mta_more_sec('Who pays which fare?', mta_more_bars(array_slice($items, 0, 10)), 'Subway rides by fare type, ' . esc_html($f['fare_range']) . '.');
    }

    if (count($f['ff']) > 1) {
        $o .= mta_more_sec('Fair Fares: half-price rides', '<p>' . esc_html(number_format(end($f['ff'])) . ' New Yorkers were enrolled in Fair Fares NYC in ' . gmdate('F Y', strtotime(array_key_last($f['ff']) . '-01 UTC')) .
            '. The city program gives people with low incomes half-price subway and bus rides.') . '</p>' .
            mta_more_h3('People enrolled, by month') . mta_more_month_chart(array_slice($f['ff'], -36, 36, true), 'Fair Fares enrollees by month'),
            'From NYC Open Data. Apply at <a href="https://www.nyc.gov/site/fairfares/index.page" rel="noopener">nyc.gov/fairfares</a>.');
    }

    $o .= mta_tm_faq_html(mta_nt_omny_faq($f));
    $o .= mta_ridership_ssr_more('omny');
    $o .= mta_nt_source(['https://data.ny.gov/d/5wq4-mkjj' => 'MTA Subway Hourly Ridership', 'https://data.ny.gov/d/wujg-7c2s' => 'MTA Subway Hourly Ridership 2020-2024',
        'https://data.ny.gov/d/gxb3-akrn' => 'MTA Bus Hourly Ridership', 'https://data.cityofnewyork.us/d/3tw8-6si8' => 'Fair Fares Enrollees (NYC Open Data)', 'https://omny.info/fares' => 'OMNY fares'], $x['updated']);
    return mta_nt_wrap($o);
}

add_shortcode('mta_omny', 'mta_nt_omny_page');

/* ---------- bridge and tunnel tolls ---------- */

/** Toll prices for cars and motorcycles, from mta.info (effective January 4, 2026; checked MTA_NT_PRICES_CHECKED). */
function mta_nt_tolls()
{
    $big = ['car' => ['7.46', '9.79', '12.03'], 'moto' => ['3.25', '4.18', '5.06']];
    $small = ['car' => ['2.80', '4.42', '6.02'], 'moto' => ['2.33', '3.72', '5.06']];
    return [
        'Verrazzano-Narrows Bridge' => $big + ['note' => 'Staten Island residents with E-ZPass: $4.19 (an effective $2.75 with the resident rebate).'],
        'Robert F. Kennedy Bridge' => $big, 'Bronx-Whitestone Bridge' => $big, 'Throgs Neck Bridge' => $big,
        'Queens Midtown Tunnel' => $big, 'Hugh L. Carey Tunnel' => $big,
        'Henry Hudson Bridge' => ['car' => ['3.42', '5.42', '8.87'], 'moto' => $small['moto']],
        'Cross Bay Veterans Memorial Bridge' => $small + ['note' => 'Rockaway and Broad Channel residents with E-ZPass: $1.83, rebated after each trip.'],
        'Marine Parkway-Gil Hodges Memorial Bridge' => $small + ['note' => 'Rockaway and Broad Channel residents with E-ZPass: $1.83.'],
    ];
}

/** The traffic data's name for a crossing (it calls the Hugh L. Carey Tunnel by its old name). */
function mta_nt_toll_traffic($name, array $fac)
{
    foreach ($fac as $k => $n) {
        $a = strtolower(preg_replace('/[^a-z]/i', '', $k));
        $b = strtolower(preg_replace('/[^a-z]/i', '', $name));
        if (strpos($a, substr($b, 0, 8)) !== false || ($name === 'Hugh L. Carey Tunnel' && stripos($k, 'Brooklyn-Battery') !== false) ||
            ($name === 'Marine Parkway-Gil Hodges Memorial Bridge' && stripos($k, 'Marine') !== false) || ($name === 'Cross Bay Veterans Memorial Bridge' && stripos($k, 'Cross Bay') !== false)) {
            return $n;
        }
    }
    return null;
}

function mta_nt_tolls_faq()
{
    return [['How much is the toll on the Verrazzano Bridge?', 'For a car it is $7.46 with an E-ZPass from the New York service center, $9.79 for other E-ZPass and plate-based charges ("mid-tier"), and $12.03 by mail. Staten Island residents with E-ZPass pay less.'],
            ['Which NYC bridges are free?', 'The East River bridges run by the city (Brooklyn, Manhattan, Williamsburg and Ed Koch Queensboro) have no toll, and neither do most Harlem River bridges. The MTA charges tolls on its 7 bridges and 2 tunnels.'],
            ['What is cheaper, E-ZPass or Tolls by Mail?', 'E-ZPass. At the big MTA crossings a car pays $7.46 with a New York E-ZPass against $12.03 by mail, so Tolls by Mail costs about 61% more.'],
            ['Do I pay the congestion pricing toll too?', 'Only if you drive into Manhattan below 60th Street. Tunnel drivers get a crossing credit against it at peak times. See the congestion pricing page for the details.']];
}

function mta_nt_tolls_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    mta_tm_css();
    $b = get_option('mta_more_bridges');
    $fac = $b['facilities'] ?? [];
    $days = max(1, $b['days'] ?? 365);
    $o = mta_nt_hero('NYC Bridge & Tunnel Tolls (2026): Every MTA Crossing', 'What a car or motorcycle pays at each MTA bridge and tunnel, from mta.info (rates since January 4, 2026), with traffic from MTA open data · Not affiliated with the MTA',
        [mta_more_tile('$7.46', 'car with NY E-ZPass, big crossings'), mta_more_tile('$12.03', 'car by mail'), mta_more_tile('9', 'MTA bridges and tunnels'),
         $fac ? mta_more_tile(number_format(round(array_sum($fac) / $days)), 'crossings a day') : '']);

    $rows = [];
    foreach (mta_nt_tolls() as $name => $t) {
        $n = mta_nt_toll_traffic($name, $fac);
        $rows[] = [esc_html($name) . (!empty($t['note']) ? '<br><small class="mta-note">' . esc_html($t['note']) . '</small>' : ''), '$' . $t['car'][0], '$' . $t['car'][1], '$' . $t['car'][2],
            $n ? number_format(round($n / $days)) : '–'];
    }
    $o .= mta_more_sec('Car tolls at every MTA crossing', mta_more_table(['Crossing', 'NY E-ZPass', 'Mid-tier', 'By mail', 'Crossings a day'], $rows),
        'Two-axle cars, SUVs and small trucks. "NY E-ZPass" is an E-ZPass from the New York service center; "mid-tier" is other E-ZPass and plate charges posted to a New York account. ' .
        'Tolls are charged electronically with no booths. From <a href="https://www.mta.info/fares-tolls/tolls/vehicle-types" rel="noopener">mta.info</a>.');

    $rows = [];
    foreach (mta_nt_tolls() as $name => $t) {
        $rows[] = [esc_html($name), '$' . $t['moto'][0], '$' . $t['moto'][1], '$' . $t['moto'][2]];
    }
    $o .= mta_more_sec('Motorcycle tolls', mta_more_table(['Crossing', 'NY E-ZPass', 'Mid-tier', 'By mail'], $rows));

    $o .= mta_more_sec('Save on tolls', '<ul><li>' . esc_html('Get an E-ZPass: by mail a car pays about 61% more at the big crossings.') . ' <a href="' . esc_url(mta_tm_url('ezpass')) . '">Where to get an E-ZPass &rarr;</a></li>' .
        '<li>' . esc_html('Staten Island, Rockaway and Broad Channel residents can sign up for resident discounts on their local bridges.') . '</li>' .
        '<li><a href="' . esc_url(home_url('/' . MTA_MORE_PAGES['bridges'] . '/')) . '">' . esc_html('Traffic on each bridge and tunnel, busiest hours and how people pay') . '</a></li>' .
        '<li><a href="' . esc_url(home_url('/congestion-pricing/')) . '">' . esc_html('Congestion pricing: the toll for driving into Manhattan below 60th Street') . '</a></li></ul>');

    $o .= mta_tm_faq_html(mta_nt_tolls_faq());
    $o .= mta_ridership_ssr_more('tolls');
    $o .= mta_nt_source(['https://www.mta.info/fares-tolls/tolls/vehicle-types' => 'MTA toll rates (mta.info)', 'https://data.ny.gov/d/qzve-kjga' => 'MTA Bridges and Tunnels traffic'],
        $b['updated'] ?? MTA_NT_PRICES_CHECKED, ' Toll prices checked ' . esc_html(gmdate('M j, Y', strtotime(MTA_NT_PRICES_CHECKED))) . '.');
    return mta_nt_wrap($o);
}

add_shortcode('mta_tolls', 'mta_nt_tolls_page');

/* ---------- titles, descriptions and FAQ markup ---------- */

function mta_nt_seo()
{
    if (!mta_nt_live()) {
        return null;
    }
    if (is_page(MTA_NT_PAGES['sif']) && ($f = mta_nt_sif_facts())) {
        return ['title' => 'Staten Island Ferry: Ridership, Busiest Days & Schedule Info',
                'description' => 'The free Staten Island Ferry carries about ' . number_format($f['per_day']) . ' riders a day. Ridership by month, day and year, busiest days and 2019 comparison. From NYC open data.',
                'faq' => mta_nt_sif_faq($f)];
    }
    if (is_page(MTA_NT_PAGES['ferry']) && ($f = mta_nt_ferry_facts())) {
        return ['title' => 'NYC Ferry Ridership: Routes, Busiest Stops & Times',
                'description' => 'NYC Ferry carries about ' . number_format($f['per_day']) . ' riders a day. Every route and stop ranked, busiest hours, summer vs winter and ridership by year. From NYC open data.',
                'faq' => mta_nt_ferry_faq($f)];
    }
    if (is_page(MTA_NT_PAGES['path']) && ($f = mta_nt_path_facts())) {
        return ['title' => 'PATH Train: Fares, Stations, Routes & Ridership',
                'description' => 'PATH fares ($3.25 a ride), all 13 stations and 4 routes, and about ' . number_format($f['per_day']) . ' rides a day by month. How PATH compares with NYC transit.',
                'faq' => mta_nt_path_faq($f)];
    }
    if (is_page(MTA_NT_PAGES['omny']) && ($f = mta_nt_omny_facts())) {
        return ['title' => 'OMNY Fare Cap & Card Guide: How Tap-to-Pay Works (' . substr($f['month'], 0, 4) . ')',
                'description' => 'How the OMNY $' . $f['p']['cap'] . ' weekly fare cap works, what a ride costs, and how fast OMNY replaced the MetroCard: ' . $f['sub_now'] . '% of subway rides now. Fair Fares too.',
                'faq' => mta_nt_omny_faq($f)];
    }
    if (is_page(MTA_NT_PAGES['tolls'])) {
        return ['title' => 'NYC Bridge & Tunnel Tolls 2026: Every MTA Crossing',
                'description' => 'Toll for every MTA bridge and tunnel in 2026: Verrazzano, RFK, Whitestone, Throgs Neck, Queens Midtown and more, with E-ZPass, mid-tier and by-mail prices for cars and motorcycles.',
                'faq' => mta_nt_tolls_faq()];
    }
    return apply_filters('mta_nt_seo', null); // more pages (nyctransit2.php)
}

add_filter('wpseo_title', function ($t) { return ($s = mta_nt_seo()) ? mta_ridership_seo_title($s['title']) : $t; });
add_filter('wpseo_opengraph_title', function ($t) { return ($s = mta_nt_seo()) ? $s['title'] : $t; });
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc'] as $f) {
    add_filter($f, function ($t) { return ($s = mta_nt_seo()) ? mta_ridership_seo_desc($s['description']) : $t; });
}
add_action('wp_head', function () {
    if (!($s = mta_nt_seo()) || !$s['faq']) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($i) {
            return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
        }, $s['faq']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});

/* ---------- link-preview images ---------- */

add_filter('mta_share_cards', function ($cards) {
    if (!mta_nt_live() && !(defined('WP_CLI') && WP_CLI)) {
        return $cards;
    }
    return $cards + [
        'sif' => ['share.jpg', function () {
            $f = mta_nt_sif_facts();
            if (!$f || !$f['per_day']) {
                return null;
            }
            $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
            $bars = [];
            foreach ($f['weekday'] as $w => $n) {
                $bars[$names[$w]] = $n;
            }
            return ['title' => 'Staten Island Ferry', 'subtitle' => 'Free, 24/7, Manhattan to Staten Island', 'stats' => array_filter([[number_format($f['per_day']), 'riders a day'],
                    $f['vs2019'] ? [$f['vs2019'] . '%', 'of 2019 riders'] : null]),
                    'bars_title' => 'Riders a day, by day of week', 'bars' => mta_tm_card_bars($bars), 'fmt' => 'number_format',
                    'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['sif'] . '  ·  NYC open data, updated monthly'];
        }],
        'ferry' => ['share.jpg', function () {
            $f = mta_nt_ferry_facts();
            if (!$f) {
                return null;
            }
            $bars = [];
            foreach ($f['x']['routes'] as $r => $n) {
                $bars[mta_nt_ferry_route($r)] = (int) round($n / max(1, $f['days']));
            }
            return ['title' => 'NYC Ferry Ridership', 'subtitle' => 'Every route and stop, busiest hours', 'stats' => [[number_format($f['per_day']), 'boardings a day'],
                    [number_format(round($f['year12'] / 1e6, 1), 1) . 'M', 'rides in 12 months']], 'bars_title' => 'Boardings a day, by route', 'bars' => mta_tm_card_bars($bars),
                    'fmt' => 'number_format', 'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['ferry'] . '  ·  NYC open data, updated monthly'];
        }],
        'path' => ['share.jpg', function () {
            $f = mta_nt_path_facts();
            if (!$f) {
                return null;
            }
            $bars = [];
            foreach ($f['x']['months'] as $mode => $months) {
                $s = mta_nt_regional_stats($months);
                if ($s['per_day'] > 0 && !in_array($mode, ['Demand Response', 'Access-A-Ride'], true)) {
                    $bars[$mode] = $s['per_day'];
                }
            }
            arsort($bars);
            return ['title' => 'PATH Train', 'subtitle' => 'Fares, stations, routes and ridership', 'stats' => [[number_format($f['per_day']), 'rides a day'], ['$3.25', 'one ride']],
                    'bars_title' => 'Rides a day, New York transit', 'bars' => mta_tm_card_bars($bars), 'fmt' => 'number_format',
                    'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['path'] . '  ·  Regional open data, updated monthly'];
        }],
        'omny' => ['share.jpg', function () {
            $f = mta_nt_omny_facts();
            if (!$f) {
                return null;
            }
            $bars = [];
            foreach (array_slice($f['sub'], -60, 60, true) as $m => $v) {
                if (substr($m, 5, 2) === '06') {
                    $bars['June ' . substr($m, 0, 4)] = $v;
                }
            }
            $bars[gmdate('F Y', strtotime($f['month'] . '-01 UTC'))] = $f['sub_now'];
            return ['title' => 'OMNY Fare Cap & Guide', 'subtitle' => '$' . $f['p']['cap'] . ' a week, then the rest of your rides are free', 'stats' => [[$f['sub_now'] . '%', 'of subway rides'],
                    ['$' . $f['p']['cap'], 'weekly fare cap']], 'bars_title' => 'Subway rides paid with OMNY', 'bars' => mta_tm_card_bars($bars), 'fmt' => 'mta_tm_card_pct', 'top' => 100,
                    'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['omny'] . '  ·  MTA open data, updated monthly'];
        }],
        'tolls' => ['share.jpg', function () {
            $bars = [];
            foreach (mta_nt_tolls() as $name => $t) {
                $bars[$name] = (float) $t['car'][0];
            }
            arsort($bars);
            return ['title' => 'NYC Bridge & Tunnel Tolls', 'subtitle' => 'Every MTA crossing, 2026 prices', 'stats' => [['$7.46', 'car, NY E-ZPass'], ['$12.03', 'car, by mail']],
                    'bars_title' => 'Car toll with NY E-ZPass', 'bars' => mta_tm_card_bars($bars), 'fmt' => function ($v) { return '$' . number_format($v, 2); },
                    'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['tolls'] . '  ·  mta.info, January 2026 rates'];
        }],
    ];
});
add_filter('mta_share_page_urls', function ($urls, $card) {
    return isset(MTA_NT_PAGES[$card]) ? [mta_nt_url($card)] : $urls;
}, 10, 2);
add_action('init', function () {
    foreach (array_keys(MTA_NT_PAGES) as $k) {
        mta_ridership_share_image(function () use ($k) { return mta_nt_live() && is_page(MTA_NT_PAGES[$k]); }, $k);
    }
});
