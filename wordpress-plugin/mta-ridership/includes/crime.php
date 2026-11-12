<?php
/**
 * Subway crime page (/subway-crime/, [mta_crime]) and a short teaser on the ridership page: the MTA's count of the
 * seven major felonies (murder, rape, robbery, felony assault, burglary, grand larceny, grand larceny auto) in NYC
 * Transit, per month and per million riders, with the LIRR, Metro-North and Staten Island Railway for comparison.
 * Data (data.ny.gov): MTA Major Felonies (yeek-jhmu), monthly, about 3-4 weeks after the month ends.
 * The whole dataset is small (one row per month, agency and felony type), so the step fetches all of it.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_CRIME_HOOK   = 'mta_ridership_crime_step';
const MTA_CRIME_OPTION = 'mta_ridership_crime';
const MTA_CRIME_SLUG   = 'subway-crime';

add_action(MTA_CRIME_HOOK, function () { mta_ridership_run_step('crime'); });

add_action('init', function () {
    if (!get_option(MTA_CRIME_OPTION) && get_option(MTA_LINES_OPTION)) {
        mta_crime_schedule(120);
    }
});

/** The crime page and its teaser are live (since 2026-11-03); return mta_ridership_preview() to put them back in review (the page is then a 404 without ?mta_preview=1). */
function mta_crime_live()
{
    return true;
}

/** The extra crime numbers (day, month, borough, line, victims, misdemeanors, staff, fares) and crime on station and line pages are live (since 2026-11-03); return mta_ridership_preview() to put them back in review. */
function mta_crime_more_live()
{
    return true;
}

function mta_crime_page()
{
    return get_page_by_path(MTA_CRIME_SLUG, OBJECT, 'page');
}

function mta_crime_url()
{
    return home_url('/' . MTA_CRIME_SLUG . '/');
}

add_action('template_redirect', function () {
    if (is_page(MTA_CRIME_SLUG) && !mta_crime_live()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    }
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    $p = mta_crime_page();
    return $p && !mta_crime_live() ? array_merge((array) $ids, [$p->ID]) : $ids;
});
add_action('init', function () {
    mta_ridership_share_image(function () { return (bool) mta_crime_seo(); }, 'crime');
});
add_filter('wp_robots', function ($r) {
    return is_page(MTA_CRIME_SLUG) && mta_ridership_preview() ? ['noindex' => true, 'nofollow' => true] + $r : $r;
});

function mta_crime_schedule($delay)
{
    if (!wp_next_scheduled(MTA_CRIME_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_CRIME_HOOK);
    }
}

function mta_crime_step()
{
    try {
        $rows = mta_ridership_api('https://data.ny.gov/resource/yeek-jhmu.json', [
            '$select' => 'month, agency, felony_type, felony_count, crimes_per_million_riders', '$limit' => 50000]);
        $s = mta_crime_summary($rows);
        if (!$s) {
            throw new Exception('No major felony data returned');
        }
        // The NYPD's own records add the time and station. If they fail, keep last month's copy.
        try {
            $s['nypd'] = mta_crime_nypd_fetch();
        } catch (Throwable $e) {
            error_log('[mta-ridership] NYPD subway crime data failed: ' . $e->getMessage());
        }
        $old = get_option(MTA_CRIME_OPTION);
        if (empty($s['nypd'])) {
            $s['nypd'] = $old['nypd'] ?? null;
        }
        // Attacks on staff, police summonses and arrests, and fare evasion: each kept from last month if it fails.
        foreach (['workers' => 'mta_crime_workers_fetch', 'police' => 'mta_crime_police_fetch', 'fares' => 'mta_crime_fares_fetch'] as $k => $fn) {
            try {
                $s[$k] = $fn();
            } catch (Throwable $e) {
                error_log("[mta-ridership] subway crime $k data failed: " . $e->getMessage());
            }
            if (empty($s[$k])) {
                $s[$k] = $old[$k] ?? null;
            }
        }
        update_option(MTA_CRIME_OPTION, ['updated' => gmdate('c')] + $s, false);
        delete_option('mta_ridership_crime_error');
        if (function_exists('mta_more_schedule')) {
            mta_more_schedule(600);
        }
        if (function_exists('rocket_clean_post')) {
            foreach ([mta_crime_page(), get_page_by_path('mta-ridership', OBJECT, 'page')] as $p) {
                if ($p) {
                    rocket_clean_post($p->ID);
                }
            }
            // Station and line pages show crime too, so they refresh with it.
            $urls = [];
            if (mta_crime_more_live() && function_exists('rocket_clean_files')) {
                foreach (function_exists('mta_stations_all') ? mta_stations_all() : [] as $st) {
                    $urls[] = mta_stations_url($st['slug']);
                }
                foreach (function_exists('mta_lines_list') ? mta_lines_list() : [] as [$slug]) {
                    $urls[] = mta_lines_url($slug);
                }
                rocket_clean_files($urls);
            }
        }
    } catch (Throwable $e) {
        update_option('mta_ridership_crime_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_CRIME_HOOK, 'mta_crime_schedule');
    }
}

/** NYC Open Data query (no app token: the site's token is only for data.ny.gov). */
function mta_crime_city_api($id, $query)
{
    $res = wp_remote_get(add_query_arg(['$query' => rawurlencode($query)], "https://data.cityofnewyork.us/resource/$id.json"), ['timeout' => 120]);
    if (is_wp_error($res)) {
        throw new Exception($res->get_error_message());
    }
    if (wp_remote_retrieve_response_code($res) !== 200) {
        throw new Exception('HTTP ' . wp_remote_retrieve_response_code($res) . ': ' . substr(wp_remote_retrieve_body($res), 0, 300));
    }
    return json_decode(wp_remote_retrieve_body($res), true);
}

/**
 * The NYPD's subway complaints for the seven major felonies over the last 12 full months it has published, from
 * NYPD Complaint Data Historic (qgea-i56i, earlier years) and Current Year To Date (5uac-w243, updated quarterly):
 * counts by hour and by station location, matched to the MTA's station complexes.
 */
function mta_crime_nypd_fetch()
{
    $base = "prem_typ_desc='TRANSIT - NYC SUBWAY' AND ky_cd IN ('101','104','105','106','107','109','110')";
    $max = mta_crime_city_api('5uac-w243', "SELECT max(cmplnt_fr_dt) AS d WHERE $base");
    $d = substr($max[0]['d'] ?? '', 0, 10);
    if (!$d) {
        return null;
    }
    // A month counts once its last days are in.
    $latest = (int) substr($d, 8, 2) >= 28 ? substr($d, 0, 7) : gmdate('Y-m', strtotime(substr($d, 0, 7) . '-01 -1 month UTC'));
    $from = gmdate('Y-m', strtotime("$latest-01 -11 month UTC"));
    $to = gmdate('Y-m-01', strtotime("$latest-01 +1 month UTC"));
    $w = "$base AND cmplnt_fr_dt >= '$from-01' AND cmplnt_fr_dt < '$to'";
    $wm = "prem_typ_desc='TRANSIT - NYC SUBWAY' AND law_cat_cd='MISDEMEANOR' AND cmplnt_fr_dt >= '$from-01' AND cmplnt_fr_dt < '$to'";
    $st = $tm = $dow = $boro = $vic = $misd = [];
    foreach (['qgea-i56i', '5uac-w243'] as $id) { // each complaint is in one of the two, by the year it was reported
        $st = array_merge($st, mta_crime_city_api($id, "SELECT station_name, latitude, longitude, ky_cd, count(*) AS n WHERE $w GROUP BY station_name, latitude, longitude, ky_cd LIMIT 50000"));
        $tm = array_merge($tm, mta_crime_city_api($id, "SELECT cmplnt_fr_tm AS t, count(*) AS n WHERE $w GROUP BY t LIMIT 50000"));
        $dow = array_merge($dow, mta_crime_city_api($id, "SELECT date_extract_dow(cmplnt_fr_dt) AS d, count(*) AS n WHERE $w GROUP BY d"));
        $boro = array_merge($boro, mta_crime_city_api($id, "SELECT boro_nm AS b, count(*) AS n WHERE $w GROUP BY b"));
        $vic = array_merge($vic, mta_crime_city_api($id, "SELECT vic_sex AS s, vic_age_group AS a, count(*) AS n WHERE $w GROUP BY s, a LIMIT 5000"));
        $misd = array_merge($misd, mta_crime_city_api($id, "SELECT ofns_desc AS o, count(*) AS n WHERE $wm GROUP BY o LIMIT 5000"));
    }
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    return ['from' => $from, 'latest' => $latest] + mta_crime_nypd_summary($st, $tm, $x['stations'] ?? []) + mta_crime_nypd_more($dow, $boro, $vic, $misd);
}

/**
 * The rest of the NYPD numbers: felonies by day of the week (0 = Sunday) and borough, the victims of those felonies
 * (men and women, age groups; businesses and the state count as "not a person"), and misdemeanors by offense.
 */
function mta_crime_nypd_more(array $dow_rows, array $boro_rows, array $vic_rows, array $misd_rows)
{
    $add = function (array $rows, $key, $map = null) {
        $o = [];
        foreach ($rows as $r) {
            $k = $r[$key] ?? '';
            $k = $map ? $map($k) : $k;
            if ($k !== null && $k !== '' && $k !== '(null)') {
                $o[$k] = ($o[$k] ?? 0) + (int) $r['n'];
            }
        }
        return $o;
    };
    $dow = array_fill(0, 7, 0);
    foreach ($add($dow_rows, 'd') as $d => $n) {
        if ($d >= 0 && $d < 7) {
            $dow[(int) $d] += $n;
        }
    }
    $boro = $add($boro_rows, 'b', function ($b) { return ucwords(strtolower($b)); });
    arsort($boro);
    $sex = $add($vic_rows, 's', function ($s) { return ['M' => 'men', 'F' => 'women'][$s] ?? 'other'; });
    $ages = ['<18' => 'Under 18', '18-24' => '18 – 24', '25-44' => '25 – 44', '45-64' => '45 – 64', '65+' => '65 and over'];
    $age = array_fill_keys(array_values($ages), 0);
    $age_unknown = 0;
    foreach ($vic_rows as $r) {
        $a = $r['a'] ?? '';
        if (isset($ages[$a])) {
            $age[$ages[$a]] += (int) $r['n'];
        } else {
            $age_unknown += (int) $r['n'];
        }
    }
    $misd = $add($misd_rows, 'o');
    arsort($misd);
    return ['dow' => $dow, 'boro' => $boro, 'victims' => ['sex' => $sex, 'age' => $age, 'age_unknown' => $age_unknown], 'misd' => $misd];
}

/**
 * Monthly rows (month, a category, a total) → the last 12 months and the 12 before, per category, and the total for
 * each full calendar year. Months are the dataset's own; its latest month ends the 12.
 */
function mta_crime_monthly_summary(array $rows, $cat, $val = 'total')
{
    $by = [];
    foreach ($rows as $r) {
        $m = substr($r['month'] ?? '', 0, 7);
        if ($m) {
            $by[$m][$r[$cat] ?? 'Other'] = ($by[$m][$r[$cat] ?? 'Other'] ?? 0) + (int) round((float) ($r[$val] ?? 0));
        }
    }
    if (!$by) {
        return null;
    }
    ksort($by);
    $latest = array_key_last($by);
    $month = function ($k) use ($latest) { return gmdate('Y-m', strtotime("$latest-01 -$k month UTC")); };
    $from = $month(11);
    $prev = $month(23);
    $out = ['from' => $from, 'latest' => $latest, 'year' => [], 'before' => [], 'years' => []];
    $last_full = (int) substr($latest, 5, 2) === 12 ? (int) substr($latest, 0, 4) : (int) substr($latest, 0, 4) - 1;
    foreach ($by as $m => $cats) {
        $part = $m >= $from ? 'year' : ($m >= $prev ? 'before' : null);
        foreach ($cats as $c => $n) {
            if ($part) {
                $out[$part][$c] = ($out[$part][$c] ?? 0) + $n;
            }
            $y = (int) substr($m, 0, 4);
            if ($y <= $last_full) {
                $out['years'][$y] = ($out['years'][$y] ?? 0) + $n;
            }
        }
    }
    arsort($out['year']);
    return $out;
}

/** Attacks and threats against subway and Staten Island Railway staff (MTA Workplace Violence Penal Law Incidents, 2xh4-m2qk). */
function mta_crime_workers_fetch()
{
    return mta_crime_monthly_summary(mta_ridership_api('https://data.ny.gov/resource/2xh4-m2qk.json', [
        '$select' => 'month, measure, total', '$where' => "agency='NYCT Subway/SIR'", '$limit' => 50000]), 'measure');
}

/** NYPD summonses and arrests in the subway (MTA Summonses and Arrests, 7tfn-twae). */
function mta_crime_police_fetch()
{
    return mta_crime_monthly_summary(mta_ridership_api('https://data.ny.gov/resource/7tfn-twae.json', [
        '$select' => 'month, metric, total', '$where' => "agency='NYCT Subway' AND police_force='NYPD'", '$limit' => 50000]), 'metric');
}

/** The MTA's estimate of the share of subway riders who did not pay, by quarter (6kj3-ijvb): ['2026-Q3' => 10.8, ...]. */
function mta_crime_fares_fetch()
{
    $out = [];
    foreach (mta_ridership_api('https://data.ny.gov/resource/6kj3-ijvb.json', ['$select' => 'time_period, fare_evasion', '$limit' => 1000]) as $r) {
        if (!empty($r['time_period']) && isset($r['fare_evasion'])) {
            $out[$r['time_period']] = round((float) $r['fare_evasion'] * 100, 1);
        }
    }
    ksort($out);
    return $out ?: null;
}

/**
 * NYPD rows → felonies by hour of the day, riders by hour (an average day, all stations), and felonies by MTA
 * station complex: each NYPD station location goes to the nearest complex within 400 m.
 */
function mta_crime_nypd_summary(array $st_rows, array $tm_rows, array $stations)
{
    $hours = array_fill(0, 24, 0);
    foreach ($tm_rows as $r) {
        $h = (int) substr($r['t'] ?? '', 0, 2);
        if ($h >= 0 && $h < 24) {
            $hours[$h] += (int) $r['n'];
        }
    }
    $riders = array_fill(0, 24, 0);
    foreach ($stations as $s) {
        foreach ((array) ($s['hours'] ?? []) as $h => $v) {
            $riders[$h] += (int) $v;
        }
    }
    $by = [];
    $types = []; // [station id][NYPD offense code] => n
    $total = 0;
    $unmatched = 0;
    foreach ($st_rows as $r) {
        $n = (int) $r['n'];
        $total += $n;
        $la = (float) ($r['latitude'] ?? 0);
        $lo = (float) ($r['longitude'] ?? 0);
        $best = null;
        $bd = INF;
        foreach ($la ? $stations : [] as $s) {
            $dx = ($s['lon'] - $lo) * cos(deg2rad(($s['lat'] + $la) / 2));
            $dd = hypot($dx, $s['lat'] - $la) * 111320; // metres
            if ($dd < $bd) {
                $bd = $dd;
                $best = $s['id'];
            }
        }
        if ($best === null || $bd > 400) {
            $unmatched += $n;
            continue;
        }
        $by[$best] = ($by[$best] ?? 0) + $n;
        if (!empty($r['ky_cd'])) {
            $types[$best][$r['ky_cd']] = ($types[$best][$r['ky_cd']] ?? 0) + $n;
        }
    }
    arsort($by);
    foreach ($types as &$t) {
        arsort($t);
    }
    unset($t);
    return ['total' => $total, 'unmatched' => $unmatched, 'hours' => $hours, 'riders' => $riders, 'stations' => $by, 'types' => $types];
}

/**
 * Rows of the MTA Major Felonies dataset → per agency: the last 12 months and the 12 before (count, rate per
 * million riders, by felony type) and, for NYC Transit, every calendar year. The rate is total felonies over
 * total riders, where a month's riders come from the MTA's own rate (count / rate).
 */
function mta_crime_summary(array $rows)
{
    $by = []; // [agency][Y-m] => ['n' => total, 'r' => sum of the per-type rates, 't' => [type => n]]
    $latest = '';
    foreach ($rows as $r) {
        $m = substr($r['month'] ?? '', 0, 7);
        $a = $r['agency'] ?? '';
        if (!$m || !$a) {
            continue;
        }
        $n = (int) ($r['felony_count'] ?? 0);
        $x = &$by[$a][$m];
        $x['n'] = ($x['n'] ?? 0) + $n;
        $x['r'] = ($x['r'] ?? 0) + (float) ($r['crimes_per_million_riders'] ?? 0);
        $x['t'][$r['felony_type'] ?? 'Other'] = ($x['t'][$r['felony_type'] ?? 'Other'] ?? 0) + $n;
        unset($x);
        $latest = max($latest, $m);
    }
    if (!$latest) {
        return null;
    }
    $month = function ($k) use ($latest) { return gmdate('Y-m', strtotime("$latest-01 -$k month UTC")); };
    $from = $month(11);
    $prev_from = $month(23);
    $prev_to = $month(12);
    // Count, riders (millions) and types over months in [$a, $b]. Months with no felonies have no rate, so no riders.
    $span = function ($months, $a, $b) {
        $o = ['n' => 0, 'riders' => 0.0, 'months' => 0, 't' => []];
        foreach ($months as $m => $x) {
            if ($m < $a || $m > $b) {
                continue;
            }
            $o['n'] += $x['n'];
            $o['months']++;
            if ($x['r'] > 0) {
                $o['riders'] += $x['n'] / $x['r'];
            }
            foreach ($x['t'] as $t => $n) {
                $o['t'][$t] = ($o['t'][$t] ?? 0) + $n;
            }
        }
        $o['rate'] = $o['riders'] > 0 ? round($o['n'] / $o['riders'], 2) : null;
        unset($o['riders']);
        arsort($o['t']);
        return $o;
    };
    $out = ['latest' => $latest, 'from' => $from, 'prev' => [$prev_from, $prev_to], 'agencies' => []];
    foreach ($by as $a => $months) {
        ksort($months);
        $out['agencies'][$a] = ['year' => $span($months, $from, $latest), 'before' => $span($months, $prev_from, $prev_to)];
    }
    if (isset($by['NYCT'])) {
        $years = [];
        $first = (int) substr(array_key_first($by['NYCT']), 0, 4);
        $last_full = (int) substr($latest, 5, 2) === 12 ? (int) substr($latest, 0, 4) : (int) substr($latest, 0, 4) - 1;
        for ($y = $first; $y <= $last_full; $y++) {
            $s = $span($by['NYCT'], "$y-01", "$y-12");
            $years[$y] = ['n' => $s['n'], 'rate' => $s['rate']];
        }
        $out['years'] = $years;
        $out['monthly'] = array_map(function ($x) { return $x['n']; }, array_slice($by['NYCT'], -36, null, true));
    }
    return $out;
}

function mta_crime_types()
{
    return ['Grand Larceny' => 'Grand larceny (theft over $1,000)', 'Felony Assault' => 'Felony assault', 'Robbery' => 'Robbery',
            'Burglary' => 'Burglary', 'Rape' => 'Rape', 'Murder' => 'Murder', 'Grand Larceny Auto' => 'Car theft'];
}

/** The numbers the page, the teaser and the FAQ share, or null if there is no data yet. */
function mta_crime_facts()
{
    $d = get_option(MTA_CRIME_OPTION);
    $t = $d['agencies']['NYCT'] ?? null;
    if (!$t || !$t['year']['n']) {
        return null;
    }
    $mon = function ($ym) { return gmdate('M Y', strtotime("$ym-01 UTC")); };
    $y = $t['year'];
    $b = $t['before'];
    return ['d' => $d, 'y' => $y, 'b' => $b, 'range' => $mon($d['from']) . ' – ' . $mon($d['latest']),
            'chg' => $b['n'] ? round(($y['n'] - $b['n']) / $b['n'] * 100, 1) : null];
}

/** "7.9% more than" / "3.1% fewer than" the 12 months before. */
function mta_crime_change_text($chg)
{
    return $chg === null ? '' : ($chg <= 0 ? abs($chg) . '% fewer' : $chg . '% more') . ' than the 12 months before';
}

/** About how many major felonies a day, from a count over some months. */
function mta_crime_per_day($n, $months)
{
    return max(1, (int) round($n / max(1, $months * 365 / 12)));
}

/**
 * The NYPD numbers ready to show, or null before the first NYPD run: felonies for five parts of the day (with their
 * share of felonies and of rides), and per station (name, slug, riders a day).
 */
function mta_crime_nypd_view($d)
{
    $n = $d['nypd'] ?? null;
    if (empty($n['total']) || empty(array_sum($n['riders'] ?? []))) {
        return null;
    }
    $parts = [[0, 4, 'Midnight – 5 AM'], [5, 9, '5 – 10 AM'], [10, 15, '10 AM – 4 PM'], [16, 19, '4 – 8 PM'], [20, 23, '8 PM – midnight']];
    $hrs = array_sum($n['hours']);
    $rides = array_sum($n['riders']);
    $periods = [];
    foreach ($parts as [$a, $b, $label]) {
        $c = array_sum(array_slice($n['hours'], $a, $b - $a + 1));
        $r = array_sum(array_slice($n['riders'], $a, $b - $a + 1));
        $periods[] = ['label' => $label, 'n' => $c, 'crime_share' => round($c / max(1, $hrs) * 100), 'ride_share' => round($r / $rides * 100)];
    }
    $all = function_exists('mta_stations_all') ? mta_stations_all() : [];
    $stations = [];
    foreach ($n['stations'] as $id => $c) {
        $s = $all[$id] ?? null;
        $day = $s && $s['hours'] ? array_sum($s['hours']) : 0;
        if (!$s || !$day) {
            continue;
        }
        $stations[] = ['id' => $id, 'n' => $c, 'name' => mta_stations_display($s['name']), 'lines' => implode(',', mta_stations_lines_of($s['name'])),
                       'slug' => $s['slug'], 'day' => $day, 'types' => $n['types'][$id] ?? []];
    }
    $mon = function ($ym) { return gmdate('M Y', strtotime("$ym-01 UTC")); };
    return ['range' => $mon($n['from']) . ' – ' . $mon($n['latest']), 'total' => $n['total'], 'periods' => $periods, 'stations' => $stations,
            'night' => $periods[0], 'day' => $periods[2], 'dow' => $n['dow'] ?? null, 'boro' => $n['boro'] ?? null, 'victims' => $n['victims'] ?? null, 'misd' => $n['misd'] ?? null];
}

/** "20 thefts, 15 assaults, 4 robberies" from NYPD offense codes => counts (biggest first, at most $max kinds). */
function mta_crime_type_words(array $types, $max = 3)
{
    $names = ['101' => ['murder', 'murders'], '104' => ['rape', 'rapes'], '105' => ['robbery', 'robberies'], '106' => ['assault', 'assaults'],
              '107' => ['burglary', 'burglaries'], '109' => ['theft', 'thefts'], '110' => ['car theft', 'car thefts']];
    $out = [];
    foreach (array_slice($types, 0, $max, true) as $k => $n) {
        if (isset($names[$k])) {
            $out[] = number_format($n) . ' ' . $names[$k][$n === 1 ? 0 : 1];
        }
    }
    return implode(', ', $out);
}

/** A plainer name for an NYPD misdemeanor offense. */
function mta_crime_misd_name($o)
{
    $names = ['OTHER OFFENSES RELATED TO THEFT' => 'Fare evasion and selling swipes', 'DANGEROUS DRUGS' => 'Drugs', 'ASSAULT 3 & RELATED OFFENSES' => 'Assault (less serious)',
              'OFFENSES AGAINST PUBLIC ADMINI' => 'Obstructing police or officials', 'CRIMINAL TRESPASS' => 'Trespassing', 'FRAUDS' => 'Fraud',
              'CRIMINAL MISCHIEF & RELATED OF' => 'Vandalism', 'PETIT LARCENY' => 'Petty theft (under $1,000)', 'DANGEROUS WEAPONS' => 'Weapons',
              'SEX CRIMES' => 'Sex crimes', 'OFF. AGNST PUB ORD SENSBLTY &' => 'Public order offenses', 'OFFENSES AGAINST THE PERSON' => 'Other offenses against a person'];
    return $names[$o] ?? ucfirst(strtolower($o));
}

/**
 * Felonies at the stations each line stops at, from the NYPD station counts: [line code => ['n', 'name', 'slug',
 * 'top' => three busiest-for-crime stations]], most first. A station served by several lines counts for each.
 */
function mta_crime_lines($v)
{
    if (!$v || !function_exists('mta_lines_list') || !function_exists('mta_stations_for_line')) {
        return [];
    }
    $by_id = [];
    foreach ($v['stations'] as $s) {
        $by_id[$s['id']] = $s;
    }
    $out = [];
    foreach (mta_lines_list() as $code => [$slug, $name]) {
        $n = 0;
        $list = [];
        foreach (mta_stations_for_line($code) as $boro) {
            foreach ($boro as $st) {
                if (isset($by_id[$st['id']])) {
                    $n += $by_id[$st['id']]['n'];
                    $list[] = $by_id[$st['id']];
                }
            }
        }
        if ($n) {
            usort($list, function ($a, $b) { return $b['n'] <=> $a['n']; });
            $out[$code] = ['n' => $n, 'name' => $name, 'slug' => $slug, 'stations' => count($list), 'top' => array_slice($list, 0, 3)];
        }
    }
    uasort($out, function ($a, $b) { return $b['n'] <=> $a['n']; });
    return $out;
}

/** "Crime at this station" for a station page (in review), or ''. */
function mta_crime_station_section($st, $sec)
{
    $f = mta_crime_more_live() ? mta_crime_facts() : null;
    $v = $f ? mta_crime_nypd_view($f['d']) : null;
    if (!$v) {
        return '';
    }
    $name = mta_stations_display($st['name']);
    $rank = 0;
    $me = null;
    foreach ($v['stations'] as $i => $s) {
        if ((string) $s['id'] === (string) $st['id']) {
            $me = $s;
            $rank = $i + 1;
            break;
        }
    }
    $link = ' <a href="' . esc_url(mta_crime_url()) . '">Subway crime across the city &rarr;</a>';
    if (!$me) {
        $body = '<p>' . esc_html('From ' . $v['range'] . ', none of the NYPD\'s reports of major felonies in the subway were matched to ' . $name . '.') . $link . '</p>';
    } else {
        $words = mta_crime_type_words($me['types'], 7);
        $body = '<p>' . esc_html('From ' . $v['range'] . ', ' . number_format($me['n']) . ' major felon' . ($me['n'] === 1 ? 'y was' : 'ies were') . ' reported to the NYPD at ' .
            $name . ($words ? ': ' . $words : '') . '. That is #' . $rank . ' of the ' . count($v['stations']) . ' stations with any, out of ' .
            number_format($v['total']) . ' in the whole subway.') . $link . '</p>';
    }
    return $sec('Crime at ' . $name, $body, 'NYPD complaint reports of the seven major felonies (murder, rape, robbery, felony assault, burglary, grand larceny and car theft), ' .
        'matched to the nearest station by location.');
}

/** "Crime on the A train" for a line page (in review), or ''. */
function mta_crime_line_section($l, $sec)
{
    $f = mta_crime_more_live() ? mta_crime_facts() : null;
    $v = $f ? mta_crime_nypd_view($f['d']) : null;
    $all = mta_crime_lines($v);
    if (!isset($all[$l['code']])) {
        return '';
    }
    $x = $all[$l['code']];
    $rank = array_search($l['code'], array_keys($all), true) + 1;
    $top = implode(', ', array_map(function ($s) { return $s['name'] . ' (' . $s['n'] . ')'; }, $x['top']));
    $body = '<p>' . esc_html('From ' . $v['range'] . ', ' . number_format($x['n']) . ' major felonies were reported to the NYPD at the ' . $x['stations'] . ' stations on the ' .
        $l['name'] . ' with any, #' . $rank . ' of ' . count($all) . ' lines. The most were at ' . $top . '.') .
        ' <a href="' . esc_url(mta_crime_url()) . '">Subway crime by line and station &rarr;</a></p>';
    return $sec('Crime at ' . $l['name'] . ' stations', $body, 'NYPD complaint reports of the seven major felonies at the stations the line stops at. ' .
        'Reports are matched to the nearest station by location, and a station served by several lines counts for each of them.');
}

function mta_crime_faq($f)
{
    $y = $f['y'];
    $names = mta_crime_types();
    $top = array_key_first($y['t']);
    $top_name = ucfirst(strtolower($names[$top] ?? $top));
    $years = $f['d']['years'] ?? [];
    $v = mta_crime_nypd_view($f['d']);
    $out = [
        ['Is the NYC subway safe?', 'From ' . $f['range'] . ' the MTA counted ' . number_format($y['n']) . ' major felonies in NYC Transit, about ' .
            mta_crime_per_day($y['n'], $y['months']) . ' a day across the whole subway. ' . $top_name . ' was the most common (' . number_format($y['t'][$top]) . ').'],
    ];
    if ($v) {
        $out[] = ['Is the NYC subway safe at night?', 'From ' . $v['range'] . ', ' . number_format($v['night']['n']) . ' of the NYPD\'s ' . number_format($v['total']) .
            ' subway felony reports (' . $v['night']['crime_share'] . '%) were between midnight and 5 AM, when only ' . $v['night']['ride_share'] . '% of rides happen. ' .
            number_format($v['day']['n']) . ' were between 10 AM and 4 PM.'];
        $most = array_slice($v['stations'], 0, 3);
        if ($most) {
            $out[] = ['Which NYC subway stations have the most crime?', 'By number of major felonies reported to the NYPD (' . $v['range'] . '): ' .
                implode(', ', array_map(function ($s) { return $s['name'] . ' (' . $s['n'] . ')'; }, $most)) . '. These are among the busiest stations.'];
        }
        $lines = mta_crime_more_live() ? mta_crime_lines($v) : [];
        if ($lines) {
            $out[] = ['Which NYC subway line has the most crime?', 'Counting the major felonies reported to the NYPD at every station a line stops at (' . $v['range'] . '): ' .
                implode(', ', array_map(function ($x) { return 'the ' . $x['name'] . ' (' . number_format($x['n']) . ')'; }, array_slice($lines, 0, 3))) .
                '. Long lines through busy stations count the most.'];
        }
        if (mta_crime_more_live() && !empty($v['dow']) && array_sum($v['dow'])) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $hi = array_search(max($v['dow']), $v['dow'], true);
            $lo = array_search(min($v['dow']), $v['dow'], true);
            $out[] = ['What day has the most crime on the NYC subway?', $days[$hi] . ', with ' . number_format($v['dow'][$hi]) . ' major felonies reported to the NYPD from ' . $v['range'] .
                '. ' . $days[$lo] . ' had the fewest (' . number_format($v['dow'][$lo]) . ').'];
        }
    }
    $list = [];
    foreach ($years as $yr => $x) {
        $list[] = number_format($x['n']) . ' in ' . $yr;
    }
    $out[] = ['Is subway crime going up or down?', 'Major felonies were ' . mta_crime_change_text($f['chg']) . ' (' . number_format($f['b']['n']) . ').' .
        ($list ? ' By year: ' . implode(', ', $list) . ', and ' . number_format($y['n']) . ' in the last 12 months.' : '')];
    $out[] = ['What is the most common crime on the subway?', $top_name . ': ' . number_format($y['t'][$top]) . ' of the ' . number_format($y['n']) .
        ' major felonies (' . round($y['t'][$top] / $y['n'] * 100) . '%) in the last 12 months.'];
    $out[] = ['Is the NYC subway safe for tourists?', 'Millions of people ride every day, and the MTA counted about ' . mta_crime_per_day($y['n'], $y['months']) .
        ' major felonies a day across the whole subway in the last 12 months. The most common was ' . strtolower($names[$top] ?? $top) . '.' .
        ($v ? ' A large share happen between midnight and 5 AM, when trains and stations are emptiest.' : '')];
    return $out;
}

/** A short pointer to the crime page for the ridership page, or '' if there is no data yet. */
function mta_crime_section()
{
    $f = mta_crime_facts();
    if (!$f) {
        return '';
    }
    $h = mta_ridership_h();
    return '<section class="mta-section"><' . $h . '>Subway crime</' . $h . '><p>From ' . esc_html($f['range']) . ' the MTA counted ' . number_format($f['y']['n']) .
        ' major felonies in NYC Transit, ' . esc_html(mta_crime_change_text($f['chg'])) . '. <a href="' . esc_url(mta_crime_url()) .
        '">Is the NYC subway safe? See crime by year, time of day and station &rarr;</a></p></section>';
}

add_shortcode('mta_crime', function () {
    if (!mta_crime_live()) {
        return '';
    }
    $f = mta_crime_facts();
    if (!$f) {
        mta_crime_schedule(5);
        return '<p>Subway crime data is loading. Check back in a few minutes.</p>';
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.18.1');
    $d = $f['d'];
    $y = $f['y'];
    $b = $f['b'];
    $chg = $f['chg'];
    $range = esc_html($f['range']);
    $v = mta_crime_nypd_view($d);
    $per_day = mta_crime_per_day($y['n'], $y['months']);
    $more = mta_crime_more_live();
    $tile = function ($big, $small) {
        return '<div class="mta-tile"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>';
    };
    $h3 = function ($t) { return '<' . mta_ridership_h(true) . '>' . esc_html($t) . '</' . mta_ridership_h(true) . '>'; };
    $sec = function ($title, $body, $note = '') {
        return '<section class="mta-section"><' . mta_ridership_h() . '>' . esc_html($title) . '</' . mta_ridership_h() . '>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
    };
    // [name (plain text or ['html' => link]), bar value, number, small line]
    $bars = function ($items) {
        $max = max(1, max(array_column($items, 1)));
        $o = '';
        foreach ($items as [$name, $val, $num, $sub]) {
            $o .= '<li><div class="mta-top-row"><span class="mta-top-name">' . (is_array($name) ? $name['html'] : esc_html($name)) . ($sub ? '<br><small class="mta-note">' . esc_html($sub) . '</small>' : '') .
                '</span><span class="mta-top-num">' . esc_html($num) . '</span></div><div class="mta-bar"><span style="width:' . round($val / $max * 100, 1) . '%"></span></div></li>';
        }
        return '<ul class="mta-top" style="list-style:none;padding-left:0">' . $o . '</ul>';
    };
    $station = function ($s) {
        return ['html' => '<a href="' . esc_url(mta_stations_url($s['slug'])) . '">' . esc_html($s['name']) . '</a> <span class="mta-lines">' . esc_html($s['lines']) . '</span>'];
    };

    $o = '<header class="mta-hero"><h1>Is the NYC Subway Safe? NYC Subway Crime Statistics</h1>' .
        '<p class="mta-hero-links">Major felonies from MTA and NYPD open data, ' . $range . ', updated monthly · Not affiliated with the MTA</p><div class="mta-tiles">' .
        $tile(number_format($y['n']), 'major felonies in 12 months') . $tile('About ' . $per_day, 'a day across the subway') .
        ($chg === null ? '' : $tile(($chg > 0 ? '+' : '') . $chg . '%', 'vs the 12 months before')) . '</div></header>';

    $o .= $sec('How much crime is there on the NYC subway?',
        '<p>From ' . $range . ' the MTA counted <strong>' . number_format($y['n']) . ' major felonies</strong> in NYC Transit, <strong>about ' . $per_day .
        ' a day</strong> across the whole subway' .
        ($chg === null ? '.' : '. That is ' . esc_html(mta_crime_change_text($chg)) . ' (' . number_format($b['n']) . ').') . '</p>',
        'The seven major felonies the MTA counts in NYC Transit, which the NYPD patrols: murder, rape, robbery, felony assault, burglary, grand larceny and car theft.');

    if ($v) {
        $items = [];
        foreach ($v['periods'] as $p) {
            $items[] = [$p['label'], $p['n'], number_format($p['n']), $p['crime_share'] . '% of felonies, ' . $p['ride_share'] . '% of rides'];
        }
        $o .= $sec('Is the NYC subway safe at night?',
            '<p>' . esc_html('Between midnight and 5 AM there were ' . number_format($v['night']['n']) . ' major felonies (' . $v['night']['crime_share'] .
                '% of the total), while only ' . $v['night']['ride_share'] . '% of rides happen then. Between 10 AM and 4 PM there were ' . number_format($v['day']['n']) . '.') .
                '</p>' . $h3('Major felonies by time of day') . $bars($items),
            'NYPD complaint reports of the seven major felonies in the subway, ' . esc_html($v['range']) . ', by the time they happened. Share of rides uses the MTA\'s station entries by hour.');
        if ($more && !empty($v['dow']) && array_sum($v['dow'])) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $items = [];
            foreach ([1, 2, 3, 4, 5, 6, 0] as $i) {
                $items[] = [$days[$i], $v['dow'][$i], number_format($v['dow'][$i]), ''];
            }
            $hi = array_search(max($v['dow']), $v['dow'], true);
            $lo = array_search(min($v['dow']), $v['dow'], true);
            $o .= $sec('Which day has the most subway crime?', '<p>' . esc_html($days[$hi] . ' had the most major felonies (' . number_format($v['dow'][$hi]) . ') and ' .
                $days[$lo] . ' the fewest (' . number_format($v['dow'][$lo]) . ').') . '</p>' . $bars($items),
                'NYPD complaint reports of the seven major felonies in the subway, ' . esc_html($v['range']) . ', by the day they happened.');
        }

        $top = array_slice($v['stations'], 0, 10);
        if ($top) {
            $items = [];
            foreach ($top as $s) {
                $items[] = [$station($s), $s['n'], (string) $s['n'], number_format($s['day']) . ' riders a day' . ($more && $s['types'] ? ' · ' . mta_crime_type_words($s['types']) : '')];
            }
            $body = '<p>' . esc_html('The stations with the most major felonies are mostly the busiest ones, where the most people are.') . '</p>' .
                $h3('Most major felonies') . $bars($items);
            $o .= $sec('Which NYC subway stations have the most crime?', $body,
                'NYPD complaint reports of the seven major felonies, ' . esc_html($v['range']) . ', matched to the nearest station by location.');
        }
        $lines = $more ? mta_crime_lines($v) : [];
        if ($lines) {
            $items = [];
            foreach ($lines as $x) {
                $items[] = [['html' => '<a href="' . esc_url(mta_lines_url($x['slug'])) . '">' . esc_html($x['name']) . '</a>'], $x['n'], number_format($x['n']),
                    $x['stations'] . ' stations · most at ' . $x['top'][0]['name'] . ' (' . $x['top'][0]['n'] . ')'];
            }
            $first = reset($lines);
            $o .= $sec('Which subway line has the most crime?', '<p>' . esc_html('Counting the major felonies at every station a line stops at, the ' . $first['name'] .
                ' had the most (' . number_format($first['n']) . '). Long lines through busy stations count the most.') . '</p>' . $bars($items),
                'NYPD complaint reports of the seven major felonies, ' . esc_html($v['range']) . ', at the stations each line stops at. ' .
                'A station served by several lines counts for each of them, so the lines add up to more than the total.');
        }
        if ($more && !empty($v['boro'])) {
            $items = [];
            foreach ($v['boro'] as $bn => $c) {
                $items[] = [$bn, $c, number_format($c), round($c / max(1, array_sum($v['boro'])) * 100) . '% of the total'];
            }
            $o .= $sec('NYC subway crime by borough', $bars($items),
                'NYPD complaint reports of the seven major felonies in the subway, ' . esc_html($v['range']) . '. The Staten Island Railway is not part of these figures.');
        }
    }

    $names = mta_crime_types();
    $items = [];
    foreach ($y['t'] as $type => $n) {
        $p = $b['t'][$type] ?? 0;
        $items[] = [$names[$type] ?? $type, $n, number_format($n), $p ? 'Before: ' . number_format($p) . ' (' . (($n - $p) > 0 ? '+' : '') . round(($n - $p) / $p * 100) . '%)' : ''];
    }
    $o .= $sec('NYC subway crime by type', $bars($items), $range . ', with the 12 months before for comparison.');
    if ($more && $v && !empty($v['misd'])) {
        $mt = array_sum($v['misd']);
        $mk = array_key_first($v['misd']);
        $items = [];
        foreach (array_slice($v['misd'], 0, 10, true) as $k => $c) {
            $items[] = [mta_crime_misd_name($k), $c, number_format($c), ''];
        }
        $o .= $sec('Lesser crimes (misdemeanors)', '<p>' . esc_html('Besides the major felonies, the NYPD took ' . number_format($mt) . ' reports of misdemeanors in the subway from ' .
            $v['range'] . '. The most common was ' . strtolower(mta_crime_misd_name($mk)) . ' (' . round($v['misd'][$mk] / $mt * 100) . '%).') . '</p>' . $bars($items),
            'NYPD complaint reports classed as misdemeanors in the subway: less serious crimes, counted separately from the major felonies.');
    }
    $vi = $more && $v ? ($v['victims'] ?? null) : null;
    if ($vi && array_sum($vi['age'])) {
        $items = [];
        foreach ($vi['age'] as $label => $c) {
            $items[] = [$label, $c, number_format($c), ''];
        }
        $o .= $sec('Who are the victims?', '<p>' . esc_html('Of the major felony reports from ' . $v['range'] . ', ' . number_format($vi['sex']['men'] ?? 0) . ' named a man as the victim and ' .
            number_format($vi['sex']['women'] ?? 0) . ' a woman. The other ' . number_format($vi['sex']['other'] ?? 0) . ' named a business or the state, or did not say.') . '</p>' .
            $h3('Victims by age') . $bars($items),
            'NYPD complaint reports of the seven major felonies in the subway. ' . number_format($vi['age_unknown']) . ' reports gave no age and are left out of the age list.');
    }

    if (!empty($d['years'])) {
        $items = [];
        foreach ($d['years'] as $yr => $x) {
            $items[] = [(string) $yr, $x['n'], number_format($x['n']), ''];
        }
        $items[] = ['Last 12 months', $y['n'], number_format($y['n']), ''];
        $o .= $sec('NYC subway crime by year', $bars($items), 'Major felonies in NYC Transit each full calendar year.');
    }
    if ($more && !empty($d['monthly'])) {
        $vals = $d['monthly'];
        $hi = array_search(max($vals), $vals, true);
        $lo = array_search(min($vals), $vals, true);
        $nice = function ($m) { return gmdate('F Y', strtotime("$m-01 UTC")); };
        $o .= $sec('NYC subway crime by month', '<p>' . esc_html('Over the last ' . count($vals) . ' months, the most major felonies were in ' . $nice($hi) . ' (' . number_format($vals[$hi]) .
            ') and the fewest in ' . $nice($lo) . ' (' . number_format($vals[$lo]) . ').') . '</p>' .
            mta_more_month_chart($vals, 'Major felonies by month, ' . $nice(array_key_first($vals)) . ' to ' . $nice(array_key_last($vals))),
            'Major felonies in NYC Transit each month, from the MTA.');
    }

    $rail = ['LIRR' => 'Long Island Rail Road', 'MNR' => 'Metro-North', 'SIR' => 'Staten Island Railway'];
    $tr = '';
    foreach ($rail as $k => $name) {
        if (isset($d['agencies'][$k])) {
            $x = $d['agencies'][$k];
            $tr .= '<tr><td>' . esc_html($name) . '</td><td>' . number_format($x['year']['n']) . '</td><td>' . number_format($x['before']['n']) . '</td></tr>';
        }
    }
    if ($tr) {
        $o .= $sec('The MTA\'s railroads', '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th></th><th>Last 12 months</th><th>12 months before</th></tr></thead><tbody>' . $tr . '</tbody></table></div>', 'Major felonies on the commuter railroads and the Staten Island Railway, which the MTA Police patrol.');
    }
    $o .= function_exists('mta_more_crime_sections') ? mta_more_crime_sections() : '';
    $mr = function ($x) { return gmdate('M Y', strtotime($x['from'] . '-01 UTC')) . ' – ' . gmdate('M Y', strtotime($x['latest'] . '-01 UTC')); };
    $wk = $more ? ($d['workers'] ?? null) : null;
    if ($wk && array_sum($wk['year'])) {
        $wnames = ['Assault' => 'Assault', 'Harassment' => 'Harassment (such as shoving)', 'Menacing' => 'Menacing (threats)', 'Forcible Touching' => 'Forcible touching'];
        $items = [];
        foreach ($wk['year'] as $k => $c) {
            if ($c) {
                $items[] = [$wnames[$k] ?? $k, $c, number_format($c), 'Before: ' . number_format($wk['before'][$k] ?? 0)];
            }
        }
        $wb = array_sum($wk['before']);
        $o .= $sec('Attacks on subway workers', '<p>' . esc_html('From ' . $mr($wk) . ', the MTA recorded ' . number_format(array_sum($wk['year'])) .
            ' assaults, threats and other attacks on subway and Staten Island Railway workers' . ($wb ? ', compared with ' . number_format($wb) . ' in the 12 months before.' : '.')) . '</p>' . $bars($items),
            'MTA workplace violence reports (incidents that are crimes under New York law) for New York City Transit subway and Staten Island Railway staff.');
    }
    $po = $more ? ($d['police'] ?? null) : null;
    $fa = $more ? ($d['fares'] ?? null) : null;
    if (($po && $po['year']) || $fa) {
        $body = '';
        if ($fa) {
            $q = array_key_last($fa);
            $body .= '<p>' . esc_html('The MTA estimates that ' . $fa[$q] . '% of subway riders did not pay their fare in ' . str_replace('-', ' ', $q) . '.') . '</p>';
            $items = [];
            foreach (array_slice($fa, -8, null, true) as $k => $pct) {
                $items[] = [str_replace('-', ' ', $k), $pct, $pct . '%', ''];
            }
            $body .= $h3('Riders who did not pay, by quarter') . $bars($items);
        }
        if ($po && $po['year']) {
            $pnames = ['Fare Evasion TABS Summons' => 'Fare evasion tickets', 'Fare Evasion Arrests' => 'Fare evasion arrests', 'Fare Evasion Criminal Summons' => 'Fare evasion criminal summonses',
                       'Other Arrests' => 'Other arrests', 'All Other Summons' => 'Other summonses'];
            $tr = '';
            foreach ($po['year'] as $k => $c) {
                $tr .= '<tr><td>' . esc_html($pnames[$k] ?? $k) . '</td><td>' . number_format($c) . '</td><td>' . number_format($po['before'][$k] ?? 0) . '</td></tr>';
            }
            $body .= $h3('NYPD tickets and arrests in the subway, ' . $mr($po)) . '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th></th><th>Last 12 months</th>' .
                '<th>12 months before</th></tr></thead><tbody>' . $tr . '</tbody></table></div>';
        }
        $o .= $sec('Fare evasion and policing', $body, 'The MTA\'s fare evasion estimate comes from staff counting riders who enter without paying at a sample of stations. ' .
            'Tickets and arrests are the NYPD\'s, from the MTA\'s open data.');
    }

    $faq = '';
    foreach (mta_crime_faq($f) as [$q, $a]) {
        $faq .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($a) . '</p></details>';
    }
    $o .= '<section class="mta-section mta-faq"><' . mta_ridership_h() . '>Questions</' . mta_ridership_h() . '>' . $faq . '</section>';
    $o .= mta_ridership_ssr_more('crime');
    $o .= '<p class="mta-source mta-source-ssr">The MTA says its figures count arrests for the seven major felonies; the NYPD figures are complaint reports. Neither is every crime. ' .
        'Data: <a href="https://data.ny.gov/d/yeek-jhmu" rel="noopener">MTA Major Felonies</a> (data.ny.gov); NYPD Complaint Data ' .
        '<a href="https://data.cityofnewyork.us/d/qgea-i56i" rel="noopener">Historic</a> and <a href="https://data.cityofnewyork.us/d/5uac-w243" rel="noopener">Current Year</a> ' .
        '(NYC Open Data, updated every few months); MTA hourly ridership for riders by hour and station' .
        ($more ? '; <a href="https://data.ny.gov/d/2xh4-m2qk" rel="noopener">MTA Workplace Violence Penal Law Incidents</a>, <a href="https://data.ny.gov/d/7tfn-twae" rel="noopener">MTA Summonses and Arrests</a> ' .
            'and <a href="https://data.ny.gov/d/6kj3-ijvb" rel="noopener">MTA NYCT Subway Fare Evasion</a> (data.ny.gov)' : '') . '. Updated ' . esc_html(gmdate('M j, Y', strtotime($d['updated']))) .
        '.<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership mta-crime">' . $o . mta_ridership_disclaimer() . '</div>';
});

/* ---------- search title, description and FAQ ---------- */

function mta_crime_seo()
{
    if (!is_page(MTA_CRIME_SLUG) || !mta_crime_live() || !($f = mta_crime_facts())) {
        return null;
    }
    $y = $f['y'];
    return ['f' => $f, 'title' => 'Is the NYC Subway Safe? Crime Stats (' . substr($f['d']['latest'], 0, 4) . ')',
            'description' => 'NYC subway crime statistics: ' . number_format($y['n']) . ' major felonies in 12 months, ' .
                mta_crime_change_text($f['chg']) . '. Crime by year, time of day and station.'];
}

add_filter('wpseo_title', function ($t) { return ($s = mta_crime_seo()) ? mta_ridership_seo_title($s['title']) : $t; });
add_filter('wpseo_opengraph_title', function ($t) { return ($s = mta_crime_seo()) ? $s['title'] : $t; });
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($t) { return ($s = mta_crime_seo()) ? mta_ridership_seo_desc($s['description']) : $t; });
}
add_action('wp_head', function () {
    if (!($s = mta_crime_seo())) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($i) {
            return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
        }, mta_crime_faq($s['f'])),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});
