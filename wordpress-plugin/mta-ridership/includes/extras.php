<?php
/**
 * Extras: map data (station locations + average riders per hour over the last 8 weeks),
 * year-over-year change, the station lookup, fare types and payment methods. Same method as
 * compute_extras() in mta_ridership.py. Runs as its own cron step after the trends.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_RIDERSHIP_EXTRAS_HOOK   = 'mta_ridership_extras_step';
const MTA_RIDERSHIP_EXTRAS_OPTION = 'mta_ridership_extras';

add_action(MTA_RIDERSHIP_EXTRAS_HOOK, function () { mta_ridership_run_step('extras'); });

add_action('init', function () {
    if (!get_option(MTA_RIDERSHIP_EXTRAS_OPTION) && get_option(MTA_RIDERSHIP_TRENDS_OPTION)) {
        mta_ridership_schedule_extras(10);
    }
});

function mta_ridership_schedule_extras($delay)
{
    if (!wp_next_scheduled(MTA_RIDERSHIP_EXTRAS_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_RIDERSHIP_EXTRAS_HOOK);
    }
}

function mta_ridership_extras_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    try {
        $data = get_option(MTA_RIDERSHIP_OPTION);
        $h = mta_ridership_history_load();
        if (!$data || !$h) {
            return;
        }
        $latest = $data['latest_day'];
        $day = function ($i) use ($h) { return mta_ridership_next_day($h['start'], $i); };

        // System total per day (to know which days have data).
        $sys = array_fill(0, $h['ndays'], 0);
        foreach ($h['st'] as $series) {
            foreach ($series as $i => $v) {
                $sys[$i] += $v;
            }
        }
        $days_from = function ($from) use ($h, $sys) {
            $n = 0;
            for ($i = max(0, mta_ridership_day_index($h['start'], $from)); $i < $h['ndays']; $i++) {
                $n += $sys[$i] > 0 ? 1 : 0;
            }
            return $n;
        };

        // Map: average riders per station per hour over the last 8 weeks, one week per API call
        // (longer ranges grouped by station and hour time out on the API).
        $map_from = mta_ridership_next_day($latest, -55);
        $map_days = $days_from($map_from);
        $tot = [];
        for ($lo = $map_from; $lo <= $latest; $lo = $hi) {
            $hi = min(mta_ridership_next_day($lo, 7), mta_ridership_next_day($latest));
            foreach (mta_ridership_query([
                '$select' => 'station_complex_id, date_extract_hh(transit_timestamp) AS hr, sum(ridership) AS riders',
                '$where'  => "transit_timestamp >= '{$lo}' AND transit_timestamp < '{$hi}'",
                '$group'  => 'station_complex_id, hr', '$limit' => 50000,
            ]) as $r) {
                $sid = $r['station_complex_id'];
                if (!isset($tot[$sid])) {
                    $tot[$sid] = array_fill(0, 24, 0.0);
                }
                $tot[$sid][(int) $r['hr']] += (float) $r['riders'];
            }
        }
        // Station locations rarely change: reuse the saved ones unless a station is missing.
        $old = get_option(MTA_RIDERSHIP_EXTRAS_OPTION) ?: [];
        $coords = [];
        foreach ($old['stations'] ?? [] as $s) {
            $coords[$s['id']] = [$s['lat'], $s['lon']];
        }
        if (array_diff_key($tot, $coords)) foreach (mta_ridership_query([
            '$select' => 'station_complex_id, max(latitude) AS lat, max(longitude) AS lon',
            '$group'  => 'station_complex_id', '$limit' => 5000,
        ]) as $r) {
            $coords[$r['station_complex_id']] = [(float) $r['lat'], (float) $r['lon']];
        }
        $stations = [];
        foreach ($tot as $sid => $hs) {
            if (!isset($coords[$sid], $h['names'][$sid])) {
                continue;
            }
            $stations[] = ['id' => (string) $sid, 'name' => $h['names'][$sid][0], 'borough' => $h['names'][$sid][1],
                           'lat' => round($coords[$sid][0], 5), 'lon' => round($coords[$sid][1], 5),
                           'hours' => array_map(function ($v) use ($map_days) { return (int) round($v / max(1, $map_days)); }, $hs)];
        }
        usort($stations, function ($a, $b) { return array_sum($b['hours']) <=> array_sum($a['hours']); });

        // Year over year: this year so far vs the same calendar days last year.
        $cur_y = (int) substr($latest, 0, 4);
        $md = substr($latest, 5);
        $cur = $prev = $boro_c = $boro_p = [];
        foreach ($h['st'] as $sid => $series) {
            $boro = $h['names'][$sid][1];
            foreach ($series as $i => $v) {
                $d = $day($i);
                if (substr($d, 5) > $md) {
                    continue;
                }
                $y = (int) substr($d, 0, 4);
                if ($y === $cur_y) {
                    $cur[$sid] = ($cur[$sid] ?? 0) + $v;
                    $boro_c[$boro] = ($boro_c[$boro] ?? 0) + $v;
                } elseif ($y === $cur_y - 1) {
                    $prev[$sid] = ($prev[$sid] ?? 0) + $v;
                    $boro_p[$boro] = ($boro_p[$boro] ?? 0) + $v;
                }
            }
        }
        $yoy = null;
        if ($prev) {
            $days_in = mta_ridership_day_index("$cur_y-01-01", $latest) + 1;
            $moves = [];
            foreach ($prev as $sid => $p) {
                if (isset($cur[$sid]) && $p >= $days_in * 2000) { // 2,000+ riders/day last year
                    $moves[] = ['id' => (string) $sid, 'name' => $h['names'][$sid][0], 'borough' => $h['names'][$sid][1],
                                'prev' => (int) round($p), 'cur' => (int) round($cur[$sid]),
                                'pct' => round(($cur[$sid] / $p - 1) * 100, 1)];
                }
            }
            usort($moves, function ($a, $b) { return $b['pct'] <=> $a['pct']; });
            $boros = [];
            foreach ($boro_p as $b => $p) {
                if (!empty($boro_c[$b])) {
                    $boros[] = ['borough' => $b, 'prev' => (int) round($p), 'cur' => (int) round($boro_c[$b]),
                                'pct' => round(($boro_c[$b] / $p - 1) * 100, 1)];
                }
            }
            usort($boros, function ($a, $b) { return $b['cur'] <=> $a['cur']; });
            $yoy = ['year' => $cur_y, 'prev_year' => $cur_y - 1, 'through' => $md,
                    'total_prev' => (int) round(array_sum($prev)), 'total_cur' => (int) round(array_sum($cur)),
                    'total_pct' => round((array_sum($cur) / array_sum($prev) - 1) * 100, 1),
                    'boroughs' => $boros, 'up' => array_slice($moves, 0, 8), 'down' => array_slice(array_reverse($moves), 0, 8)];
        }

        // Fare types over the last 365 days.
        $year_from = mta_ridership_next_day($latest, -364);

        // Station lookup ("Find your station"): every station's average riders a day over the last
        // 365 days, its rank, and this year vs the same days last year (if it had 100+ riders/day then).
        $year_i = mta_ridership_day_index($h['start'], $year_from);
        $year_days = $days_from($year_from);
        $days_cur = mta_ridership_day_index("$cur_y-01-01", $latest) + 1;
        $lookup = [];
        foreach ($h['st'] as $sid => $series) {
            $sum = 0;
            foreach ($series as $i => $v) {
                $sum += $i >= $year_i ? $v : 0;
            }
            $avg = (int) round($sum / max(1, $year_days));
            if ($avg < 1 || !isset($h['names'][$sid])) { // e.g. St George (SIR), which counts almost no entries
                continue;
            }
            $p = $prev[$sid] ?? 0;
            $lookup[] = ['id' => (string) $sid, 'name' => $h['names'][$sid][0], 'borough' => $h['names'][$sid][1],
                         'avg' => $avg,
                         'yoy' => $p >= $days_cur * 100 && !empty($cur[$sid]) ? round(($cur[$sid] / $p - 1) * 100, 1) : null];
        }
        usort($lookup, function ($a, $b) { return $b['avg'] <=> $a['avg'] ?: strcmp($a['id'], $b['id']); });
        foreach ($lookup as $i => &$row) {
            $row['rank'] = $i + 1;
        }
        unset($row);
        $fares = mta_ridership_query([
            '$select' => 'fare_class_category, sum(ridership) AS riders',
            '$where'  => "transit_timestamp >= '{$year_from}' AND transit_timestamp < '" . mta_ridership_next_day($latest) . "'",
            '$group'  => 'fare_class_category', '$limit' => 1000,
        ]);
        $ftot = array_sum(array_map(function ($r) { return (float) $r['riders']; }, $fares));
        $fares = array_map(function ($r) use ($ftot) {
            return ['category' => $r['fare_class_category'], 'riders' => (int) round($r['riders']),
                    'share' => round($r['riders'] / max(1, $ftot), 4)];
        }, $fares);
        usort($fares, function ($a, $b) { return $b['riders'] <=> $a['riders']; });

        // OMNY vs MetroCard by month.
        // Past months don't change: keep the saved ones and only re-ask for the last two months.
        $since = gmdate('Y-m-01', strtotime(substr($latest, 0, 7) . '-01 -1 month UTC'));
        $pay = [];
        foreach ($old['payment'] ?? [] as $p) {
            if ($p['month'] < substr($since, 0, 7)) {
                $pay[$p['month']] = ['omny' => $p['omny'], 'metrocard' => $p['metrocard']];
            }
        }
        $where = $pay ? "transit_timestamp >= '{$since}'" : null;
        foreach (mta_ridership_query(array_filter([
            '$select' => 'date_trunc_ym(transit_timestamp) AS month, payment_method, sum(ridership) AS riders',
            '$where'  => $where,
            '$group'  => 'month, payment_method', '$limit' => 5000,
        ])) as $r) {
            $pay[substr($r['month'], 0, 7)][strtolower($r['payment_method'])] = (float) $r['riders'];
        }
        ksort($pay);
        $month_days = [];
        foreach ($sys as $i => $v) {
            if ($v > 0) {
                $m = substr($day($i), 0, 7);
                $month_days[$m] = ($month_days[$m] ?? 0) + 1;
            }
        }
        $payment = [];
        foreach ($pay as $m => $p) {
            $o = $p['omny'] ?? 0;
            $mc = $p['metrocard'] ?? 0;
            $payment[] = ['month' => $m, 'omny' => (int) round($o), 'metrocard' => (int) round($mc),
                          'omny_share' => round($o / max(1, $o + $mc), 4),
                          'partial' => ($month_days[$m] ?? 0) < (int) gmdate('t', strtotime("$m-01 UTC"))];
        }

        update_option(MTA_RIDERSHIP_EXTRAS_OPTION, [
            'updated' => gmdate('c'), 'from' => $year_from, 'to' => $latest, 'days' => $days_from($year_from),
            'map_from' => $map_from, 'map_days' => $map_days, 'stations' => $stations,
            'yoy' => $yoy, 'fares' => $fares, 'payment' => $payment, 'lookup' => $lookup,
        ], false);
        delete_option('mta_ridership_extras_error');
        mta_ridership_clear_page_cache();
        mta_congestion_schedule(60); // congestion pricing page: next cron request (uses the map's station locations)
    } catch (Throwable $e) {
        update_option('mta_ridership_extras_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_RIDERSHIP_EXTRAS_HOOK, 'mta_ridership_schedule_extras');
    }
}
