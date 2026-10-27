<?php
/**
 * Station trends: unusual busy/quiet days, weekend vs weekday stations, late-night share, transfers.
 *
 * Unusual days need each station's daily history, so we keep a compact per-station daily
 * history in an option and only fetch the days we don't have yet (backfilled in chunks,
 * one cron request per chunk, so no request runs too long).
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_RIDERSHIP_TRENDS_HOOK   = 'mta_ridership_trends_step';
const MTA_RIDERSHIP_TRENDS_OPTION = 'mta_ridership_trends';
const MTA_RIDERSHIP_HISTORY       = 'mta_ridership_history';
const MTA_RIDERSHIP_CHUNK_DAYS    = 45;   // ~19k station-days per API call
const MTA_RIDERSHIP_STEP_SECONDS  = 100;  // stop fetching and reschedule after this

const MTA_SPIKE_MIN_BASELINE = 1500; // ignore stations normally below this many riders/day
const MTA_QUIET_MIN_BASELINE = 3000;
const MTA_SPIKE_RATIO        = 3.0;  // "unusually busy" = 3x its normal level (vs the system that day)
const MTA_QUIET_RATIO        = 0.35;

add_action(MTA_RIDERSHIP_TRENDS_HOOK, 'mta_ridership_trends_step');

// First build after install/upgrade: start it without waiting for the next daily refresh.
add_action('init', function () {
    if (!get_option(MTA_RIDERSHIP_TRENDS_OPTION) && get_option(MTA_RIDERSHIP_OPTION)) {
        mta_ridership_schedule_trends(10);
    }
});

function mta_ridership_schedule_trends($delay)
{
    if (!wp_next_scheduled(MTA_RIDERSHIP_TRENDS_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_RIDERSHIP_TRENDS_HOOK);
    }
}

function mta_ridership_trends_status()
{
    $t = get_option(MTA_RIDERSHIP_TRENDS_OPTION);
    $h = mta_ridership_history_load();
    $have = $h ? 'history through ' . mta_ridership_history_last_day($h) : 'no history yet';
    $err = get_option('mta_ridership_trends_error');
    return ($t ? 'last built ' . $t['updated'] : 'not built yet') . ', ' . $have . ($err ? ' (last error: ' . $err . ')' : '');
}

/* ---------- compact history: start day + per-station arrays indexed by day offset ---------- */

function mta_ridership_history_load()
{
    $raw = get_option(MTA_RIDERSHIP_HISTORY);
    if (!$raw) {
        return null;
    }
    $json = gzuncompress(base64_decode($raw));
    return $json ? json_decode($json, true) : null;
}

function mta_ridership_history_save($h)
{
    update_option(MTA_RIDERSHIP_HISTORY, base64_encode(gzcompress(wp_json_encode($h), 6)), false);
}

function mta_ridership_day_index($start, $ymd)
{
    return (int) round((strtotime("$ymd UTC") - strtotime("$start UTC")) / 86400);
}

function mta_ridership_history_last_day($h)
{
    return mta_ridership_next_day($h['start'], $h['ndays'] - 1);
}

/** One cron step: fetch missing days for a while, then either reschedule or build the trends. */
function mta_ridership_trends_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    $t0 = time();
    try {
        $data = get_option(MTA_RIDERSHIP_OPTION);
        if (!$data) {
            return;
        }
        $latest = $data['latest_day'];
        $h = mta_ridership_history_load() ?: ['start' => $data['first_day'], 'ndays' => 0, 'st' => [], 'names' => []];

        while (mta_ridership_history_last_day($h) < $latest || $h['ndays'] === 0) {
            if (time() - $t0 > MTA_RIDERSHIP_STEP_SECONDS) {
                mta_ridership_history_save($h);
                mta_ridership_schedule_trends(30);
                return;
            }
            $from = mta_ridership_next_day($h['start'], $h['ndays']);
            $to   = min(mta_ridership_next_day($from, MTA_RIDERSHIP_CHUNK_DAYS - 1), $latest);
            $rows = [];
            for ($offset = 0; ; $offset += 50000) {
                $page = mta_ridership_query([
                    '$select' => 'station_complex_id, station_complex, borough, date_trunc_ymd(transit_timestamp) AS day, sum(ridership) AS riders',
                    '$where'  => "transit_timestamp >= '{$from}' AND transit_timestamp < '" . mta_ridership_next_day($to) . "'",
                    '$group'  => 'station_complex_id, station_complex, borough, day',
                    '$order'  => 'day, station_complex_id',
                    '$limit'  => 50000, '$offset' => $offset,
                ]);
                $rows = array_merge($rows, $page);
                if (count($page) < 50000) {
                    break;
                }
            }
            foreach ($rows as $r) {
                $sid = $r['station_complex_id'];
                $h['names'][$sid] = [$r['station_complex'], $r['borough'] ?? ''];
                $h['st'][$sid][mta_ridership_day_index($h['start'], substr($r['day'], 0, 10))] = (int) round($r['riders']);
            }
            $h['ndays'] = mta_ridership_day_index($h['start'], $to) + 1;
        }
        mta_ridership_history_save($h);
        mta_ridership_build_trends($h, $latest);
        mta_ridership_calendar_step(); // quick: reuses the history just saved
        mta_ridership_schedule_extras(60); // map, year-over-year, fares, payment: next cron request
        delete_option('mta_ridership_trends_error');
        mta_ridership_clear_page_cache();
    } catch (Throwable $e) {
        update_option('mta_ridership_trends_error', gmdate('c') . ' ' . $e->getMessage(), false);
    }
}

// Public, read-only copy of the published numbers (same data the page shows).
add_action('rest_api_init', function () {
    register_rest_route('mta-ridership/v1', '/data', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $data = get_option(MTA_RIDERSHIP_OPTION) ?: [];
            $data['trends'] = get_option(MTA_RIDERSHIP_TRENDS_OPTION) ?: null;
            $data['trends_status'] = mta_ridership_trends_status();
            $data['extras'] = get_option(MTA_RIDERSHIP_EXTRAS_OPTION) ?: null;
            $data['extras_error'] = get_option('mta_ridership_extras_error') ?: null;
            $data['calendar'] = get_option(MTA_RIDERSHIP_CALENDAR_OPTION) ?: null;
            $next = wp_next_scheduled(MTA_RIDERSHIP_HOOK);
            $data['next_refresh'] = $next ? gmdate('c', $next) : null;
            return $data;
        },
    ]);
});

function mta_ridership_median(array $v)
{
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/**
 * Baseline = median of the same weekday over the previous 26 weeks (needs at least 10).
 * Long enough that a few weeks of closure don't make the reopening look like a spike.
 */
function mta_ridership_baseline(array $series, $i)
{
    $b = [];
    for ($k = 1; $k <= 26; $k++) {
        if (isset($series[$i - 7 * $k])) {
            $b[] = $series[$i - 7 * $k];
        }
    }
    return count($b) >= 10 ? mta_ridership_median($b) : null;
}

/**
 * Find runs of unusual days per station. Ratios are relative to the whole system that day,
 * so holidays/storms that hit every station don't count as a station being "quiet".
 */
function mta_ridership_find_events($h, $sys, $sys_ratio, $busy)
{
    $events = [];
    foreach ($h['st'] as $sid => $series) {
        $run = null;
        $close = function () use (&$run, &$events, $sid) {
            if ($run) {
                $run['id'] = $sid;
                $events[] = $run;
            }
            $run = null;
        };
        for ($i = 70; $i < $h['ndays']; $i++) {
            if (!isset($series[$i]) || empty($sys_ratio[$i])) {
                continue;
            }
            $base = mta_ridership_baseline($series, $i);
            if ($base === null || $base < ($busy ? MTA_SPIKE_MIN_BASELINE : MTA_QUIET_MIN_BASELINE)) {
                continue;
            }
            $ratio = $series[$i] / $base;
            $rel   = $ratio / $sys_ratio[$i];
            $hit   = $busy ? $rel >= MTA_SPIKE_RATIO : ($rel <= MTA_QUIET_RATIO && $series[$i] > 0);
            if ($run && $i - $run['last'] > 2) {
                $close();
            }
            if (!$hit) {
                continue;
            }
            if (!$run) {
                $run = ['first' => $i, 'last' => $i, 'days' => 0, 'peak' => $i, 'ratio' => $ratio, 'riders' => $series[$i], 'baseline' => $base];
            }
            $run['last'] = $i;
            $run['days']++;
            if ($busy ? $ratio > $run['ratio'] : $ratio < $run['ratio']) {
                $run['peak'] = $i;
                $run['ratio'] = $ratio;
                $run['riders'] = $series[$i];
                $run['baseline'] = $base;
            }
        }
        $close();
    }
    return $events;
}

function mta_ridership_event_out($h, $e)
{
    $series = $h['st'][$e['id']];
    $from = max(0, $e['peak'] - 28);
    $to   = min($h['ndays'] - 1, $e['peak'] + 14);
    $vals = [];
    for ($i = $from; $i <= $to; $i++) {
        $vals[] = $series[$i] ?? null;
    }
    return [
        'id'        => $e['id'],
        'name'      => $h['names'][$e['id']][0],
        'borough'   => $h['names'][$e['id']][1],
        'peak_day'  => mta_ridership_next_day($h['start'], $e['peak']),
        'first_day' => mta_ridership_next_day($h['start'], $e['first']),
        'last_day'  => mta_ridership_next_day($h['start'], $e['last']),
        'days'      => $e['days'],
        'riders'    => $e['riders'],
        'baseline'  => (int) round($e['baseline']),
        'ratio'     => round($e['ratio'], 2),
        'series_start' => mta_ridership_next_day($h['start'], $from),
        'series'    => $vals,
    ];
}

function mta_ridership_build_trends($h, $latest)
{
    // System total per day and its own ratio to normal.
    $sys = array_fill(0, $h['ndays'], 0);
    foreach ($h['st'] as $series) {
        foreach ($series as $i => $v) {
            $sys[$i] += $v;
        }
    }
    $sys_ratio = [];
    for ($i = 0; $i < $h['ndays']; $i++) {
        $b = mta_ridership_baseline($sys, $i);
        $sys_ratio[$i] = ($b && $sys[$i]) ? $sys[$i] / $b : null;
    }

    $last = $h['ndays'] - 1;
    $by_year = function ($events, $busy, $n) use ($h) {
        $out = [];
        foreach ($events as $e) {
            $out[substr(mta_ridership_next_day($h['start'], $e['peak']), 0, 4)][] = $e;
        }
        ksort($out);
        foreach ($out as $y => $list) {
            // busy: biggest multiple of normal; quiet: most riders missing (closures all sit near 0%)
            usort($list, function ($a, $b) use ($busy) {
                return $busy ? $b['ratio'] <=> $a['ratio']
                             : $b['baseline'] * $b['days'] <=> $a['baseline'] * $a['days'];
            });
            $per_station = [];
            $keep = [];
            foreach ($list as $e) { // at most 2 per station, so Mets-Willets Point doesn't fill the list
                if (($per_station[$e['id']] = ($per_station[$e['id']] ?? 0) + 1) <= 2) {
                    $keep[] = $e;
                }
            }
            $out[$y] = array_map(function ($e) use ($h) { return mta_ridership_event_out($h, $e); }, array_slice($keep, 0, $n));
        }
        return $out;
    };

    $spikes = mta_ridership_find_events($h, $sys, $sys_ratio, true);
    $quiet  = mta_ridership_find_events($h, $sys, $sys_ratio, false);
    $recent = array_filter($spikes, function ($e) use ($last) { return $e['last'] >= $last - 13; });
    usort($recent, function ($a, $b) { return $b['ratio'] <=> $a['ratio']; });

    // Weekend vs weekday, over the last 364 days.
    $from = max(0, $h['ndays'] - 364);
    $ww = [];
    foreach ($h['st'] as $sid => $series) {
        $wd = $we = [];
        $window = array_filter(array_slice($series, 0, null, true), function ($i) use ($from) { return $i >= $from; }, ARRAY_FILTER_USE_KEY);
        if (!$window) {
            continue;
        }
        $floor = 0.2 * mta_ridership_median(array_values($window)); // skip days the station was closed
        for ($i = $from; $i < $h['ndays']; $i++) {
            if (!isset($series[$i]) || $series[$i] < $floor) {
                continue;
            }
            $dow = (int) gmdate('N', strtotime(mta_ridership_next_day($h['start'], $i) . ' UTC'));
            if ($dow >= 6) {
                $we[] = $series[$i];
            } else {
                $wd[] = $series[$i];
            }
        }
        if (count($wd) < 100 || count($we) < 40) {
            continue;
        }
        $a = array_sum($wd) / count($wd);
        $b = array_sum($we) / count($we);
        if ($a < 1 || $a + $b < 2000) {
            continue; // too small to be meaningful
        }
        $ww[] = ['id' => $sid, 'name' => $h['names'][$sid][0], 'borough' => $h['names'][$sid][1],
                 'weekday_avg' => (int) round($a), 'weekend_avg' => (int) round($b), 'ratio' => round($b / $a, 3)];
    }
    usort($ww, function ($x, $y) { return $y['ratio'] <=> $x['ratio']; });

    // Late-night share and transfers, last 365 days (summed by the API).
    $year_from = mta_ridership_next_day($latest, -364);
    $range = "transit_timestamp >= '{$year_from}' AND transit_timestamp < '" . mta_ridership_next_day($latest) . "'";
    $tot = [];
    foreach (mta_ridership_query([
        '$select' => 'station_complex_id, sum(ridership) AS riders, sum(transfers) AS transfers',
        '$where'  => $range, '$group' => 'station_complex_id', '$limit' => 5000,
    ]) as $r) {
        $tot[$r['station_complex_id']] = ['riders' => (float) $r['riders'], 'transfers' => (float) ($r['transfers'] ?? 0)];
    }
    $night = [];
    foreach (mta_ridership_query([
        '$select' => 'station_complex_id, sum(ridership) AS riders',
        '$where'  => $range . ' AND date_extract_hh(transit_timestamp) < 5',
        '$group'  => 'station_complex_id', '$limit' => 5000,
    ]) as $r) {
        $sid = $r['station_complex_id'];
        if (empty($tot[$sid]) || $tot[$sid]['riders'] < 365 * 2000 || !isset($h['names'][$sid])) {
            continue;
        }
        $night[] = ['id' => $sid, 'name' => $h['names'][$sid][0], 'borough' => $h['names'][$sid][1],
                    'night_riders' => (int) round($r['riders']), 'share' => round($r['riders'] / $tot[$sid]['riders'], 4)];
    }
    usort($night, function ($x, $y) { return $y['share'] <=> $x['share']; });
    $transfers = [];
    foreach ($tot as $sid => $t) {
        if ($t['transfers'] > 0 && isset($h['names'][$sid])) {
            $transfers[] = ['id' => $sid, 'name' => $h['names'][$sid][0], 'borough' => $h['names'][$sid][1],
                            'transfers' => (int) round($t['transfers']), 'share' => round($t['transfers'] / max(1, $t['riders']), 4)];
        }
    }
    usort($transfers, function ($x, $y) { return $y['transfers'] <=> $x['transfers']; });

    update_option(MTA_RIDERSHIP_TRENDS_OPTION, [
        'updated'     => gmdate('c'),
        'latest_day'  => $latest,
        'year_from'   => $year_from,
        'settings'    => ['spike_ratio' => MTA_SPIKE_RATIO, 'quiet_ratio' => MTA_QUIET_RATIO],
        'recent'      => array_map(function ($e) use ($h) { return mta_ridership_event_out($h, $e); }, array_slice($recent, 0, 5)),
        'spikes'      => $by_year($spikes, true, 10),
        'quiet'       => $by_year($quiet, false, 10),
        'weekend'     => array_slice($ww, 0, 10),
        'weekday'     => array_slice(array_reverse($ww), 0, 10),
        'late_night'  => array_slice($night, 0, 10),
        'transfers'   => array_slice($transfers, 0, 10),
    ], false);
}
