<?php
/**
 * Trip flow map on each station page (/stations/<station>/): where subway riders starting there go, hour by hour, on
 * an average weekday, Saturday and Sunday. Lines from the station to its top destinations, thicker and redder for more
 * riders, with a play button and an hour slider like the other maps.
 * Data (data.ny.gov): Subway Origin-Destination Ridership Estimate (28vm-gjqr), which the MTA scales up from OMNY and
 * MetroCard swipes, by month, day of the week and hour. Each row is the average for that weekday in the month.
 *
 * - One small file per station, uploads/mta-flows/<station id>.json, loaded only when the map is near the screen.
 * - Re-built only when the MTA posts a new month. One query per station (~5 s), so a build runs in batches of
 *   ~4 minutes, each picking up where the last one stopped (like the bus stop maps).
 * Live since 2026-11-02; mta_flows_live() switches it off again if needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_FLOWS_HOOK   = 'mta_ridership_flows_step';
const MTA_FLOWS_OPTION = 'mta_ridership_flows';
const MTA_FLOWS_TOP    = 60; // destinations kept per station; the rest are small and would only clutter the map

add_action(MTA_FLOWS_HOOK, function () { mta_ridership_run_step('flows'); });

add_action('init', function () {
    if (!get_option(MTA_FLOWS_OPTION) && get_option(MTA_STATIONS_OPTION)) {
        mta_flows_schedule(120);
    }
});

/** The trip flow map is live (since 2026-11-02); return mta_ridership_preview() to put it back in review. */
function mta_flows_live()
{
    return true;
}

function mta_flows_schedule($delay)
{
    if (!wp_next_scheduled(MTA_FLOWS_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_FLOWS_HOOK);
    }
}

/** Where a station's file lives: [path on disk, URL]. */
function mta_flows_file($id)
{
    $u = wp_upload_dir(null, false);
    $id = preg_replace('/[^0-9A-Za-z]/', '', (string) $id);
    return [$u['basedir'] . "/mta-flows/$id.json", $u['baseurl'] . "/mta-flows/$id.json"];
}

/**
 * One station's rows (d = destination id, h = hour, b = wk/sa/su, n = sum of the day-of-week averages) into the file
 * the page reads. Weekday rows add up five weekdays' averages, so they're divided by 5.
 * $stations: id => [name, slug, lat, lon]. Returns null when the station or its place is unknown.
 * {total: {wk/sa/su: [24]} (every trip from here), share: % of weekday trips the kept destinations take,
 *  to: [[name, slug, lat, lon, {wk/sa/su: [24]}], ...] busiest last}
 */
function mta_flows_build($origin, array $rows, array $stations, $top = MTA_FLOWS_TOP)
{
    $per = ['wk' => 5, 'sa' => 1, 'su' => 1];
    $v = [];
    $total = ['wk' => array_fill(0, 24, 0), 'sa' => array_fill(0, 24, 0), 'su' => array_fill(0, 24, 0)];
    foreach ($rows as $r) {
        if (!isset($r['n'], $per[$r['b']])) {
            continue;
        }
        $n = $r['n'] / $per[$r['b']];
        $h = (int) $r['h'] % 24;
        $total[$r['b']][$h] += $n;
        if ((string) $r['d'] !== (string) $origin) {
            $v[(string) $r['d']][$r['b']][$h] = ($v[(string) $r['d']][$r['b']][$h] ?? 0) + $n;
        }
    }
    $o = $stations[(string) $origin] ?? null;
    if (!$o || $o[2] === null) {
        return null;
    }
    $day = function ($d) use ($v) { return array_sum($v[$d]['wk'] ?? []); };
    $ids = array_filter(array_keys($v), function ($d) use ($stations) { return isset($stations[$d]) && $stations[$d][2] !== null; });
    usort($ids, function ($a, $b) use ($day) { return [$day($b), $a] <=> [$day($a), $b]; });
    $ids = array_slice($ids, 0, $top);
    $all = array_sum($total['wk']);
    $kept = array_sum(array_map($day, $ids));
    $to = [];
    foreach (array_reverse($ids) as $d) {
        $hours = [];
        foreach ($per as $b => $_) {
            $hours[$b] = [];
            for ($h = 0; $h < 24; $h++) {
                $x = $v[$d][$b][$h] ?? 0;
                $hours[$b][] = round($x, $x < 10 ? 1 : 0);
            }
        }
        [$name, $slug, $lat, $lon] = $stations[$d];
        $to[] = [$name, $slug, $lat, $lon, $hours];
    }
    foreach ($total as $b => $a) {
        $total[$b] = array_map(function ($x) { return (int) round($x); }, $a);
    }
    return ['from' => [$o[0], $o[2], $o[3]], 'total' => $total, 'share' => $all ? round($kept / $all * 100) : 0, 'to' => $to];
}

/** Every station as id => [display name, slug, lat, lon], from the station pages' list. */
function mta_flows_stations()
{
    $out = [];
    foreach (mta_stations_all() as $id => $s) {
        $out[(string) $id] = [mta_stations_display($s['name']), $s['slug'], $s['lat'] !== null ? round((float) $s['lat'], 5) : null,
                              $s['lon'] !== null ? round((float) $s['lon'], 5) : null];
    }
    return $out;
}

/**
 * Monthly step: when the MTA has a new month, re-build every station's file, a batch at a time.
 * Progress is saved after each station, so a cut-off run loses at most one station.
 */
function mta_flows_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }
    $deadline = time() + 240;
    try {
        $api = function ($id, $params) { return mta_ridership_api("https://data.ny.gov/resource/$id.json", $params + ['$limit' => 50000]); };
        $st = get_option(MTA_FLOWS_OPTION) ?: [];
        $max = $api('28vm-gjqr', ['$select' => 'max(timestamp) AS m']);
        $latest = substr($max[0]['m'] ?? '', 0, 7);
        if (!$latest) {
            throw new Exception('No trip estimates returned');
        }
        $stations = mta_flows_stations();
        if (!$stations) {
            throw new Exception('Station list not loaded yet');
        }
        if (($st['building'] ?? $st['month'] ?? '') !== $latest) {
            $st['building'] = $latest;
            $st['todo'] = array_map('strval', array_keys($stations));
            $st['done'] = 0;
        }
        [$y, $m] = array_map('intval', explode('-', $latest));
        while (!empty($st['todo']) && time() < $deadline) {
            $id = $st['todo'][0];
            $rows = $api('28vm-gjqr', [
                '$select' => "destination_station_complex_id AS d, hour_of_day AS h, case(day_of_week = 'Saturday', 'sa', day_of_week = 'Sunday', 'su', true, 'wk') AS b, " .
                             'sum(estimated_average_ridership) AS n',
                '$where'  => "year = $y AND month = $m AND origin_station_complex_id = '" . preg_replace('/[^0-9A-Za-z]/', '', $id) . "'",
                '$group'  => 'd, h, b',
            ]);
            $data = mta_flows_build($id, $rows, $stations);
            [$path] = mta_flows_file($id);
            if ($data && $data['to']) {
                wp_mkdir_p(dirname($path));
                $json = wp_json_encode(['month' => $latest] + $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!$json || file_put_contents($path, $json) === false) {
                    throw new Exception("Could not write the file for station $id");
                }
                $st['done']++;
            } elseif (file_exists($path)) {
                @unlink($path); // no trips this month: no map rather than an old one
            }
            array_shift($st['todo']);
            update_option(MTA_FLOWS_OPTION, $st, false);
        }
        if (!empty($st['todo'])) {
            wp_schedule_single_event(time() + 60, MTA_FLOWS_HOOK); // next batch
        } elseif (($st['month'] ?? '') !== $latest) {
            $st['month'] = $latest;
            $st['latest'] = $latest; // the status page reads it as a month
            $st['stations'] = $st['done'];
            $st['updated'] = gmdate('c');
            if (function_exists('mta_ridership_clean_urls')) {
                mta_ridership_clean_urls([mta_stations_url()]); // the trip maps are on the station pages
            }
        }
        $st['checked'] = gmdate('c');
        update_option(MTA_FLOWS_OPTION, $st, false);
        delete_option('mta_ridership_flows_error');
    } catch (Throwable $e) {
        update_option('mta_ridership_flows_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_FLOWS_HOOK, 'mta_flows_schedule');
    }
}

/** The trip flow map section for a station page, or '' when there's no file for it yet. */
function mta_flows_section($s, $sec)
{
    $st = get_option(MTA_FLOWS_OPTION);
    [$path, $url] = mta_flows_file($s['id']);
    if (!mta_flows_live() || empty($st['month']) || !file_exists($path)) {
        return '';
    }
    $name = mta_stations_display($s['name']);
    return $sec('Where riders from ' . $name . ' go, hour by hour',
        '<p>Each line runs from ' . esc_html($name) . ' to a station where its riders get off; thicker and redder lines carry more people in that hour. ' .
        'Press play to watch a day go by, or tap a station for its numbers.</p><div data-slot="flows" data-src="' . esc_url($url . '?v=' . $st['month']) . '" data-base="' . esc_url(trailingslashit(mta_stations_link())) . '"></div>',
        'MTA estimates for ' . esc_html(gmdate('F Y', strtotime($st['month'] . '-01 UTC'))) . ', scaled up from OMNY and MetroCard swipes: subway trips that ' .
        'start here, by the hour they start, on an average day. The map shows the top ' . MTA_FLOWS_TOP . ' destinations. They are estimates, not exact counts.');
}
