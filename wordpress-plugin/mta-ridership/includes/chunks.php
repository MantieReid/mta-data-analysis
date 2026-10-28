<?php
/**
 * Keeps the ridership page's first load small. The three biggest pieces of data (station map points with
 * hourly riders, the full station list for "Find your station", and the holidays calendar) are not written
 * into the page; the browser fetches them from a read-only endpoint, with a version in the address so it
 * can keep them cached, when they are needed: the map and calendar as they near the screen, the station
 * list when the page is idle or the search box is used. The page embeds only the top five stations, which
 * is enough to show the first card and the popular buttons instantly.
 *
 * The endpoint only ever returns one of the three stored blocks, chosen from a fixed list: no query
 * parameters, no database or API work, nothing an outsider can make expensive.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Chunk name => where it comes from. Anything not listed here is a 404. */
function mta_ridership_chunk_names()
{
    return ['stations', 'lookup', 'calendar'];
}

/** The stored data for one chunk, or null if the name isn't allowed or the data isn't built yet. */
function mta_ridership_chunk($name)
{
    if (!in_array($name, mta_ridership_chunk_names(), true)) {
        return null;
    }
    if ($name === 'calendar') {
        $c = get_option(MTA_RIDERSHIP_CALENDAR_OPTION);
        return $c ?: null;
    }
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    return !empty($x[$name]) ? $x[$name] : null;
}

/** Changes whenever the data behind the chunks is rebuilt, so browsers fetch fresh copies. */
function mta_ridership_chunk_version()
{
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    $c = get_option(MTA_RIDERSHIP_CALENDAR_OPTION);
    return substr(md5(($x['updated'] ?? '') . '|' . ($c['updated'] ?? '')), 0, 8);
}

/**
 * The page payload without the big blocks. Adds 'lookup_top' (top five stations with their hourly riders,
 * so the first station card and the popular buttons need no extra request), 'lookup_total' and
 * 'chunks' (where to fetch the rest). $show is the list of sections on the page.
 */
function mta_ridership_lazy_payload(array $data, array $show)
{
    $x = $data['extras'] ?? null;
    if ($x && !empty($x['lookup'])) {
        $hours = [];
        foreach ($x['stations'] ?? [] as $s) {
            $hours[$s['id']] = $s['hours'];
        }
        $top = [];
        foreach (array_slice($x['lookup'], 0, 5) as $row) {
            $top[] = $row + ['hours' => $hours[$row['id']] ?? null];
        }
        $data['lookup_top'] = $top;
        $data['lookup_total'] = count($x['lookup']);
    }
    unset($data['extras']['stations'], $data['extras']['lookup']);
    $has_cal = in_array('holidays', $show, true);
    unset($data['calendar']);
    $data['chunks'] = ['url' => rest_url('mta-ridership/v1/chunk/'), 'v' => mta_ridership_chunk_version(), 'calendar' => $has_cal];
    return $data;
}

add_action('rest_api_init', function () {
    register_rest_route('mta-ridership/v1', '/chunk/(?P<name>[a-z]+)', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true', // public, read-only copy of data the page already shows
        'callback'            => function ($req) {
            $d = mta_ridership_chunk((string) $req['name']);
            if ($d === null) {
                return new WP_Error('mta_no_chunk', 'Not found', ['status' => 404]);
            }
            $res = new WP_REST_Response($d);
            $res->header('Cache-Control', 'public, max-age=86400'); // the address changes when the data does
            return $res;
        },
    ]);
});
