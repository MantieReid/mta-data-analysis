<?php
/**
 * Live subway map: every line on a map with its stops in real order, the next trains at a stop and current
 * service alerts. Shown on each line page (/subway-lines/<line>/) and on /subway-map/ ([mta_map]).
 *
 * - Lines, stops and track shapes come from the MTA's static GTFS (rrgtfsfeeds/gtfs_subway.zip), turned
 *   once a day into one small file, uploads/mta-subway/lines.json (re-built only when the zip changes).
 * - Arrival times: the visitor's browser reads the MTA's GTFS-Realtime feeds directly (they allow it and need
 *   no key), so this server carries no live load. See assets/mta-map.js.
 * - Alerts: the MTA's alerts file is ~1 MB, so /wp-json/mta-ridership/v1/alerts fetches it at most every
 *   2 minutes and returns only the active alerts, in the MTA's own words.
 * In review: everything here shows only with ?mta_preview=1 (mta_map_live()).
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_MAP_HOOK   = 'mta_ridership_map_step';
const MTA_MAP_OPTION = 'mta_ridership_map';
const MTA_MAP_SLUG   = 'subway-map';
const MTA_MAP_GTFS   = 'https://rrgtfsfeeds.s3.amazonaws.com/gtfs_subway.zip';
const MTA_MAP_ALERTS = 'https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/camsys%2Fsubway-alerts.json';

/** The map is in review: shown only with ?mta_preview=1. */
function mta_map_live()
{
    return mta_ridership_preview();
}

/** The public live map is its own app on a subdomain (repo MantieReid/nyc-subway-map). */
const MTA_MAP_APP = 'https://map.mantiereidii.com/';

/** Link to the map app, opened on one line when $code (a line page code such as "Q" or "S 42nd") is given. */
function mta_map_app_url($code = null)
{
    if ($code === null) {
        return MTA_MAP_APP;
    }
    $filter = strpos($code, 'S ') === 0 || $code === 'S' ? 'Shuttles' : strtoupper($code);
    return MTA_MAP_APP . '#mta/' . rawurlencode($filter) . '?view=map';
}

add_action(MTA_MAP_HOOK, function () { mta_ridership_run_step('map'); });

function mta_map_schedule($delay)
{
    if (!wp_next_scheduled(MTA_MAP_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_MAP_HOOK);
    }
}

add_action('init', function () {
    if (!get_option(MTA_MAP_OPTION) && get_option(MTA_RIDERSHIP_EXTRAS_OPTION)) {
        mta_map_schedule(30);
    }
});

/** Which live feed carries a route (checked against the feeds on 2026-10-29). */
function mta_map_feed($route)
{
    $feeds = ['A' => 'ace', 'C' => 'ace', 'E' => 'ace', 'H' => 'ace', 'B' => 'bdfm', 'D' => 'bdfm', 'F' => 'bdfm', 'M' => 'bdfm', 'FS' => 'bdfm',
              'G' => 'g', 'J' => 'jz', 'Z' => 'jz', 'L' => 'l', 'N' => 'nqrw', 'Q' => 'nqrw', 'R' => 'nqrw', 'W' => 'nqrw', 'SI' => 'si'];
    return 'gtfs' . (isset($feeds[$route]) ? '-' . $feeds[$route] : '');
}

/** "6X" (the 6 express) and "FX" are the same line as the 6 and F on the map. */
function mta_map_base_route($id)
{
    return preg_match('/^(\d|F)X$/', $id) ? $id[0] : $id;
}

/** Google's encoded polyline format (5 decimals), so the shapes stay small. */
function mta_map_encode(array $pts)
{
    $num = function ($v) {
        $v = $v < 0 ? ~($v << 1) : $v << 1;
        $s = '';
        while ($v >= 0x20) {
            $s .= chr((0x20 | ($v & 0x1f)) + 63);
            $v >>= 5;
        }
        return $s . chr($v + 63);
    };
    $out = '';
    $plat = $plon = 0;
    foreach ($pts as [$lat, $lon]) {
        $a = (int) round($lat * 1e5);
        $b = (int) round($lon * 1e5);
        $out .= $num($a - $plat) . $num($b - $plon);
        $plat = $a;
        $plon = $b;
    }
    return $out;
}

/** Keep a point only when it is at least $m metres from the last kept one (and always the last point). */
function mta_map_simplify(array $pts, $m = 35)
{
    $out = [];
    $last = null;
    foreach ($pts as $i => $p) {
        if ($last === null || $i === count($pts) - 1 ||
            hypot(($p[0] - $last[0]) * 111000, ($p[1] - $last[1]) * 84000) >= $m) {
            $out[] = $p;
            $last = $p;
        }
    }
    return $out;
}

/** Is $small a run of consecutive stops inside $big? */
function mta_map_contains(array $big, array $small)
{
    $n = count($small);
    for ($i = 0; $i + $n <= count($big); $i++) {
        if (array_slice($big, $i, $n) === $small) {
            return true;
        }
    }
    return false;
}

/**
 * Turn the GTFS zip into the map data:
 *   routes[id] = [s: name, n: long name, c: colour, t: text colour, f: feed,
 *                 b: branches [[from, to, trips, [[stop id, part-time 0/1], ...]], ...], sh: [encoded shapes]]
 *   stops[id]  = [name, lat, lon]   (parent stations only)
 * Branches are the stop patterns in one direction (direction 1, or 0 if a route has none), keeping those run
 * by at least 3% of trips and dropping any that are just a piece of a longer one. One branch per pair of end
 * stations, its longest pattern; a stop is part-time when fewer than half of that branch's trips stop there
 * (e.g. the A's local stops in Manhattan, late nights only).
 */
function mta_map_build($zip_path)
{
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        throw new Exception('Could not open the GTFS zip');
    }
    $rows = function ($name) use ($zip) {
        $h = $zip->getStream($name);
        if (!$h) {
            throw new Exception("GTFS file missing: $name");
        }
        $head = fgetcsv($h, 0, ',', '"', '\\');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            if (count($r) === count($head)) {
                yield array_combine($head, $r);
            }
        }
        fclose($h);
    };

    $routes = [];
    foreach ($rows('routes.txt') as $r) {
        if (mta_map_base_route($r['route_id']) === $r['route_id']) {
            $routes[$r['route_id']] = ['s' => $r['route_short_name'], 'n' => $r['route_long_name'], 'c' => $r['route_color'] ?: '808183',
                                       't' => $r['route_text_color'] ?: 'FFFFFF', 'f' => mta_map_feed($r['route_id'])];
        }
    }
    $parent = $stops = [];
    foreach ($rows('stops.txt') as $s) {
        $parent[$s['stop_id']] = $s['parent_station'] ?: $s['stop_id'];
        if ($s['parent_station'] === '') {
            $stops[$s['stop_id']] = [$s['stop_name'], round((float) $s['stop_lat'], 5), round((float) $s['stop_lon'], 5)];
        }
    }
    $trips = [];
    foreach ($rows('trips.txt') as $t) {
        $trips[$t['trip_id']] = [mta_map_base_route($t['route_id']), $t['direction_id'], $t['shape_id']];
    }

    // stop_times.txt is ~37 MB: read it row by row; rows come grouped by trip.
    $pat = $shape_of = [];
    $cur = null;
    $seq = [];
    $flush = function () use (&$cur, &$seq, &$pat, &$shape_of, $trips) {
        if ($cur !== null && $seq && isset($trips[$cur])) {
            [$rid, $dir, $shape] = $trips[$cur];
            $k = implode(',', $seq);
            $pat[$rid][$dir][$k] = ($pat[$rid][$dir][$k] ?? 0) + 1;
            $shape_of[$rid][$dir][$k][$shape] = ($shape_of[$rid][$dir][$k][$shape] ?? 0) + 1;
        }
    };
    foreach ($rows('stop_times.txt') as $r) {
        if ($r['trip_id'] !== $cur) {
            $flush();
            $cur = $r['trip_id'];
            $seq = [];
        }
        $seq[] = $parent[$r['stop_id']] ?? $r['stop_id'];
    }
    $flush();

    $need = [];
    foreach ($routes as $rid => &$route) {
        $dirs = $pat[$rid] ?? [];
        $dir = isset($dirs['1']) ? '1' : (isset($dirs['0']) ? '0' : null);
        if ($dir === null) {
            unset($routes[$rid]);
            continue;
        }
        $c = $dirs[$dir];
        arsort($c);
        $total = array_sum($c);
        $keep = [];
        foreach ($c as $k => $n) {
            $p = explode(',', $k);
            if ($n < $total * 0.03) {
                continue;
            }
            foreach ($keep as $kp) {
                if (mta_map_contains($kp[0], $p)) {
                    continue 2;
                }
            }
            $keep[] = [$p, $n, $k];
        }
        // One branch per pair of end stations: its longest pattern, with the trips of all its patterns.
        $branches = [];
        foreach ($keep as [$p, $n, $k]) {
            $ends = $p[0] . '>' . end($p);
            $branches[$ends]['pats'][] = [$p, $n];
            $branches[$ends]['n'] = ($branches[$ends]['n'] ?? 0) + $n;
            $shape = array_search(max($shape_of[$rid][$dir][$k]), $shape_of[$rid][$dir][$k]);
            $need[$shape] = true;
            $route['shapes'][$shape] = true;
        }
        uasort($branches, function ($a, $b) { return $b['n'] <=> $a['n']; });
        $route['b'] = [];
        foreach ($branches as $br) {
            usort($br['pats'], function ($a, $b) { return count($b[0]) <=> count($a[0]); });
            $longest = $br['pats'][0][0];
            $served = [];
            foreach ($br['pats'] as [$p, $n]) {
                foreach ($p as $s) {
                    $served[$s] = ($served[$s] ?? 0) + $n;
                }
            }
            $route['b'][] = [$longest[0], end($longest), $br['n'],
                             array_map(function ($s) use ($served, $br) { return [$s, $served[$s] < $br['n'] / 2 ? 1 : 0]; }, $longest)];
        }
    }
    unset($route);

    // shapes.txt: read only the shapes we use.
    $pts = [];
    foreach ($rows('shapes.txt') as $r) {
        if (isset($need[$r['shape_id']])) {
            $pts[$r['shape_id']][(int) $r['shape_pt_sequence']] = [(float) $r['shape_pt_lat'], (float) $r['shape_pt_lon']];
        }
    }
    foreach ($routes as &$route) {
        $route['sh'] = [];
        foreach (array_keys($route['shapes'] ?? []) as $id) {
            if (!empty($pts[$id])) {
                ksort($pts[$id]);
                $route['sh'][] = mta_map_encode(mta_map_simplify(array_values($pts[$id])));
            }
        }
        unset($route['shapes']);
    }
    unset($route);
    $zip->close();

    // Only the stops some branch uses.
    $used = [];
    foreach ($routes as $route) {
        foreach ($route['b'] as $b) {
            foreach ($b[3] as [$s]) {
                $used[$s] = true;
            }
        }
    }
    return ['routes' => $routes, 'stops' => array_intersect_key($stops, $used)];
}

/** Where lines.json lives: [path on disk, URL]. */
function mta_map_file()
{
    $u = wp_upload_dir(null, false);
    return [$u['basedir'] . '/mta-subway/lines.json', $u['baseurl'] . '/mta-subway/lines.json'];
}

/** Daily step: re-build lines.json when the MTA's GTFS zip has changed. */
function mta_map_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(600);
    }
    $tmp = null;
    try {
        $old = get_option(MTA_MAP_OPTION) ?: [];
        [$path] = mta_map_file();
        $head = wp_remote_head(MTA_MAP_GTFS, ['timeout' => 30]);
        $stamp = is_wp_error($head) ? '' : (wp_remote_retrieve_header($head, 'last-modified') . '|' . wp_remote_retrieve_header($head, 'content-length'));
        if ($stamp && $stamp === ($old['source'] ?? '') && file_exists($path)) {
            $old['checked'] = gmdate('c');
            update_option(MTA_MAP_OPTION, $old, false);
            delete_option('mta_ridership_map_error');
            return;
        }
        $tmp = wp_tempnam('gtfs_subway.zip');
        $res = wp_remote_get(MTA_MAP_GTFS, ['timeout' => 180, 'stream' => true, 'filename' => $tmp]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            throw new Exception('GTFS download failed: ' . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_response_code($res)));
        }
        $data = mta_map_build($tmp);
        if (count($data['routes']) < 20) {
            throw new Exception('GTFS has only ' . count($data['routes']) . ' routes');
        }
        $data['updated'] = gmdate('c');
        wp_mkdir_p(dirname($path));
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!$json || file_put_contents($path, $json) === false) {
            throw new Exception('Could not write lines.json');
        }
        update_option(MTA_MAP_OPTION, ['updated' => $data['updated'], 'checked' => gmdate('c'), 'latest' => gmdate('Y-m-d'), 'source' => $stamp,
                                       'size' => strlen($json), 'routes' => count($data['routes']), 'stops' => count($data['stops'])], false);
        delete_option('mta_ridership_map_error');
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
    } catch (Throwable $e) {
        update_option('mta_ridership_map_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_MAP_HOOK, 'mta_map_schedule');
    } finally {
        if ($tmp && file_exists($tmp)) {
            @unlink($tmp);
        }
    }
}

/** The decoded lines.json, or null before the first build. */
function mta_map_data()
{
    static $d = false;
    if ($d === false) {
        [$path] = mta_map_file();
        $d = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
    }
    return $d;
}

/* ---------- Alerts ---------- */

/**
 * The MTA alerts file reduced to what the map shows: alerts active at $now, in the MTA's own words.
 * Returns [[routes: [...], type, header, description, until], ...], soonest-ending last.
 */
function mta_map_trim_alerts($raw, $now)
{
    $text = function ($t) {
        foreach ($t['translation'] ?? [] as $x) {
            if (($x['language'] ?? '') === 'en') {
                return trim(preg_replace('/\s+/', ' ', $x['text']));
            }
        }
        return '';
    };
    $out = [];
    foreach ($raw['entity'] ?? [] as $e) {
        $a = $e['alert'] ?? [];
        // Active now: inside one of its periods (an alert with no periods is always active).
        $active = empty($a['active_period']);
        $until = null;
        foreach ($a['active_period'] ?? [] as $p) {
            if (($p['start'] ?? 0) <= $now && $now <= ($p['end'] ?? PHP_INT_MAX)) {
                $active = true;
                $until = $p['end'] ?? null;
                break;
            }
        }
        if (!$active) {
            continue;
        }
        $routes = [];
        foreach ($a['informed_entity'] ?? [] as $ie) {
            if (!empty($ie['route_id'])) {
                $routes[mta_map_base_route($ie['route_id'])] = true;
            }
        }
        $head = $text($a['header_text'] ?? []);
        if (!$routes || $head === '') {
            continue;
        }
        // strval: PHP turns the array key "6" into the number 6.
        $out[] = ['routes' => array_map('strval', array_keys($routes)), 'type' => $a['transit_realtime.mercury_alert']['alert_type'] ?? 'Alert',
                  'header' => $head, 'description' => $text($a['description_text'] ?? []), 'until' => $until];
    }
    // Delays first, then other unplanned notices, then planned work.
    usort($out, function ($a, $b) {
        $rank = function ($t) { return stripos($t, 'delay') !== false ? 0 : (stripos($t, 'planned') === 0 ? 2 : 1); };
        return $rank($a['type']) <=> $rank($b['type']);
    });
    return $out;
}

function mta_map_alerts()
{
    $cached = get_transient('mta_map_alerts');
    if ($cached !== false) {
        return $cached;
    }
    $res = wp_remote_get(MTA_MAP_ALERTS, ['timeout' => 20]);
    $raw = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
    $out = ['updated' => gmdate('c'), 'ok' => is_array($raw), 'alerts' => is_array($raw) ? mta_map_trim_alerts($raw, time()) : []];
    set_transient('mta_map_alerts', $out, is_array($raw) ? 120 : 30);
    return $out;
}

add_action('rest_api_init', function () {
    register_rest_route('mta-ridership/v1', '/alerts', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true', // public, read-only copy of the MTA's public alerts
        'callback'            => function () {
            $res = new WP_REST_Response(mta_map_alerts());
            $res->header('Cache-Control', 'public, max-age=120');
            return $res;
        },
    ]);
});

/* ---------- Pages ---------- */

/** Line page code ("JZ", "S 42nd") => GTFS route ids. */
function mta_map_routes_for_line($code)
{
    $map = ['JZ' => ['J', 'Z'], 'S 42nd' => ['GS'], 'S Fkln' => ['FS'], 'S Rock' => ['H']];
    return $map[$code] ?? [(string) $code]; // line codes like 7 can arrive as numbers
}

/** GTFS stop id => [station page URL, station name], from the station report card data. */
function mta_map_station_links()
{
    $rc = get_option(MTA_STATIONS_OPTION);
    $all = function_exists('mta_stations_all') ? mta_stations_all() : [];
    $out = [];
    foreach ($rc['gtfs'] ?? [] as $stop => $cid) {
        if (isset($all[$cid])) {
            $out[$stop] = mta_stations_url($all[$cid]['slug']);
        }
    }
    return $out;
}

/** Load the map script, with what the script needs. $routes: GTFS ids to show, or [] for all. */
function mta_map_enqueue(array $routes)
{
    [, $url] = mta_map_file();
    $opt = get_option(MTA_MAP_OPTION) ?: [];
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    // The script loads MapLibre itself, only when the map is about to scroll into view.
    wp_enqueue_script('mta-map', $base . 'assets/mta-map.js', [], '2.2.1', true);
    wp_add_inline_script('mta-map', 'window.MTA_MAP = ' . wp_json_encode([
        'data' => add_query_arg('v', substr(md5($opt['updated'] ?? ''), 0, 8), $url),
        'alerts' => rest_url('mta-ridership/v1/alerts'),
        'feed' => 'https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/nyct%2F',
        'routes' => array_map('strval', array_values($routes)),
        'links' => mta_map_station_links(),
        'look' => get_option('mta_map_look') ?: null, // street map style and colours chosen with the tester
        'tester' => mta_ridership_preview(),
    ], JSON_UNESCAPED_SLASHES) . ';', 'before');
}

/** The map box (filled by assets/mta-map.js). */
function mta_map_box($label)
{
    return '<div class="mta-live-map" data-mta-map><div class="mta-map-alerts" aria-live="polite"></div>' .
        '<div class="mta-map-canvas" role="region" aria-label="' . esc_attr($label) . '"></div>' .
        '<p class="mta-map-status mta-note">Loading the map…</p></div>';
}

/** Line page: "Live map" and "Stops on the Q train" in real order, by branch. '' when there's no data. */
function mta_map_line_sections($l, $sec)
{
    $d = mta_map_data();
    $ids = array_values(array_filter(mta_map_routes_for_line($l['code']), function ($r) use ($d) { return isset($d['routes'][$r]); }));
    if (!$d || !$ids) {
        return '';
    }
    mta_map_enqueue($ids);
    $links = mta_map_station_links();
    $o = $sec('Live map: ' . $l['name'], mta_map_box('Map of the ' . $l['name']),
        'Click a stop for the next trains. Times are the MTA\'s live estimates and update every 30 seconds. ' .
        'Alerts are the MTA\'s own words; <a href="https://www.mta.info/alerts" rel="noopener">mta.info</a> has the official status.');
    $body = '';
    foreach ($ids as $rid) {
        $r = $d['routes'][$rid];
        foreach ($r['b'] as [$from, $to, $n, $stops]) {
            $name = function ($s) use ($d) { return $d['stops'][$s][0] ?? $s; };
            $body .= '<' . mta_ridership_h(true) . '>' . esc_html($name($from) . ' – ' . $name($to)) . ' (' . count($stops) . ' stops)</' . mta_ridership_h(true) . '><ol class="mta-stop-list">';
            foreach ($stops as [$s, $part]) {
                $label = esc_html($name($s));
                $body .= '<li' . ($part ? ' class="part"' : '') . '>' . (isset($links[$s]) ? '<a href="' . esc_url($links[$s]) . '">' . $label . '</a>' : $label) .
                    ($part ? ' <span class="mta-lines">some trains only</span>' : '') . '</li>';
            }
            $body .= '</ol>';
        }
    }
    $o .= $sec('Stops on the ' . $l['name'], $body,
        'In order, from the MTA\'s published schedule. "Some trains only" = fewer than half of the trains on that branch stop there ' .
        '(for example late nights or rush hours). Check <a href="https://www.mta.info/schedules" rel="noopener">mta.info</a> before you travel.');
    return $o;
}

add_shortcode('mta_map', function () {
    if (!mta_map_live()) {
        return '';
    }
    $d = mta_map_data();
    if (!$d) {
        mta_map_schedule(5);
        return '<p>The subway map is being prepared. Check back in a few minutes.</p>';
    }
    mta_map_enqueue([]);
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.16.8');
    $sec = function ($title, $body, $note = '') {
        return '<section class="mta-section"><' . mta_ridership_h() . '>' . esc_html($title) . '</' . mta_ridership_h() . '>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
    };
    $pills = '';
    $shuttles = ['GS' => '42 St S', 'FS' => 'Franklin S', 'H' => 'Rockaway S']; // all three are "S" in the MTA data
    foreach ($d['routes'] as $rid => $r) {
        $pills .= '<button type="button" class="mta-bullet' . (isset($shuttles[$rid]) ? ' wide' : '') . '" data-route="' . esc_attr($rid) . '" style="background:#' . esc_attr($r['c']) . ';color:#' . esc_attr($r['t']) .
            '" aria-pressed="false" title="' . esc_attr($r['n']) . '">' . esc_html($shuttles[$rid] ?? $r['s']) . '</button>';
    }
    $o = '<header class="mta-hero"><h1>Live NYC Subway Map: Train Times &amp; Service Alerts</h1>' .
        '<p class="mta-hero-links">Every line, the next trains at any stop and current service alerts, from the MTA\'s live data · Not affiliated with the MTA · ' .
        '<a href="https://www.mta.info/" rel="noopener">Official information: mta.info &rarr;</a></p></header>';
    $o .= $sec('Pick a line', '<div class="mta-bullets" data-mta-picker>' . $pills . '<button type="button" class="mta-find-pick on" data-route="">All lines</button></div>',
        'Pick a line to highlight it, then click a stop for the next trains.');
    $o .= '<section class="mta-section">' . mta_map_box('Map of the NYC subway') . '</section>';
    $o .= '<p class="mta-source mta-source-ssr">Data: MTA <a href="https://api.mta.info/" rel="noopener">real-time feeds</a> and ' .
        '<a href="https://new.mta.info/developers" rel="noopener">GTFS schedule</a>. Train times are the MTA\'s live estimates. ' .
        'Street map: OpenFreeMap, OpenMapTiles, &copy; OpenStreetMap contributors. Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a>.</p>';
    return '<div class="mta-ridership mta-map-page">' . $o . mta_ridership_disclaimer() . '</div>';
});

// The WordPress map stays in review (?mta_preview=1); everyone else goes to the map app.
add_action('template_redirect', function () {
    if (is_page(MTA_MAP_SLUG) && !mta_map_live()) {
        wp_redirect(mta_map_app_url(), 301);
        exit;
    }
});
add_filter('wp_robots', function ($r) {
    return is_page(MTA_MAP_SLUG) && mta_ridership_preview() ? ['noindex' => true, 'nofollow' => true] + $r : $r;
});
