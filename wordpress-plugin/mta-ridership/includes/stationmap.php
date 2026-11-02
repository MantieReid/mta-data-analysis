<?php
/**
 * Station map on the Stations page (/stations/): every subway station as a circle sized by riders a day, with two views:
 * - "Growing or shrinking": this year so far vs the same days last year (green growing, red shrinking);
 * - "Elevators working": share of hours the station's elevators were in service over the last 12 months
 *   (red for the least, green for the most; stations without elevators shown as small grey dots).
 * Built from data the plugin already keeps (no extra MTA queries): mta_stations_all() and the monthly report card data.
 * Live since 2026-11-02; mta_stationmap_live() switches it off again if needed.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The station map is live (since 2026-11-02); return mta_ridership_preview() to put it back in review. */
function mta_stationmap_live()
{
    return true;
}

/**
 * The map's data. Pure part, for tests: $all is mta_stations_all(), $rc the report card option.
 * {s: [[name, slug, lines, lat, lon, riders a day, % vs last year or null, % elevators working or null, elevators], ...]}
 */
function mta_stationmap_data(array $all, array $rc)
{
    $out = [];
    foreach ($all as $id => $s) {
        if ($s['lat'] === null || $s['lon'] === null) {
            continue;
        }
        $e = $rc['stations'][(string) $id]['equip']['elevator'] ?? null;
        $out[] = [mta_stations_display($s['name']), $s['slug'], implode(' ', mta_stations_lines_of($s['name'])), round((float) $s['lat'], 5), round((float) $s['lon'], 5),
                  (int) $s['avg'], $s['yoy'], $e ? (float) $e['avail'] : null, $e ? (int) $e['units'] : 0];
    }
    usort($out, function ($a, $b) { return $b[5] <=> $a[5]; }); // busiest first, so small stations draw on top
    return $out;
}

/** The section for the Stations page, or '' when it's not ready. */
function mta_stationmap_section($sec)
{
    if (!mta_stationmap_live()) {
        return '';
    }
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
    $rc = get_option(MTA_STATIONS_OPTION) ?: [];
    $data = mta_stationmap_data(mta_stations_all(), $rc);
    if (!$data) {
        return '';
    }
    $month = function ($ym) { return gmdate('F Y', strtotime("$ym-01 UTC")); };
    $yr = $x['yoy']['year'] ?? '';
    $json = wp_json_encode(['s' => $data, 'base' => trailingslashit(mta_stations_link())], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    return $sec('Map: which stations are growing, and where elevators work',
        '<p>Every subway station, sized by riders a day. Pick a view: which stations are gaining or losing riders this year, ' .
        'or how often each station’s elevators were working. Tap a station for its numbers and a link to its page.</p>' .
        '<div data-slot="stmap"></div><script type="application/json" id="mta-stmap-data">' . $json . '</script>',
        esc_html("Growing or shrinking: $yr so far vs the same days last year (stations with 100+ riders a day then). ") .
        (!empty($rc['equip_from']) ? esc_html('Elevators working: share of hours in service over 24 hours, ' . $month($rc['equip_from']) . ' – ' . $month($rc['equip_to']) .
            ' (MTA availability), for the elevators NYC Transit runs.') : ''));
}
