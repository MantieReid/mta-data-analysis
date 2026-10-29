<?php
/**
 * Congestion pricing page, [mta_congestion]: a plain guide (zone, hours, tolls, calculator) plus what the
 * MTA's own dashboards don't show: this year vs last year on the same days, entries by bridge and tunnel,
 * who pays, when vehicles enter, and subway ridership inside the zone vs outside.
 * Data: MTA Congestion Relief Zone Vehicle Entries (t6yz-b64h) and the zone outline (srxy-5nxn).
 * Runs as its own cron step after the extras step.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_CONGESTION_API      = 'https://data.ny.gov/resource/t6yz-b64h.json';
const MTA_CONGESTION_ZONE_API = 'https://data.ny.gov/resource/srxy-5nxn.json';
const MTA_CONGESTION_HOOK     = 'mta_ridership_congestion_step';
const MTA_CONGESTION_OPTION   = 'mta_ridership_congestion';
const MTA_CONGESTION_START    = '2025-01-05'; // tolling began Sunday, January 5, 2025

add_action(MTA_CONGESTION_HOOK, function () { mta_ridership_run_step('congestion'); });

add_action('init', function () {
    if (!get_option(MTA_CONGESTION_OPTION) && get_option(MTA_RIDERSHIP_EXTRAS_OPTION)) {
        mta_congestion_schedule(10);
    }
});

function mta_congestion_schedule($delay)
{
    if (!wp_next_scheduled(MTA_CONGESTION_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_CONGESTION_HOOK);
    }
}

function mta_congestion_sum(array $params)
{
    return mta_ridership_api(MTA_CONGESTION_API, $params + ['$limit' => 5000]);
}

/** Ray casting: is [lat, lon] inside the polygon (a list of [lat, lon])? */
function mta_congestion_inside($lat, $lon, $poly)
{
    $in = false;
    for ($i = 0, $j = count($poly) - 1; $i < count($poly); $j = $i++) {
        [$ya, $xa] = $poly[$i];
        [$yb, $xb] = $poly[$j];
        if (($ya > $lat) !== ($yb > $lat) && $lon < ($xb - $xa) * ($lat - $ya) / ($yb - $ya) + $xa) {
            $in = !$in;
        }
    }
    return $in;
}

function mta_congestion_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    try {
        // Vehicles entering the zone per day.
        $daily = [];
        foreach (mta_congestion_sum([
            '$select' => 'toll_date AS d, sum(crz_entries) AS n', '$group' => 'd', '$order' => 'd',
        ]) as $r) {
            $daily[substr($r['d'], 0, 10)] = (int) round($r['n']);
        }
        if (!$daily) {
            throw new Exception('No vehicle entries returned');
        }
        $latest = array_key_last($daily);
        $year = (int) substr($latest, 0, 4);
        $md = substr($latest, 5);

        // This year vs last year on the same days (Jan 5 to the latest day; tolling started Jan 5, 2025).
        $range = function ($y) use ($md) { return ["$y-01-05", "$y-$md"]; };
        $sum_days = function ($from, $to) use ($daily) {
            $n = $d = 0;
            foreach ($daily as $day => $v) {
                if ($day >= $from && $day <= $to) {
                    $n += $v;
                    $d++;
                }
            }
            return [$n, $d];
        };
        [$cur_n, $cur_d] = $sum_days(...$range($year));
        [$prev_n, $prev_d] = $year > 2025 ? $sum_days(...$range($year - 1)) : [0, 0];

        // Average entries a day per month, per year.
        $months = [];
        foreach ($daily as $day => $v) {
            $y = substr($day, 0, 4);
            $m = (int) substr($day, 5, 2);
            $months[$y][$m][0] = ($months[$y][$m][0] ?? 0) + $v;
            $months[$y][$m][1] = ($months[$y][$m][1] ?? 0) + 1;
        }
        $monthly = [];
        foreach ($months as $y => $ms) {
            foreach ($ms as $m => [$n, $d]) {
                $monthly[$y][$m] = (int) round($n / $d);
            }
        }

        // Entries by bridge, tunnel or street, same days both years.
        $where = function ($y) use ($range) {
            [$a, $b] = $range($y);
            return "toll_date >= '$a' AND toll_date <= '{$b}T23:59:59'";
        };
        $by_group = function ($y) use ($where) {
            $out = [];
            foreach (mta_congestion_sum([
                '$select' => 'detection_group AS g, sum(crz_entries) AS n', '$where' => $where($y), '$group' => 'g',
            ]) as $r) {
                $out[$r['g']] = (float) $r['n'];
            }
            return $out;
        };
        $g_cur = $by_group($year);
        $g_prev = $year > 2025 ? $by_group($year - 1) : [];
        $entries = [];
        foreach ($g_cur as $g => $n) {
            $p = $g_prev[$g] ?? 0;
            $entries[] = ['name' => $g, 'per_day' => (int) round($n / max(1, $cur_d)),
                          'pct' => $p > 0 ? round(($n / max(1, $cur_d)) / ($p / max(1, $prev_d)) * 100 - 100, 1) : null];
        }
        usort($entries, function ($a, $b) { return $b['per_day'] <=> $a['per_day']; });

        // Who pays: vehicle type and toll period over the last 365 days.
        $year_from = mta_ridership_next_day($latest, -364);
        $vehicles = [];
        foreach (mta_congestion_sum([
            '$select' => 'vehicle_class AS c, time_period AS p, sum(crz_entries) AS n',
            '$where' => "toll_date >= '$year_from'", '$group' => 'c, p',
        ]) as $r) {
            $c = preg_replace('/^\d+ - /', '', $r['c']);
            $vehicles[$c][strtolower($r['p'])] = (int) round($r['n']);
        }
        uasort($vehicles, function ($a, $b) { return array_sum($b) <=> array_sum($a); });

        // When vehicles enter: average per hour on weekdays and weekends, last 8 weeks.
        $hour_from = mta_ridership_next_day($latest, -55);
        $wd_days = $we_days = 0;
        foreach ($daily as $day => $v) {
            if ($day >= $hour_from) {
                gmdate('N', strtotime("$day UTC")) >= 6 ? $we_days++ : $wd_days++;
            }
        }
        $hours = ['weekday' => array_fill(0, 24, 0), 'weekend' => array_fill(0, 24, 0)];
        foreach (mta_congestion_sum([
            '$select' => 'day_of_week_int AS w, hour_of_day AS h, sum(crz_entries) AS n',
            '$where' => "toll_date >= '$hour_from'", '$group' => 'w, h',
        ]) as $r) {
            $k = in_array((int) $r['w'], [1, 7], true) ? 'weekend' : 'weekday'; // 1 = Sunday, 7 = Saturday
            $hours[$k][(int) $r['h']] += (float) $r['n'];
        }
        foreach ($hours as $k => $hs) {
            $days = $k === 'weekend' ? $we_days : $wd_days;
            $hours[$k] = array_map(function ($v) use ($days) { return (int) round($v / max(1, $days)); }, $hs);
        }

        // The zone outline (rarely changes: reuse the saved one).
        $old = get_option(MTA_CONGESTION_OPTION) ?: [];
        $zone = $old['zone'] ?? [];
        if (!$zone) {
            foreach (mta_ridership_api(MTA_CONGESTION_ZONE_API, ['$limit' => 100]) as $r) {
                foreach ($r['polygon']['coordinates'] as $ring) {
                    $zone[] = array_map(function ($p) { return [round($p[1], 5), round($p[0], 5)]; }, $ring);
                }
            }
        }

        // Subway riders at stations inside the zone vs everywhere else, same days both years.
        $subway = null;
        $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
        $h = mta_ridership_history_load();
        $data = get_option(MTA_RIDERSHIP_OPTION);
        if ($x && $h && $data) {
            $in_zone = [];
            foreach ($x['stations'] as $s) {
                foreach ($zone as $poly) {
                    if (mta_congestion_inside($s['lat'], $s['lon'], $poly)) {
                        $in_zone[$s['id']] = true;
                        break;
                    }
                }
            }
            $s_latest = $data['latest_day'];
            $sy = (int) substr($s_latest, 0, 4);
            $smd = substr($s_latest, 5);
            $tot = ['in' => [0, 0], 'out' => [0, 0]];
            $per = [];
            $dates = [];
            for ($i = 0; $i < $h['ndays']; $i++) {
                $dates[$i] = mta_ridership_next_day($h['start'], $i);
            }
            foreach ($h['st'] as $sid => $series) {
                foreach ($series as $i => $v) {
                    $d = $dates[$i] ?? mta_ridership_next_day($h['start'], $i);
                    $y = (int) substr($d, 0, 4);
                    if (substr($d, 5) < '01-05' || substr($d, 5) > $smd || ($y !== $sy && $y !== $sy - 1)) {
                        continue;
                    }
                    $k = $y === $sy ? 0 : 1;
                    $tot[isset($in_zone[$sid]) ? 'in' : 'out'][$k] += $v;
                    if (isset($in_zone[$sid])) {
                        $per[$sid][$k] = ($per[$sid][$k] ?? 0) + $v;
                    }
                }
            }
            $days_s = mta_ridership_day_index("$sy-01-05", $s_latest) + 1;
            $moves = [];
            foreach ($per as $sid => $v) {
                if (($v[1] ?? 0) >= $days_s * 2000 && !empty($v[0])) {
                    $moves[] = ['name' => $h['names'][$sid][0], 'per_day' => (int) round($v[0] / $days_s),
                                'pct' => round(($v[0] / $v[1] - 1) * 100, 1)];
                }
            }
            usort($moves, function ($a, $b) { return $b['per_day'] <=> $a['per_day']; });
            $pct = function ($c, $p) { return $p > 0 ? round(($c / $p - 1) * 100, 1) : null; };
            $subway = ['through' => $s_latest, 'year' => $sy, 'stations' => count($in_zone),
                       'in_per_day' => (int) round($tot['in'][0] / $days_s), 'in_pct' => $pct(...$tot['in']),
                       'out_per_day' => (int) round($tot['out'][0] / $days_s), 'out_pct' => $pct(...$tot['out']),
                       'busiest' => array_slice($moves, 0, 10),
                       'points' => array_values(array_map(function ($s) { return [$s['lat'], $s['lon']]; },
                           array_filter($x['stations'], function ($s) use ($in_zone) { return isset($in_zone[$s['id']]); })))];
        }

        update_option(MTA_CONGESTION_OPTION, [
            'updated' => gmdate('c'), 'latest' => $latest, 'year' => $year, 'from' => $range($year)[0],
            'total' => array_sum($daily), 'cur_per_day' => (int) round($cur_n / max(1, $cur_d)),
            'prev_per_day' => $prev_d ? (int) round($prev_n / $prev_d) : null,
            'pct' => $prev_n ? round(($cur_n / $cur_d) / ($prev_n / $prev_d) * 100 - 100, 1) : null,
            'monthly' => $monthly, 'entries' => $entries, 'vehicles' => $vehicles, 'vehicles_from' => $year_from,
            'hours' => $hours, 'hours_from' => $hour_from, 'zone' => $zone, 'subway' => $subway,
        ], false);
        delete_option('mta_ridership_congestion_error');
        mta_congestion_clear_cache();
        mta_lines_schedule(60); // line report cards: next cron request
    } catch (Throwable $e) {
        update_option('mta_ridership_congestion_error', gmdate('c') . ' ' . $e->getMessage(), false);
        mta_ridership_retry_later(MTA_CONGESTION_HOOK, 'mta_congestion_schedule');
    }
}

function mta_congestion_clear_cache()
{
    if (!function_exists('rocket_clean_post')) {
        return;
    }
    foreach (get_posts(['post_type' => 'any', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 50,
                        's' => '[mta_congestion', 'no_found_rows' => true]) as $id) {
        rocket_clean_post($id);
    }
}

/* ---------- page ---------- */

/** Toll rates with E-ZPass, from mta.info (Congestion Relief Zone toll, 2025–2027). [label, peak, overnight, crossing credit] */
function mta_congestion_rates()
{
    return [
        'car'   => ['Cars, SUVs, pickups and small vans', 9.00, 2.25, 3.00],
        'moto'  => ['Motorcycles', 4.50, 1.05, 1.50],
        'small' => ['Small trucks and some buses', 14.40, 3.60, 7.20],
        'large' => ['Large trucks and tour buses', 21.60, 5.40, 12.00],
    ];
}

function mta_congestion_answers($c)
{
    $fmt = function ($n) { return number_format($n); };
    $a = [
        'cost'  => 'With E-ZPass, cars pay $9 during peak hours and $2.25 overnight, once per day. Motorcycles pay $4.50 / $1.05, ' .
            'small trucks $14.40 / $3.60 and large trucks $21.60 / $5.40. Taxi riders pay $0.75 and Uber or Lyft riders $1.50 per trip. ' .
            'Drivers without E-ZPass pay more. Entering at peak through the Lincoln, Holland, Queens-Midtown or Hugh L. Carey tunnel gives a credit of up to $3 for cars.',
        'hours' => 'Peak toll hours are 5 AM to 9 PM on weekdays and 9 AM to 9 PM on weekends. At all other times the overnight toll applies, 75% less than peak.',
        'zone'  => 'Manhattan south of and including 60th Street. The FDR Drive, the West Side Highway/Route 9A and the Hugh L. Carey Tunnel connections to West Street are not tolled.',
        'raise' => 'Yes. The MTA is phasing the toll in: the $9 peak toll for cars goes up to $12 in 2028 and $15 in 2031.',
    ];
    if ($c['pct'] !== null) {
        $a['working'] = (true ? "This data alone can't answer that. " : '') .
            'About ' . $fmt($c['cur_per_day']) . ' vehicles a day entered the zone in ' . $c['year'] . ' (Jan 5 – ' .
            mta_congestion_md($c['latest']) . '), ' . ($c['pct'] <= 0 ? abs($c['pct']) . '% fewer' : $c['pct'] . '% more') .
            ' than the ' . $fmt($c['prev_per_day']) . ' a day on the same days of ' . ($c['year'] - 1) . ', the first year of tolling.' .
            (true ? ' ' . mta_congestion_caveat() . " For the change compared with traffic before tolling, see the MTA's own estimates." : '');
    }
    if (!empty($c['entries'])) {
        $a['busiest'] = 'The busiest way into the zone is ' . $c['entries'][0]['name'] . ', with about ' . $fmt($c['entries'][0]['per_day']) .
            ' vehicles a day in ' . $c['year'] . ', followed by ' . $c['entries'][1]['name'] . ' (' . $fmt($c['entries'][1]['per_day']) . ').';
    }
    if (!empty($c['subway']['in_pct'])) {
        $s = $c['subway'];
        $a['subway'] = 'Subway ridership at the ' . $s['stations'] . ' stations inside the zone is ' . ($s['in_pct'] >= 0 ? 'up ' : 'down ') .
            abs($s['in_pct']) . '% on the same days of last year (' . $fmt($s['in_per_day']) . ' riders a day), compared with ' .
            ($s['out_pct'] >= 0 ? 'up ' : 'down ') . abs($s['out_pct']) . '% at stations outside it.' .
            (true ? ' Both years had the toll, and ridership also depends on office attendance, tourism and service changes, ' .
            "so the gap between the two is not a measure of the toll's effect." : '');
    }
    return $a;
}

/** Why a year-over-year comparison doesn't measure the toll's effect (both years were tolled). */
function mta_congestion_caveat()
{
    return "Tolling began on January 5, 2025, so both periods had the toll: this shows how traffic changed in tolling's second year, not the effect of the toll itself. Traffic also moves with the economy, tourism, construction, weather and how often people work from home.";
}

function mta_congestion_md($ymd)
{
    return gmdate('M j', strtotime("$ymd UTC"));
}

function mta_congestion_faq($c)
{
    $a = mta_congestion_answers($c);
    $q = [
        'cost' => 'How much is congestion pricing in NYC?', 'hours' => 'What are the congestion pricing hours?',
        'zone' => 'Where is the congestion pricing zone?', 'working' => 'Is congestion pricing working?',
        'busiest' => 'Which bridge or tunnel do most vehicles use to enter the zone?',
        'subway' => 'Did congestion pricing change subway ridership?', 'raise' => 'Will the congestion pricing toll go up?',
    ];
    $out = [];
    foreach ($q as $k => $question) {
        if (isset($a[$k])) {
            $out[] = [$question, $a[$k]];
        }
    }
    return $out;
}

add_shortcode('mta_congestion', function () {
    $c = get_option(MTA_CONGESTION_OPTION);
    if (!$c) {
        mta_congestion_schedule(5);
        return '<p>Congestion pricing data is loading. Check back in a few minutes.</p>';
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css', [], '1.9.4');
    wp_enqueue_script('leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js', [], '1.9.4', true);
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.15.0');
    wp_enqueue_script('mta-congestion', $base . 'assets/mta-congestion.js', ['chartjs', 'leaflet'], '1.15.0', true);
    wp_add_inline_script('mta-congestion', 'window.MTA_CONGESTION = ' . wp_json_encode($c + ['rates' => mta_congestion_rates()]) . ';', 'before');

    $fmt = 'number_format';
    $a = mta_congestion_answers($c);
    $tile = function ($big, $small, $cls = '') {
        return '<div class="mta-tile' . $cls . '"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>';
    };
    $sec = function ($title, $body, $note = '') {
        return '<section class="mta-section"><' . mta_ridership_h() . '>' . esc_html($title) . '</' . mta_ridership_h() . '>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
    };
    $o = '<header class="mta-hero"><h1>' . (true ? 'NYC Congestion Pricing: Is It Working? Explore the Traffic and Transit Data'
        : 'NYC Congestion Pricing: Map, Hours, Cost &amp; Traffic Data') . '</h1>' .
        '<p class="mta-hero-links">An independent look at MTA open data, updated daily · Not affiliated with the MTA</p><div class="mta-tiles">' .
        $tile('$9', 'peak toll for cars (E-ZPass)') .
        $tile($fmt($c['cur_per_day']), 'vehicles enter the zone a day') .
        ($c['pct'] !== null ? $tile(($c['pct'] > 0 ? '+' : '') . $c['pct'] . '%', 'vs same days of ' . ($c['year'] - 1)) : '') .
        '</div></header>';

    // Guide: cost, hours, calculator.
    $rows = '';
    foreach (mta_congestion_rates() as [$label, $peak, $night]) {
        $rows .= '<tr><td>' . esc_html($label) . '</td><td>$' . number_format($peak, 2) . '</td><td>$' . number_format($night, 2) . '</td></tr>';
    }
    $rows .= '<tr><td>Uber, Lyft (per trip, paid by the rider)</td><td>$1.50</td><td>$1.50</td></tr>' .
             '<tr><td>Taxis and black cars (per trip)</td><td>$0.75</td><td>$0.75</td></tr>';
    $o .= $sec('How much is congestion pricing?',
        '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th>Vehicle (with E-ZPass)</th><th>Peak</th><th>Overnight</th></tr></thead><tbody>' .
        $rows . '</tbody></table></div>' .
        '<p><strong>Peak hours:</strong> 5 AM – 9 PM on weekdays, 9 AM – 9 PM on weekends. Overnight is everything else, 75% cheaper. ' .
        'Cars and motorcycles pay once a day; trucks and buses pay each time they enter. ' .
        'At peak, entering through the Lincoln, Holland, Queens-Midtown or Hugh L. Carey tunnel takes off up to $3 for cars. ' .
        'Without E-ZPass you pay more. The car toll rises to $12 in 2028 and $15 in 2031.</p>' .
        '<div data-slot="calc" class="mta-card mta-calc"></div>',
        'Rates from <a href="https://www.mta.info/fares-tolls/tolls/congestion-relief-zone/about" rel="noopener">mta.info</a>. ' .
        'The MTA also has an official <a href="https://www.mta.info/fares-tolls/tolls/congestion-relief-zone/toll-rate-calculator" rel="noopener">toll calculator</a>.');

    $o .= $sec('Congestion pricing zone map', '<div data-slot="map" class="mta-map mta-crz-map"></div>',
        esc_html($a['zone']) . ' Dots are the ' . (int) ($c['subway']['stations'] ?? 0) . ' subway stations inside the zone.');

    $o .= $sec('When do vehicles enter the zone?', '<div data-slot="hours"></div>',
        'Average vehicles entering per hour, ' . esc_html(mta_ridership_nice_date($c['hours_from'])) . ' – ' .
        esc_html(mta_ridership_nice_date($c['latest'])) . '. Shaded hours are peak toll hours.');

    if (isset($a['working'])) {
        $p = true; // live: no causal claim from a year-over-year comparison
        $o .= $sec(($p ? 'Traffic into the zone: ' : 'Is congestion pricing working? ') . $c['year'] . ' vs ' . ($c['year'] - 1),
            '<p>' . esc_html($a['working']) . '</p><div data-slot="monthly"></div>',
            'Average vehicles entering the zone a day, by month (' . esc_html(mta_ridership_nice_date($c['latest'])) . ' is the latest day, ' .
            'so its month is partial). ' . ($p ? 'The MTA compares traffic with a pre-tolling baseline on its ' .
            '<a href="https://metrics.mta.info/?cbdtp/vehiclereductions" rel="noopener">Vehicle Reductions</a> page, which stopped updating after December 2025.'
            : 'The MTA\'s own "Vehicle Reductions" page stopped updating after December 2025.'));
    }

    $er = '';
    foreach ($c['entries'] as $e) {
        $p = $e['pct'];
        $er .= '<tr><td>' . esc_html($e['name']) . '</td><td>' . $fmt($e['per_day']) . '</td><td class="' . ($p !== null && $p < 0 ? 'neg' : 'pos') . '">' .
            ($p === null ? '–' : ($p > 0 ? '+' : '') . $p . '%') . '</td></tr>';
    }
    $o .= $sec('Busiest bridges and tunnels into the zone',
        '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th>Entry</th><th>Vehicles a day</th><th>vs ' . ($c['year'] - 1) .
        '</th></tr></thead><tbody>' . $er . '</tbody></table></div>',
        'Jan 5 – ' . esc_html(mta_congestion_md($c['latest'])) . ', ' . $c['year'] . ', compared with the same days of ' . ($c['year'] - 1) . '.');

    $o .= $sec('Who pays the toll?', '<div data-slot="vehicles"></div>',
        'Vehicle entries by type and toll period, ' . esc_html(mta_ridership_nice_date($c['vehicles_from'])) . ' – ' .
        esc_html(mta_ridership_nice_date($c['latest'])) . '.');

    if (!empty($c['subway']['busiest'])) {
        $s = $c['subway'];
        $sr = '';
        foreach ($s['busiest'] as $b) {
            $sr .= '<tr><td>' . esc_html(mta_ridership_station_name($b['name'])) . ' <span class="mta-lines">' . esc_html(mta_ridership_station_lines($b['name'])) .
                '</span></td><td>' . $fmt($b['per_day']) . '</td><td class="' . ($b['pct'] < 0 ? 'neg' : 'pos') . '">' . ($b['pct'] > 0 ? '+' : '') . $b['pct'] . '%</td></tr>';
        }
        $o .= $sec('Subway ridership inside the zone',
            '<div class="mta-tiles mta-tiles-yoy">' .
            $tile(($s['in_pct'] > 0 ? '+' : '') . $s['in_pct'] . '%', 'stations inside the zone', ' mta-tile-sm' . ($s['in_pct'] < 0 ? ' neg' : '')) .
            $tile(($s['out_pct'] > 0 ? '+' : '') . $s['out_pct'] . '%', 'stations outside', ' mta-tile-sm' . ($s['out_pct'] < 0 ? ' neg' : '')) .
            '</div><p>' . esc_html($a['subway']) . '</p>' .
            '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th>Busiest stations in the zone</th><th>Riders a day</th><th>vs ' . ($s['year'] - 1) .
            '</th></tr></thead><tbody>' . $sr . '</tbody></table></div>',
            'Jan 5 – ' . esc_html(mta_congestion_md($s['through'])) . ', ' . $s['year'] . ' vs the same days of ' . ($s['year'] - 1) .
            '. See every station on the <a href="' . esc_url(home_url('/mta-ridership/')) . '">NYC subway ridership page</a>.');
    }

    $o .= $sec('How NYC compares with London and Stockholm',
        '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th></th><th>New York</th><th>London</th><th>Stockholm</th></tr></thead><tbody>' .
        '<tr><td>Started</td><td>2025</td><td>2003</td><td>2007</td></tr>' .
        '<tr><td>Car charge</td><td>$9 a day at peak, $2.25 overnight</td><td>£18 a day (since Jan 2026)</td><td>Per crossing, up to 135 SEK a day</td></tr>' .
        '<tr><td>Hours</td><td>Every day; peak 5 AM – 9 PM weekdays, 9 AM – 9 PM weekends</td><td>7 AM – 6 PM weekdays, 12 – 6 PM weekends</td><td>Weekdays only</td></tr>' .
        '</tbody></table></div>',
        'London: <a href="https://tfl.gov.uk/modes/driving/congestion-charge" rel="noopener">TfL</a>. Stockholm: ' .
        '<a href="https://www.transportstyrelsen.se/en/road/vehicles/taxes-and-fees/road-tolls/congestion-taxes-in-stockholm-and-gothenburg/congestion-tax-in-stockholm/hours-and-amounts-in-stockholm/" rel="noopener">Transportstyrelsen</a>.');

    $faq = '';
    foreach (mta_congestion_faq($c) as [$q, $ans]) {
        $faq .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($ans) . '</p></details>';
    }
    $o .= '<section class="mta-section mta-faq"><' . mta_ridership_h() . '>Congestion pricing questions</' . mta_ridership_h() . '>' . $faq . '</section>';
    if (true) { // live: links to the other transit data pages
        $o .= mta_ridership_ssr_more('congestion');
    }
    $o .= mta_ridership_ssr_talk(null, true ? 'NYC congestion pricing: zone map, hours, cost, and daily traffic data'
        : 'NYC congestion pricing: zone map, hours, cost, and daily data on whether it is working');
    $o .= '<p class="mta-source mta-source-ssr">Data: <a href="https://data.ny.gov/d/t6yz-b64h" rel="noopener">MTA Congestion Relief Zone Vehicle Entries</a>' .
        ' and <a href="https://data.ny.gov/d/srxy-5nxn" rel="noopener">zone outline</a> (data.ny.gov), through ' . esc_html(mta_ridership_nice_date($c['latest'])) .
        '. Updated ' . esc_html(gmdate('M j, Y', strtotime($c['updated']))) . '. Also see the MTA\'s ' .
        '<a href="https://metrics.mta.info/?cbdtp/about" rel="noopener">congestion pricing dashboards</a>.<br>' .
        'Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).' . mta_ridership_status_link() . '</p>';
    return '<div class="mta-ridership mta-congestion">' . $o . mta_ridership_disclaimer() . '</div>';
});

/* ---------- SEO for the congestion pricing page ---------- */

function mta_congestion_is_page()
{
    if (!is_singular()) {
        return false;
    }
    $post = get_queried_object();
    return $post && has_shortcode($post->post_content ?? '', 'mta_congestion');
}

function mta_congestion_seo()
{
    $c = get_option(MTA_CONGESTION_OPTION);
    if (!$c) {
        return null;
    }
    if (mta_ridership_seo_live()) { // shorter title and description
        return [
            'title' => 'NYC Congestion Pricing: Map, Hours, Cost & Is It Working?',
            'description' => 'NYC congestion pricing: zone map, toll hours, cost ($9 peak) and a calculator, plus daily traffic into the zone' .
                ($c['pct'] !== null ? ' (' . ($c['pct'] > 0 ? '+' : '') . $c['pct'] . '% vs ' . ($c['year'] - 1) . ')' : '') . ' and subway riders.',
            'c' => $c,
        ];
    }
    return [
        'title' => true ? 'NYC Congestion Pricing: Is It Working? Explore the Traffic and Transit Data'
            : 'NYC Congestion Pricing: Map, Hours, Cost & Is It Working? (' . $c['year'] . ' Data)',
        'description' => 'NYC congestion pricing explained: zone map, toll hours, how much it costs ($9 peak, $2.25 overnight) and a calculator. ' .
            'Plus daily data on traffic into the zone' . ($c['pct'] !== null ? ' (' . ($c['pct'] > 0 ? '+' : '') . $c['pct'] . '% vs ' . ($c['year'] - 1) . ')' : '') .
            ', every bridge and tunnel, and subway ridership.',
        'c' => $c,
    ];
}

add_filter('wpseo_title', function ($title) {
    return mta_congestion_is_page() && ($s = mta_congestion_seo()) ? mta_ridership_seo_title($s['title']) : $title;
});
add_filter('wpseo_opengraph_title', function ($title) {
    return mta_congestion_is_page() && ($s = mta_congestion_seo()) ? $s['title'] : $title;
});
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($desc) {
        return mta_congestion_is_page() && ($s = mta_congestion_seo()) ? mta_ridership_seo_desc($s['description']) : $desc;
    });
}
// Share image for link previews (zone map with the headline numbers).
add_action('wpseo_add_opengraph_images', function ($images) {
    if (mta_congestion_is_page()) {
        $images->add_image(['url' => plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/congestion-share.jpg',
                            'width' => 1200, 'height' => 630, 'type' => 'image/jpeg']);
    }
});
add_filter('wpseo_twitter_image', function ($img) {
    return mta_congestion_is_page() ? plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/congestion-share.jpg' : $img;
});
// Yoast doesn't print an og:image for this page even with the image above, so print it here.
add_action('wpseo_head', function () {
    if (mta_congestion_is_page()) {
        $url = esc_url(plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/congestion-share.jpg');
        echo "<meta property=\"og:image\" content=\"$url\" />\n<meta property=\"og:image:width\" content=\"1200\" />\n" .
             "<meta property=\"og:image:height\" content=\"630\" />\n<meta property=\"og:image:type\" content=\"image/jpeg\" />\n";
    }
}, 30);

add_action('wp_head', function () {
    if (!mta_congestion_is_page() || !($s = mta_congestion_seo())) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($i) {
            return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
        }, mta_congestion_faq($s['c'])),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});
