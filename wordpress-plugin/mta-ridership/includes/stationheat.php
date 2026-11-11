<?php
/**
 * Station pages: a "when is it busy" heat map (24 hours x 7 weekdays), the station's personality and its year calendar.
 * Also the "station personalities" overview on /stations/.
 *
 * Data: data/station-heat.json, built by tools/build_station_heat.py and tools/build_station_profiles.py
 * (average riders entering per hour and weekday over the last 26 weeks, by station slug). The calendar uses the
 * saved daily history, with the same method as calendar.php (day vs the median of the same weekday over the 26 weeks before).
 *
 * In review: shown only with ?mta_preview=1 until the owner says it can go live.
 */

if (!defined('ABSPATH')) {
    exit;
}

function mta_heat_live()
{
    return mta_ridership_preview(); // return true to put it live
}

/** The slug-keyed data file, read once per request. */
function mta_heat_data()
{
    static $d = false;
    if ($d === false) {
        $raw = @file_get_contents(dirname(__DIR__) . '/data/station-heat.json');
        $d = $raw ? json_decode($raw, true) : null;
        if (!is_array($d) || empty($d['s'])) {
            $d = null;
        }
    }
    return $d;
}

/** Personality types: code => [short name, plain sentence]. The rule behind each is in tools/build_station_profiles.py. */
function mta_heat_types()
{
    return [
        'morning' => ['Morning commuter stop', 'On weekdays far more riders enter in the morning rush (6-10 AM) than in the evening rush, the usual pattern for a neighborhood where people live.'],
        'evening' => ['Evening rush stop', 'On weekdays far more riders enter in the evening rush (4-8 PM) than in the morning, the usual pattern for an office, school or entertainment area people head home from.'],
        'allday'  => ['All-day crowd', 'Riders enter steadily through the day, and the morning and evening rushes are of similar size.'],
        'night'   => ['Late-night crowd', 'At least 11% of the week\'s entries happen between 10 PM and 4 AM. For the whole system it is about 6.5%.'],
        'weekend' => ['Weekend spot', 'Saturdays and Sundays are nearly as busy as weekdays (at least 85%). For the whole system it is about 62%.'],
    ];
}

function mta_heat_personality($slug)
{
    $d = mta_heat_data();
    $t = $d['p'][$slug] ?? null;
    $types = mta_heat_types();
    return $t && isset($types[$t]) ? ['code' => $t, 'name' => $types[$t][0], 'why' => $types[$t][1]] : null;
}

function mta_heat_day_names()
{
    return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
}

function mta_heat_hour_short($h)
{
    return ($h % 12 ?: 12) . ($h < 12 ? 'a' : 'p');
}

/** The hour-by-weekday grid as HTML, plus a plain sentence summary of what it shows. Returns '' when the station has no data. */
function mta_heat_station_section($st, $sec)
{
    if (!mta_heat_live() || !($d = mta_heat_data()) || empty($d['s'][$st['slug']])) {
        return '';
    }
    $v = $d['s'][$st['slug']];
    if (count($v) !== 168 || max($v) <= 0) {
        return '';
    }
    $name = mta_stations_display($st['name']);
    $days = mta_heat_day_names();
    $max = max($v);
    $peak = array_search($max, $v);

    // Calmest daytime hour (6 AM - 10 PM) on weekdays and on weekends, averaged over the days.
    $calm = function ($from_day, $to_day) use ($v) {
        $best = null;
        for ($h = 6; $h < 22; $h++) {
            $sum = 0;
            for ($dd = $from_day; $dd <= $to_day; $dd++) {
                $sum += $v[$dd * 24 + $h];
            }
            $avg = $sum / ($to_day - $from_day + 1);
            if ($best === null || $avg < $best[1]) {
                $best = [$h, $avg];
            }
        }
        return $best;
    };
    $cw = $calm(0, 4);
    $ce = $calm(5, 6);
    $wk_total = array_sum(array_slice($v, 0, 120)) / 5;
    $we_total = array_sum(array_slice($v, 120, 48)) / 2;

    $cells = '<span class="mta-hm-corner"></span>';
    for ($h = 0; $h < 24; $h++) {
        $cells .= '<span class="mta-hm-hour" aria-hidden="true">' . ($h % 3 === 0 ? esc_html(mta_heat_hour_short($h)) : '') . '</span>';
    }
    for ($dd = 0; $dd < 7; $dd++) {
        $cells .= '<span class="mta-hm-day" aria-hidden="true">' . esc_html(substr($days[$dd], 0, 3)) . '</span>';
        for ($h = 0; $h < 24; $h++) {
            $x = $v[$dd * 24 + $h];
            $a = $x > 0 ? round(0.08 + 0.92 * sqrt($x / $max), 3) : 0;
            $cells .= '<i style="--a:' . $a . '" title="' . esc_attr($days[$dd] . ' ' . mta_ridership_hour_name($h) . ': about ' . number_format($x) . ' riders') . '"></i>';
        }
    }
    $legend = '<p class="mta-hm-legend" aria-hidden="true"><span>Quieter</span><span class="mta-hm-sw"><i style="--a:.1"></i><i style="--a:.35"></i><i style="--a:.6"></i><i style="--a:.8"></i><i style="--a:1"></i></span><span>Busier</span></p>';

    $summary = 'The busiest time at ' . $name . ' is ' . $days[intdiv($peak, 24)] . ' at ' . mta_ridership_hour_name($peak % 24) .
        ', with about ' . number_format($max) . ' riders entering in that hour. ' .
        'On weekdays the calmest daytime hour (6 AM to 10 PM) is ' . mta_ridership_hour_name($cw[0]) . ', with about ' . number_format(round($cw[1])) .
        ' riders; on weekends it is ' . mta_ridership_hour_name($ce[0]) . ' (about ' . number_format(round($ce[1])) . '). ' .
        'A typical weekend day has about ' . round($we_total / max(1, $wk_total) * 100) . '% of the riders of a weekday.';

    $body = '<p>' . esc_html($summary) . '</p>' .
        '<div class="mta-hm" role="img" aria-label="' . esc_attr('Heat map of riders entering ' . $name . ' by weekday and hour. ' . $summary) . '">' . $cells . '</div>' . $legend;

    $o = $sec('When should I go to ' . $name . '?', $body,
        'Average riders entering per hour, ' . esc_html(mta_ridership_nice_date($d['from']) . ' – ' . mta_ridership_nice_date($d['to'])) .
        ' (' . (int) $d['weeks'] . ' weeks). Brighter = more riders. This counts riders entering the station, not how crowded the platform or trains are.');

    if ($p = mta_heat_personality($st['slug'])) {
        $o .= $sec($name . ' station personality: ' . $p['name'], '<p>' . esc_html($p['why']) . '</p>',
            'A pattern worked out from the hourly numbers above, not a count of who the riders are. <a href="' . esc_url(mta_stations_link()) . '#personalities">See all station personalities &rarr;</a>');
    }
    $o .= mta_heat_calendar_section($st, $sec);
    return $o;
}

/** Colour of a day vs a normal day of that weekday (same scale as the system calendar on /mta-ridership/). */
function mta_heat_cal_color($x)
{
    return $x === null ? '#1f2937' : ($x < 0.45 ? '#b91c1c' : ($x < 0.6 ? '#ea580c' : ($x < 0.75 ? '#f59e0b' :
        ($x < 0.85 ? '#facc15' : ($x < 1.15 ? '#334155' : ($x < 1.25 ? '#22c55e' : '#16a34a'))))));
}

/** One station's year calendar: each day vs the median of the same weekday over the 26 weeks before. */
function mta_heat_calendar_section($st, $sec)
{
    $h = mta_ridership_history_load();
    $series = $h['st'][$st['id']] ?? null;
    if (!$series || empty($h['ndays'])) {
        return '';
    }
    $labels = defined('MTA_RIDERSHIP_EVENTS') ? MTA_RIDERSHIP_EVENTS : [];
    $first_y = (int) substr($h['start'], 0, 4);
    $last_y = (int) substr(mta_ridership_history_last_day($h), 0, 4);
    if (function_exists('mta_ridership_holidays')) {
        for ($y = $first_y; $y <= $last_y; $y++) {
            $labels += mta_ridership_holidays($y);
        }
    }
    $days = [];
    for ($i = 0; $i < $h['ndays']; $i++) {
        if (!isset($series[$i])) {
            continue;
        }
        $b = [];
        for ($k = 1; $k <= 26; $k++) {
            if ($i - 7 * $k >= 0 && isset($series[$i - 7 * $k])) {
                $b[] = $series[$i - 7 * $k];
            }
        }
        $med = count($b) >= 4 ? mta_ridership_median($b) : 0;
        $date = mta_ridership_next_day($h['start'], $i);
        $days[] = ['d' => $date, 'r' => $series[$i], 'x' => $med > 0 ? round($series[$i] / $med, 3) : null, 'l' => $labels[$date] ?? null];
    }
    if (count($days) < 60) {
        return '';
    }
    $name = mta_stations_display($st['name']);
    $dn = mta_heat_day_names();
    $year = substr($days[count($days) - 1]['d'], 0, 4);
    $recent = array_values(array_filter($days, function ($d) use ($year) { return substr($d['d'], 0, 4) === $year; }));
    $pad = (int) gmdate('N', strtotime($recent[0]['d'] . ' UTC')) - 1;
    $cells = str_repeat('<i class="mta-cal-pad"></i>', $pad);
    foreach ($recent as $d) {
        $wd = $dn[(int) gmdate('N', strtotime($d['d'] . ' UTC')) - 1];
        $tip = $wd . ' ' . mta_ridership_nice_date($d['d']) . ($d['l'] ? ' (' . $d['l'] . ')' : '') . ': ' . number_format($d['r']) . ' riders' .
            ($d['x'] ? ', ' . round($d['x'] * 100) . '% of a normal ' . $wd : '');
        $cells .= '<i' . ($d['l'] ? ' class="lab"' : '') . ' style="background:' . mta_heat_cal_color($d['x']) . '" title="' . esc_attr($tip) . '"></i>';
    }
    $rated = array_values(array_filter($recent, function ($d) { return $d['x'] !== null && $d['r'] >= 200; }));
    usort($rated, function ($a, $b) { return $a['x'] <=> $b['x']; });
    $line = function ($list) {
        return implode('; ', array_map(function ($d) {
            return mta_ridership_nice_date($d['d']) . ($d['l'] ? ' (' . $d['l'] . ')' : '') . ' at ' . round($d['x'] * 100) . '%';
        }, $list));
    };
    $text = '';
    if (count($rated) >= 6) {
        $text = '<p>Quietest days compared with a normal day: ' . esc_html($line(array_slice($rated, 0, 3))) . '. Busiest: ' . esc_html($line(array_reverse(array_slice($rated, -3)))) . '.</p>';
    }
    $legend = '<p class="mta-cal-legend" aria-hidden="true"><span>Much quieter</span><span class="mta-cal-swatches">';
    foreach ([0.4, 0.55, 0.7, 0.8, 1, 1.2, 1.3] as $x) {
        $legend .= '<i style="background:' . mta_heat_cal_color($x) . '"></i>';
    }
    $legend .= '</span><span>Much busier</span></p>';
    return $sec('Busy and quiet days at ' . $name . ', ' . $year, $text .
        '<div class="mta-cal-wrap"><div class="mta-cal mta-cal-st" role="img" aria-label="' . esc_attr('Calendar of daily riders at ' . $name . ' in ' . $year) . '">' . $cells . '</div></div>' . $legend,
        'Each square is one day, colored by riders compared with a normal day of that weekday (the median of the 26 weeks before). Outlined squares are holidays and events. Hover over a day for the number.');
}

/** /stations/: how many stations of each personality, with the busiest examples and a map of them all. */
function mta_heat_ranking_section($all, $sec)
{
    if (!mta_heat_live() || !($d = mta_heat_data())) {
        return '';
    }
    $types = mta_heat_types();
    $by = [];
    foreach ($all as $s) {
        $t = $d['p'][$s['slug']] ?? null;
        if ($t && isset($types[$t])) {
            $by[$t][] = $s;
        }
    }
    if (!$by) {
        return '';
    }
    $colors = ['morning' => '#7cc4ff', 'evening' => '#ffc233', 'allday' => '#a78bfa', 'night' => '#f472b6', 'weekend' => '#34d399'];
    $o = '<div class="mta-pers-grid" id="personalities">';
    foreach ($types as $code => $info) {
        if (empty($by[$code])) {
            continue;
        }
        $list = $by[$code];
        usort($list, function ($a, $b) { return $b['avg'] <=> $a['avg']; });
        $links = implode(', ', array_map(function ($s) {
            return '<a href="' . esc_url(mta_stations_link($s['slug'])) . '">' . esc_html(mta_stations_display($s['name'])) . '</a>';
        }, array_slice($list, 0, 6)));
        $o .= '<div class="mta-pers-card"><h3><span class="mta-pers-dot" style="background:' . $colors[$code] . '"></span>' . esc_html($info[0]) .
            ' <small>' . count($list) . ' stations</small></h3><p>' . esc_html($info[1]) . '</p><p>Busiest: ' . $links . '.</p></div>';
    }
    $o .= '</div>';

    // A real map (Leaflet, drawn by mta-station-maps.js): one dot per station, coloured by personality, sized by riders.
    // Hover shows the name; a click opens the station page.
    $pts = [];
    foreach ($all as $s) {
        $t = $d['p'][$s['slug']] ?? null;
        if ($t && isset($types[$t]) && $s['lat'] !== null && $s['lon'] !== null) {
            $pts[] = [mta_stations_display($s['name']), $s['slug'], implode(' ', mta_stations_lines_of($s['name'])),
                round($s['lat'], 5), round($s['lon'], 5), (int) $s['avg'], $t];
        }
    }
    if ($pts) {
        usort($pts, function ($a, $b) { return $b[5] <=> $a[5]; });
        $legend = [];
        foreach ($types as $code => $info) {
            if (!empty($by[$code])) {
                $legend[$code] = [$info[0], $colors[$code]];
            }
        }
        $json = wp_json_encode(['s' => $pts, 'types' => $legend, 'base' => trailingslashit(mta_stations_url())]);
        $o .= '<div data-slot="persmap" class="mta-persmap"><p class="mta-pers-hint">Loading the map…</p></div>' .
            '<script type="application/json" id="mta-persmap-data">' . str_replace('</', '<\/', $json) . '</script>';
    }
    return $sec('Station personalities', $o,
        'Each station is tagged from the shape of its week (riders entering by hour and weekday, last ' . (int) $d['weeks'] . ' weeks). Most stations are in residential neighborhoods, so most are morning commuter stops.');
}

/** Night share for one station's week: entries 10 PM - 4 AM (every day) over all entries. [share, night riders a week, all riders a week]. */
function mta_heat_night($week)
{
    $night = 0;
    for ($d = 0; $d < 7; $d++) {
        foreach ([22, 23, 0, 1, 2, 3] as $h) {
            $night += $week[$d * 24 + $h];
        }
    }
    $tot = array_sum($week);
    return [$tot ? $night / $tot : 0, $night, $tot];
}

/** /stations/: stations with the most night-time riders compared with others. */
function mta_heat_night_section($all, $sec)
{
    if (!mta_heat_live() || !($d = mta_heat_data())) {
        return '';
    }
    $rows = [];
    $sumN = $sumT = 0;
    foreach ($all as $s) {
        $w = $d['s'][$s['slug']] ?? null;
        if (!$w || count($w) !== 168) {
            continue;
        }
        [$share, $n, $t] = mta_heat_night($w);
        $sumN += $n;
        $sumT += $t;
        $rows[] = ['s' => $s, 'share' => $share, 'n' => $n, 't' => $t];
    }
    if (!$rows || !$sumT) {
        return '';
    }
    $avg = $sumN / $sumT;
    $link = function ($s) {
        return '<a href="' . esc_url(mta_stations_link($s['slug'])) . '">' . esc_html(mta_stations_display($s['name'])) . '</a>';
    };
    $pct = function ($x) { return number_format($x * 100, 1) . '%'; };
    // Small stations swing a lot, so the share ranking needs at least 20,000 riders a week.
    $big = array_values(array_filter($rows, function ($r) { return $r['t'] >= 20000; }));
    usort($big, function ($a, $b) { return $b['share'] <=> $a['share']; });
    $tr = '';
    foreach (array_slice($big, 0, 15) as $i => $r) {
        $tr .= '<tr><td>' . ($i + 1) . '</td><td>' . $link($r['s']) . '</td><td>' . esc_html(implode(' ', mta_stations_lines_of($r['s']['name']))) . '</td><td>' . $pct($r['share']) . '</td><td>' .
            number_format($r['share'] / $avg, 1) . '×</td><td>' . number_format($r['n']) . '</td></tr>';
    }
    $byCount = $rows;
    usort($byCount, function ($a, $b) { return $b['n'] <=> $a['n']; });
    $quiet = array_slice(array_reverse($big), 0, 5);
    $o = '<p>Across the subway, <strong>' . $pct($avg) . '</strong> of riders enter between 10 PM and 4 AM. These stations get far more of their riders at night than that.</p>' .
        '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th>#</th><th>Station</th><th>Lines</th><th>Share at night</th><th>vs average</th><th>Night riders a week</th></tr></thead><tbody>' . $tr . '</tbody></table></div>' .
        '<p><strong>Most night riders in total:</strong> ' . implode(', ', array_map(function ($r) use ($link) {
            return $link($r['s']) . ' (' . number_format($r['n']) . ' a week)';
        }, array_slice($byCount, 0, 5))) . '.</p>' .
        '<p><strong>Quietest at night:</strong> ' . implode(', ', array_map(function ($r) use ($link, $pct) {
            return $link($r['s']) . ' (' . $pct($r['share']) . ')';
        }, $quiet)) . '. These are mostly residential stops where riders leave in the morning and come home in the evening.</p>' .
        '<p>Why: nightlife (West Village, the Lower East Side, Bushwick), night games (Mets–Willets Point and Yankee Stadium during the season) and Broadway shows letting out (49 and 50 St).</p>';
    return '<div id="night-riders"></div>' . $sec('Stations with the most night riders', $o,
        'Riders entering between 10 PM and 4 AM, every day of the week, as a share of all riders entering, from MTA hourly ridership (' .
        esc_html($d['from']) . ' to ' . esc_html($d['to']) . '). Stations with fewer than 20,000 riders a week are left out of the ranking. It counts entries, not exits.');
}
