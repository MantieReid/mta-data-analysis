<?php
/**
 * Bus speed map on the Bus Routes page (and each route page, showing just that route): how fast buses move along
 * each stretch of street, hour by hour, on an average weekday, Saturday and Sunday. Red for slow, green for fast.
 * Data (data.ny.gov): MTA Bus Route Segment Speeds (kufs-yh3x), from the buses' GPS: the average time buses take
 * between two timepoints (main stops), by month, day of the week and hour.
 *
 * - Speed = road distance ÷ travel time over every trip in that hour (each row weighted by its bus trips).
 * - Local, Limited and SBS service only: express and school trips run on highways or a few times a day.
 * - Built when the MTA posts a new month: one quick query per route (~0.5 s), saved to uploads/mta-busspeeds/raw/,
 *   in batches of ~4 minutes; then added up by stretch of street into speed-places.json and speed-wk/sa/su.json.
 * Live since 2026-11-02; mta_busspeeds_live() switches it off again if needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_BUSSPEEDS_HOOK   = 'mta_ridership_busspeeds_step';
const MTA_BUSSPEEDS_OPTION = 'mta_ridership_busspeeds';

add_action(MTA_BUSSPEEDS_HOOK, function () { mta_ridership_run_step('busspeeds'); });

add_action('init', function () {
    if (!get_option(MTA_BUSSPEEDS_OPTION) && get_option(MTA_BUSES_OPTION)) {
        mta_busspeeds_schedule(180);
    }
});

/** The bus speed map is live (since 2026-11-02); return mta_ridership_preview() to put it back in review. */
function mta_busspeeds_live()
{
    return true;
}

function mta_busspeeds_schedule($delay)
{
    if (!wp_next_scheduled(MTA_BUSSPEEDS_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_BUSSPEEDS_HOOK);
    }
}

/** Where a file lives: [path on disk, URL]. */
function mta_busspeeds_file($name)
{
    $u = wp_upload_dir(null, false);
    $name = preg_replace('/[^0-9A-Za-z\/_-]/', '', $name);
    return [$u['basedir'] . "/mta-busspeeds/$name.json", $u['baseurl'] . "/mta-busspeeds/$name.json"];
}

/**
 * One route's rows (a/z = timepoint names, alat/alon/zlat/zlon, h = hour, b = wk/sa/su, d = miles × trips,
 * t = minutes × trips, n = trips) into its stretches of street: "alat,alon,zlat,zlon" => [a, z, alat, alon, zlat, zlon,
 * {wk/sa/su: {hour: [d, t, n]}}]. Stretches over 8 km in a straight line are left out (they'd cut across blocks).
 */
function mta_busspeeds_route(array $rows)
{
    $out = [];
    foreach ($rows as $r) {
        if (!isset($r['alat'], $r['alon'], $r['zlat'], $r['zlon'], $r['d'], $r['t'], $r['n']) || !in_array($r['b'], ['wk', 'sa', 'su'], true)) {
            continue;
        }
        $c = [round((float) $r['alat'], 5), round((float) $r['alon'], 5), round((float) $r['zlat'], 5), round((float) $r['zlon'], 5)];
        $km = sqrt((($c[2] - $c[0]) * 111.0) ** 2 + (($c[3] - $c[1]) * 84.0) ** 2);
        if ($km > 8 || $km < 0.05) {
            continue;
        }
        $k = implode(',', $c);
        if (!isset($out[$k])) {
            $out[$k] = [mta_busspeeds_name($r['a'] ?? ''), mta_busspeeds_name($r['z'] ?? ''), $c[0], $c[1], $c[2], $c[3], []];
        }
        $h = (int) $r['h'] % 24;
        $x = $out[$k][6][$r['b']][$h] ?? [0, 0, 0];
        $out[$k][6][$r['b']][$h] = [$x[0] + (float) $r['d'], $x[1] + (float) $r['t'], $x[2] + (int) $r['n']];
    }
    return $out;
}

/** "NOSTRAND AV/AVENUE H" → "Nostrand Av/Avenue H". */
function mta_busspeeds_name($s)
{
    return ucwords(strtolower(trim($s)), " /-(");
}

/**
 * Every route's stretches added up into one citywide map. Pure part, for tests: $routes is [[route label, stretches], ...].
 * Returns {routes: [labels], segs: [[alat, alon, zlat, zlon, from, to, [route indexes]], ...] (fastest first, so the slow
 * ones draw on top), wk/sa/su: [[24 mph with one decimal, or null when fewer than 3 trips], ...] in the same order}.
 */
function mta_busspeeds_combine(iterable $routes)
{
    $labels = $segs = $sum = [];
    foreach ($routes as [$label, $stretches]) {
        $ri = count($labels);
        $labels[] = $label;
        foreach ($stretches as $k => $s) {
            if (!isset($segs[$k])) {
                $segs[$k] = [$s[2], $s[3], $s[4], $s[5], $s[0], $s[1], []];
            }
            $segs[$k][6][$ri] = $ri;
            foreach ($s[6] as $b => $hours) {
                foreach ($hours as $h => $x) {
                    $y = $sum[$k][$b][$h] ?? [0, 0, 0];
                    $sum[$k][$b][$h] = [$y[0] + $x[0], $y[1] + $x[1], $y[2] + $x[2]];
                }
            }
        }
    }
    $mph = function ($x) { return $x && $x[2] >= 3 && $x[1] > 0 ? round($x[0] / $x[1] * 60, 1) : null; };
    $out = ['routes' => $labels, 'segs' => [], 'wk' => [], 'sa' => [], 'su' => []];
    $rows = [];
    foreach ($segs as $k => $s) {
        $row = [];
        foreach (['wk', 'sa', 'su'] as $b) {
            for ($h = 0; $h < 24; $h++) {
                $row[$b][] = $mph($sum[$k][$b][$h] ?? null);
            }
        }
        $all = array_filter($row['wk'], function ($v) { return $v !== null; });
        if (!$all) {
            continue; // no weekday hour with enough trips
        }
        $rows[] = [array_sum($all) / count($all), $k, $row];
    }
    usort($rows, function ($a, $b) { return [$b[0], $a[1]] <=> [$a[0], $b[1]]; });
    foreach ($rows as [, $k, $row]) {
        $s = $segs[$k];
        $out['segs'][] = [$s[0], $s[1], $s[2], $s[3], $s[4], $s[5], array_values($s[6])];
        foreach ($row as $b => $a) {
            $out[$b][] = $a;
        }
    }
    return $out;
}

/** Write speed-places.json (stretches and routes) and speed-wk/sa/su.json (speeds by hour) from the route files. */
function mta_busspeeds_build_all($month, array $routes)
{
    $gen = function () use ($routes) {
        foreach ($routes as $slug => $label) {
            [$path] = mta_busspeeds_file("raw/$slug");
            $data = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
            if ($data) {
                yield [$label, $data];
            }
        }
    };
    $all = mta_busspeeds_combine($gen());
    $files = ['places' => ['month' => $month, 'routes' => $all['routes'], 'segs' => $all['segs']]];
    foreach (['wk', 'sa', 'su'] as $b) {
        $files[$b] = ['month' => $month, 'mph' => $all[$b]];
    }
    foreach ($files as $name => $obj) {
        [$path] = mta_busspeeds_file("speed-$name");
        $json = wp_json_encode($obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$json || file_put_contents($path, $json) === false) {
            throw new Exception("Could not write speed-$name.json");
        }
    }
    return count($all['segs']);
}

/** Monthly step: one route at a time, a batch of ~4 minutes per run, then the citywide files. */
function mta_busspeeds_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }
    $deadline = time() + 240;
    try {
        $api = function ($params) { return mta_ridership_api('https://data.ny.gov/resource/kufs-yh3x.json', $params + ['$limit' => 50000]); };
        $kinds = "route_type IN ('Local', 'Limited', 'SBS')";
        $st = get_option(MTA_BUSSPEEDS_OPTION) ?: [];
        $max = $api(['$select' => 'max(timestamp) AS m']);
        $latest = substr($max[0]['m'] ?? '', 0, 7);
        if (!$latest) {
            throw new Exception('No bus speeds returned');
        }
        [$y, $m] = array_map('intval', explode('-', $latest));
        if (($st['building'] ?? $st['month'] ?? '') !== $latest) {
            $ids = array_column($api(['$select' => 'route_id AS r', '$where' => "year = $y AND month = $m AND $kinds", '$group' => 'r']), 'r');
            if (!$ids) {
                throw new Exception('No bus routes for ' . $latest);
            }
            sort($ids);
            $st['building'] = $latest;
            $st['todo'] = $ids;
            $st['routes'] = [];
        }
        while (!empty($st['todo']) && time() < $deadline) {
            $id = $st['todo'][0];
            $rows = $api([
                '$select' => 'timepoint_stop_name AS a, next_timepoint_stop_name AS z, timepoint_stop_latitude AS alat, timepoint_stop_longitude AS alon, ' .
                             'next_timepoint_stop_latitude AS zlat, next_timepoint_stop_longitude AS zlon, hour_of_day AS h, ' .
                             "case(day_of_week = 'Saturday', 'sa', day_of_week = 'Sunday', 'su', true, 'wk') AS b, " .
                             'sum(road_distance * bus_trip_count) AS d, sum(average_travel_time * bus_trip_count) AS t, sum(bus_trip_count) AS n',
                '$where'  => "year = $y AND month = $m AND $kinds AND route_id = '" . str_replace("'", "''", $id) . "'",
                '$group'  => 'a, z, alat, alon, zlat, zlon, h, b',
            ]);
            $data = mta_busspeeds_route($rows);
            $key = strtoupper($id); // the ridership data writes "BX12+", this one "Bx12+"
            $slug = mta_buses_slug($key);
            if ($data) {
                [$path] = mta_busspeeds_file("raw/$slug");
                wp_mkdir_p(dirname($path));
                if (file_put_contents($path, wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
                    throw new Exception("Could not write the file for $id");
                }
                $st['routes'][$slug] = mta_buses_label($key);
            }
            array_shift($st['todo']);
            update_option(MTA_BUSSPEEDS_OPTION, $st, false);
        }
        if (!empty($st['todo'])) {
            wp_schedule_single_event(time() + 60, MTA_BUSSPEEDS_HOOK); // next batch
        } elseif (($st['month'] ?? '') !== $latest) {
            $st['segs'] = mta_busspeeds_build_all($latest, $st['routes']);
            $st['month'] = $latest;
            $st['latest'] = $latest; // the status page reads it as a month
            $st['updated'] = gmdate('c');
            if (function_exists('rocket_clean_domain')) {
                rocket_clean_domain();
            }
        }
        $st['checked'] = gmdate('c');
        update_option(MTA_BUSSPEEDS_OPTION, $st, false);
        delete_option('mta_ridership_busspeeds_error');
    } catch (Throwable $e) {
        update_option('mta_ridership_busspeeds_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_BUSSPEEDS_HOOK, 'mta_busspeeds_schedule');
    }
}

/**
 * The bus speed map section: every stretch of street on the Bus Routes page, or one route's on its page ($r).
 * '' before the files exist or when the route has no stretches.
 */
function mta_busspeeds_section($r, $sec)
{
    $st = get_option(MTA_BUSSPEEDS_OPTION);
    [$path, $url] = mta_busspeeds_file('speed-places');
    if (!mta_busspeeds_live() || empty($st['month']) || !file_exists($path)) {
        return '';
    }
    $label = $r ? mta_buses_label($r['id']) : '';
    if ($r && !in_array($label, $st['routes'] ?? [], true)) {
        return '';
    }
    $title = $r ? 'How fast the ' . $label . ' moves, hour by hour' : 'How fast NYC buses move, hour by hour';
    return $sec($title,
        '<p>Each line is a stretch of street between two main stops' . ($r ? ' on the ' . esc_html($label) : '') . ', coloured by the buses’ average speed in that hour: ' .
        'red for slow, yellow in between, green for fast. Press play to watch traffic build up and clear; tap a stretch for its speed' . ($r ? '' : ' and routes') . '.</p>' .
        '<div data-slot="busspeed" data-src="' . esc_url(dirname($url) . '/') . '" data-v="' . esc_attr($st['month']) . '"' .
        ($r ? ' data-route="' . esc_attr($label) . '"' : '') . '></div>',
        'MTA bus GPS data for ' . esc_html(mta_lines_month($st['month'])) . ': road distance ÷ travel time between main stops (timepoints), averaged over every ' .
        'trip that hour, including time spent at stops and red lights. Local, Limited and Select Bus Service; express and school trips are left out. ' .
        'Lines are drawn straight from stop to stop, so they follow the streets only roughly.');
}
