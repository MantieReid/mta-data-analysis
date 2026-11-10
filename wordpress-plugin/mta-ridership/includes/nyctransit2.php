<?php
/**
 * Three more pages in the same review as nyctransit.php (MTA_NT_PAGES; preview only until the owner says go):
 *  - subway service changes and delays: live alerts and this week's planned work (fetched by the visitor's browser from
 *    two small endpoints that copy the MTA's alerts file), plus 12 months of delay alerts by line, hour and type;
 *  - MTA construction projects: the capital program's projects, their cost and their finish dates (Second Avenue
 *    Subway Phase 2, the Interborough Express, new train cars, accessibility);
 *  - an LIRR and Metro-North fare calculator from the official fare charts (no open data has railroad prices).
 * The alert history and projects are fetched in the monthly "nt" step.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_NT_FARES_FROM = ['lirr' => '2026-01-04', 'mnr' => '2026-01-04', 'mnr_ct' => '2026-07-01'];

/* ---------- fetching (called from mta_nt_step) ---------- */

/** Subway line code in the alerts data => line page code ("J"/"Z" => "JZ", "GS" => "S 42nd"...). */
function mta_nt_alert_line($code)
{
    $code = trim((string) $code);
    $map = ['J' => 'JZ', 'Z' => 'JZ', 'GS' => 'S 42nd', 'FS' => 'S Fkln', 'H' => 'S Rock', 'SI' => 'SIR', '6X' => '6', '7X' => '7', 'FX' => 'F'];
    return $map[$code] ?? $code;
}

/** Subway delay alerts: every month since 2020, and by line, type, hour and day of the week for the last 12 months of data. */
function mta_nt_alerts_fetch()
{
    $x = ['months' => [], 'lines' => [], 'types' => [], 'hours' => array_fill(0, 24, 0), 'weekdays' => array_fill(1, 7, 0), 'total' => 0, 'delays' => 0];
    foreach (mta_more_api('7kct-peq7', ['$select' => 'date_trunc_ym(date) AS m, count(distinct event_id) AS n', '$where' => "agency='NYCT Subway' AND status_label like '%delays%'",
        '$group' => 'm', '$order' => 'm']) as $r) {
        $x['months'][mta_more_month($r['m'])] = (int) $r['n'];
    }
    $last = mta_more_api('7kct-peq7', ['$select' => 'max(date) AS d', '$where' => "agency='NYCT Subway'"])[0]['d'] ?? null;
    if (!$x['months'] || !$last) {
        throw new Exception('No alert months');
    }
    $to = substr($last, 0, 10);
    $from = gmdate('Y-m-d', strtotime("$to -365 days UTC"));
    $rows = mta_more_api('7kct-peq7', ['$select' => 'affected, status_label, date', '$where' => "agency='NYCT Subway' AND update_number=1 AND date >= '$from' AND date < '$to'"]);
    foreach ($rows as $r) {
        $types = array_map('trim', explode('|', $r['status_label'] ?? ''));
        $delay = (bool) array_filter($types, function ($t) { return strpos($t, 'delays') !== false; });
        $x['total']++;
        foreach ($types as $t) {
            if ($t !== '') {
                $x['types'][$t] = ($x['types'][$t] ?? 0) + 1;
            }
        }
        if (!$delay) {
            continue;
        }
        $x['delays']++;
        $lines = [];
        foreach (explode('|', $r['affected'] ?? '') as $c) {
            if (($c = mta_nt_alert_line($c)) !== '') {
                $lines[$c] = true;
            }
        }
        foreach (array_keys($lines) as $c) {
            $x['lines'][(string) $c] = ($x['lines'][(string) $c] ?? 0) + 1;
        }
        // The data's times are UTC (an alert stamped 22:09 is about "the 6:03 PM trip"), shown here in New York time.
        $ts = strtotime(substr($r['date'], 0, 19) . ' UTC');
        $x['hours'][(int) wp_date('G', $ts, new DateTimeZone('America/New_York'))]++;
        $x['weekdays'][(int) wp_date('N', $ts, new DateTimeZone('America/New_York'))]++;
    }
    arsort($x['lines']);
    arsort($x['types']);
    $x += ['from' => $from, 'to' => $to, 'latest' => $to];
    return $x;
}

/** The MTA capital program's projects (about 160), with what the pages show. */
function mta_nt_projects_fetch()
{
    $keep = ['project_id', 'title', 'stage', 'phase', 'agencies', 'asset_categories', 'description', 'services', 'initiatives', 'start_date', 'goal_completion_date',
             'estimated_actual_completion_date', 'goal_project_cost', 'estimated_actual_project_cost'];
    $out = [];
    foreach (mta_more_api('9hy6-8j6t', ['$order' => 'project_id']) as $r) {
        $p = array_intersect_key($r, array_flip($keep));
        foreach (['agencies', 'asset_categories', 'services', 'initiatives'] as $k) { // stored as "['A', 'B']"
            $p[$k] = isset($p[$k]) ? array_values(array_filter(array_map(function ($s) { return trim($s, " '\""); }, explode(',', trim($p[$k], '[]'))))) : [];
        }
        foreach (['start_date', 'goal_completion_date', 'estimated_actual_completion_date'] as $k) {
            $p[$k] = isset($p[$k]) ? substr($p[$k], 0, 10) : null;
        }
        foreach (['goal_project_cost', 'estimated_actual_project_cost'] as $k) {
            $p[$k] = isset($p[$k]) ? (float) $p[$k] : null;
        }
        $out[] = $p;
    }
    if (count($out) < 20) {
        throw new Exception('Too few projects');
    }
    return ['projects' => $out, 'latest' => gmdate('Y-m-d')];
}

/* ---------- planned work (this week), for the service changes page ---------- */

/**
 * Planned work in the MTA alerts file that is on now or starts within $days: [[routes, type, header, when, start, now], ...] by start.
 * "when" is the MTA's own wording ("Nov 28 - 29, Sat and Sun, days and evenings").
 */
function mta_nt_trim_planned($raw, $now, $days = 8)
{
    $text = function ($t) {
        foreach ($t['translation'] ?? [] as $x) {
            if (($x['language'] ?? '') === 'en') {
                return trim(preg_replace('/\s+/', ' ', $x['text']));
            }
        }
        return '';
    };
    $end = $now + $days * 86400;
    $out = [];
    foreach ($raw['entity'] ?? [] as $e) {
        $a = $e['alert'] ?? [];
        $m = $a['transit_realtime.mercury_alert'] ?? [];
        $type = $m['alert_type'] ?? '';
        if (stripos($type, 'Planned') !== 0) {
            continue;
        }
        $start = null;
        $on = false;
        foreach ($a['active_period'] ?? [] as $p) {
            $s = (int) ($p['start'] ?? 0);
            $f = (int) ($p['end'] ?? PHP_INT_MAX);
            if ($f >= $now && $s <= $end) {
                $start = $start === null ? max($s, $now) : min($start, max($s, $now));
                $on = $on || ($s <= $now && $now <= $f);
            }
        }
        if ($start === null) {
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
        $out[] = ['routes' => array_map('strval', array_keys($routes)), 'type' => trim(substr($type, strlen('Planned')), " -"), 'header' => $head,
                  'when' => $text($m['human_readable_active_period'] ?? []), 'start' => $start, 'now' => $on];
    }
    usort($out, function ($a, $b) { return $a['start'] <=> $b['start']; });
    return $out;
}

function mta_nt_planned()
{
    $cached = get_transient('mta_nt_planned');
    if ($cached !== false) {
        return $cached;
    }
    $res = wp_remote_get(MTA_MAP_ALERTS, ['timeout' => 20]);
    $raw = is_wp_error($res) ? null : json_decode(wp_remote_retrieve_body($res), true);
    $out = ['updated' => gmdate('c'), 'ok' => is_array($raw), 'planned' => is_array($raw) ? mta_nt_trim_planned($raw, time()) : []];
    set_transient('mta_nt_planned', $out, is_array($raw) ? 600 : 60);
    return $out;
}

add_action('rest_api_init', function () {
    register_rest_route('mta-ridership/v1', '/planned', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true', // public, read-only copy of the MTA's public planned-work notices
        'callback'            => function () {
            $res = new WP_REST_Response(mta_nt_planned());
            $res->header('Cache-Control', 'public, max-age=600');
            return $res;
        },
    ]);
});

/** Line bullets for the page script: GTFS route id => [background, text colour, label]. */
function mta_nt_bullets()
{
    $d = function_exists('mta_map_data') ? mta_map_data() : null;
    $out = [];
    $shuttles = ['GS' => 'S', 'FS' => 'S', 'H' => 'S'];
    foreach ($d['routes'] ?? [] as $rid => $r) {
        $out[$rid] = ['#' . $r['c'], '#' . $r['t'], $shuttles[$rid] ?? $r['s']];
    }
    return $out;
}

function mta_nt_line_url($code)
{
    $l = mta_lines_list();
    return isset($l[$code]) ? home_url('/' . MTA_LINES_SLUG . '/' . $l[$code][0] . '/') : null;
}

/* ---------- service changes page ---------- */

function mta_nt_changes_facts()
{
    $x = get_option('mta_nt_alerts');
    if (!$x || empty($x['lines'])) {
        return null;
    }
    $names = mta_lines_list() + ['W' => ['w', 'W train']]; // the W has no line page, but it has alerts
    $lines = array_intersect_key($x['lines'], $names);
    $top = array_key_first($lines);
    $peak = array_search(max($x['hours']), $x['hours'], true);
    $wd = $x['weekdays'];
    $wk = array_sum(array_slice($wd, 0, 5, true)) / 5 / 52; // a day: 52 of each weekday in the 12 months
    $we = ($wd[6] + $wd[7]) / 2 / 52;
    return ['x' => $x, 'lines' => $lines, 'names' => $names, 'top' => $top, 'top_name' => $names[$top][1], 'per_day' => round($x['delays'] / 365, 1),
            'peak_hour' => $peak, 'wk' => $wk, 'we' => $we, 'range' => mta_ridership_nice_date($x['from']) . ' – ' . mta_ridership_nice_date($x['to'])];
}

function mta_nt_changes_faq($f)
{
    return [['Is the subway running normally right now?', 'This page shows the MTA\'s current alerts for every line, refreshed every 2 minutes. For the official status, check mta.info or the MTA app.'],
            ['Which subway line has the most delays?', 'The ' . $f['top_name'] . ' had the most delay alerts, ' . number_format($f['lines'][$f['top']]) . ' from ' . $f['range'] . '. Lines that share track (like the 2 and 3) are counted for each line.'],
            ['When is subway weekend work announced?', 'The MTA posts planned work for the coming weekend during the week before. This page lists all planned work that is on now or starts in the next 8 days.'],
            ['What time of day do most subway delays happen?', 'Most delay alerts start around ' . mta_ridership_hour_name($f['peak_hour']) . '. There are about ' . round($f['wk']) . ' delay alerts on an average weekday against ' .
                round($f['we']) . ' on a weekend day, over the last 12 months of data.']];
}

function mta_nt_changes_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    $f = mta_nt_changes_facts();
    if (!$f) {
        return '<p>Service change data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $x = $f['x'];
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_script('mta-changes', $base . 'assets/mta-changes.js', [], '1.0.2', true);
    wp_add_inline_script('mta-changes', 'window.MTA_CHANGES = ' . wp_json_encode([
        'alerts' => rest_url('mta-ridership/v1/alerts'), 'planned' => rest_url('mta-ridership/v1/planned'), 'bullets' => mta_nt_bullets(),
    ], JSON_UNESCAPED_SLASHES) . ';', 'before');

    $o = mta_nt_hero('NYC Subway Service Changes & Delays: Right Now and This Weekend', 'Current alerts and planned work for every line, in the MTA\'s own words, refreshed every few minutes, plus 12 months of delay history · Not affiliated with the MTA',
        [mta_more_tile(number_format($f['per_day']), 'delay alerts a day'), mta_more_tile($f['top_name'], 'most delay alerts'), mta_more_tile(mta_ridership_hour_name($f['peak_hour']), 'busiest hour for delays')]);
    $o .= '<p class="mta-note">For the official status, see <a href="https://www.mta.info/alerts" rel="noopener">mta.info/alerts</a>. Times below are New York time.</p>';

    $o .= mta_more_sec('Right now', '<div class="mta-bullets" data-changes-pick></div><div data-changes-now aria-live="polite"><p class="mta-note">Loading the MTA\'s current alerts…</p></div>',
        'Delays and other service changes in effect at this moment. Pick a line to see only its alerts.');
    $o .= mta_more_sec('Planned work this week and weekend', '<div data-changes-planned><p class="mta-note">Loading planned work…</p></div>',
        'Track work and other planned changes that are on now or start in the next 8 days, soonest first.');

    $bars = [];
    foreach (array_slice($f['lines'], 0, 25, true) as $c => $n) {
        $url = mta_nt_line_url($c);
        $bars[] = [['html' => $url ? '<a href="' . esc_url($url) . '">' . esc_html($f['names'][$c][1]) . '</a>' : esc_html($f['names'][$c][1])], $n, number_format($n), ''];
    }
    $o .= mta_more_sec('Which lines have the most delays?', mta_more_bars($bars),
        'Delay alerts the MTA posted for each line, ' . esc_html($f['range']) . '. One alert can cover several lines; each line is counted.');

    $o .= mta_more_sec('Delay alerts by month', mta_more_month_chart(array_slice($x['months'], -36, 36, true), 'Subway delay alerts by month'),
        'Each delay posted by the MTA counts once, however many updates it had. Fewer alerts can also mean fewer posted, not only fewer delays.');

    $hb = [];
    foreach ($x['hours'] as $h => $n) {
        $hb[] = [mta_ridership_hour_name($h), $n, number_format($n), ''];
    }
    $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $db = [];
    foreach ($x['weekdays'] as $d => $n) {
        $db[] = [$days[$d], $n, number_format(round($n / 52)) . ' a ' . $days[$d], ''];
    }
    $o .= mta_more_sec('When delays happen', mta_more_h3('By hour the alert started') . mta_more_bars($hb) . mta_more_h3('By day of the week') . mta_more_bars($db),
        'Over ' . esc_html($f['range']) . '.');

    $labels = ['delays' => 'Delays', 'severe-delays' => 'Severe delays', 'stops-skipped' => 'Stops skipped', 'part-suspended' => 'Part of the line suspended', 'express-to-local' => 'Express trains running local',
               'reroute' => 'Trains rerouted', 'suspended' => 'Line suspended', 'boarding-change' => 'Boarding change', 'cancellations' => 'Cancellations', 'station-notice' => 'Station notice',
               'information-outage' => 'Information outage', 'special-notice' => 'Special notice', 'no-scheduled-service' => 'No scheduled service'];
    $tb = [];
    foreach (array_slice($x['types'], 0, 10, true) as $t => $n) {
        $tb[] = [$labels[$t] ?? ucfirst(str_replace('-', ' ', $t)), $n, number_format($n), ''];
    }
    $o .= mta_more_sec('What kind of alerts?', mta_more_bars($tb), 'All ' . number_format($x['total']) . ' subway alerts in the same 12 months; an alert can be more than one kind.');

    $o .= mta_tm_faq_html(mta_nt_changes_faq($f));
    $o .= mta_ridership_ssr_more('changes');
    $o .= mta_nt_source(['https://data.ny.gov/d/7kct-peq7' => 'MTA Service Alerts: Beginning April 2020', 'https://api.mta.info/' => 'MTA real-time service alerts feed'], $x['updated']);
    return mta_nt_wrap($o);
}

add_shortcode('mta_service_changes', 'mta_nt_changes_page');

/* ---------- construction projects page ---------- */

function mta_nt_projects_facts()
{
    $x = get_option('mta_nt_projects');
    if (!$x || empty($x['projects'])) {
        return null;
    }
    $ps = $x['projects'];
    $cost = function ($p) { return $p['estimated_actual_project_cost'] ?? $p['goal_project_cost'] ?? 0; };
    $stages = array_count_values(array_column($ps, 'stage'));
    $late = $over = [];
    foreach ($ps as $p) {
        if (mta_nt_proj_late($p)) {
            $late[] = $p;
        }
        if ($p['goal_project_cost'] && $p['estimated_actual_project_cost'] > $p['goal_project_cost'] * 1.01) {
            $over[] = $p;
        }
    }
    $find = function ($fn) use ($ps) { return array_values(array_filter($ps, $fn)); };
    $by = function ($a, $b) use ($cost) { return $cost($b) <=> $cost($a); };
    usort($ps, $by);
    usort($late, function ($a, $b) { return strtotime($b['estimated_actual_completion_date']) - strtotime($b['goal_completion_date']) <=> strtotime($a['estimated_actual_completion_date']) - strtotime($a['goal_completion_date']); });
    $sas = $find(function ($p) { return stripos($p['title'], 'SAS ') === 0 || stripos($p['title'], 'Second Avenue Subway') !== false; });
    $ibx = $find(function ($p) { return stripos($p['title'], 'Interborough Express') !== false; });
    $cars = $find(function ($p) { return stripos($p['title'], 'Purchase') === 0; });
    $ada = $find(function ($p) { return in_array('Accessibility', $p['initiatives'], true) || stripos($p['title'], 'ADA') !== false; });
    usort($cars, $by);
    usort($ada, $by);
    $agencies = [];
    foreach ($ps as $p) {
        foreach ($p['agencies'] ?: ['Other'] as $a) {
            $agencies[$a]['n'] = ($agencies[$a]['n'] ?? 0) + 1;
            $agencies[$a]['cost'] = ($agencies[$a]['cost'] ?? 0) + $cost($p) / max(1, count($p['agencies']));
        }
    }
    uasort($agencies, function ($a, $b) { return $b['cost'] <=> $a['cost']; });
    return ['x' => $x, 'all' => $ps, 'cost' => $cost, 'total' => array_sum(array_map($cost, $ps)), 'stages' => $stages, 'late' => $late, 'over' => $over,
            'sas' => $sas, 'ibx' => $ibx[0] ?? null, 'cars' => $cars, 'ada' => $ada, 'agencies' => $agencies];
}

/** Due to finish in a later month than its goal (a few days late doesn't count). */
function mta_nt_proj_late(array $p)
{
    return $p['goal_completion_date'] && $p['estimated_actual_completion_date'] && substr($p['estimated_actual_completion_date'], 0, 7) > substr($p['goal_completion_date'], 0, 7);
}

function mta_nt_proj_date($d)
{
    return $d ? gmdate('M Y', strtotime($d . ' UTC')) : '–';
}

/** Table rows for projects: name (with its plain description on a second line), stage, cost, finish. */
function mta_nt_proj_rows(array $ps, $cost, $desc = false)
{
    $rows = [];
    foreach ($ps as $p) {
        $goal = $p['goal_completion_date'];
        $est = $p['estimated_actual_completion_date'];
        $finish = esc_html(mta_nt_proj_date($est ?: $goal)) . (mta_nt_proj_late($p) ? '<br><small class="mta-note">' . esc_html('goal was ' . mta_nt_proj_date($goal)) . '</small>' : '');
        $c = $cost($p);
        $rows[] = ['<strong>' . esc_html($p['title']) . '</strong>' . ($desc && !empty($p['description']) ? '<br><small class="mta-note">' . esc_html(wp_trim_words($p['description'], 30)) . '</small>' : '') .
                   ($p['agencies'] ? '<br><small class="mta-note">' . esc_html(implode(', ', $p['agencies'])) . '</small>' : ''),
                   esc_html($p['phase'] ?: $p['stage']), $c ? esc_html(mta_more_money($c)) : '–', $finish];
    }
    return $rows;
}

function mta_nt_projects_faq($f)
{
    $out = [['How much is the MTA spending on construction?', 'The ' . count($f['all']) . ' projects in the MTA\'s project tracker add up to about ' . mta_more_money($f['total']) . ' where a cost is listed. ' .
             'Many projects in planning or procurement don\'t have a cost yet.']];
    if ($f['sas']) {
        $p = $f['sas'][0];
        $out[] = ['When will the Second Avenue Subway Phase 2 open?', 'The tracker lists ' . count($f['sas']) . ' Phase 2 contract' . (count($f['sas']) > 1 ? 's' : '') . '. The biggest, "' . $p['title'] . '", is due to finish in ' .
            mta_nt_proj_date($p['estimated_actual_completion_date'] ?: $p['goal_completion_date']) . '. Phase 2 extends the Q train from 96 St up to 125 St in East Harlem, with new stations at 106 St, 116 St and 125 St.'];
    }
    if ($f['ibx']) {
        $out[] = ['What is the Interborough Express (IBX)?', 'A planned light rail line along an existing freight track from Bay Ridge, Brooklyn, to Jackson Heights, Queens, about 14 miles. It would connect with up to 17 subway lines and the LIRR. ' .
            'It is in the ' . strtolower($f['ibx']['stage']) . ' stage in the MTA\'s tracker.'];
    }
    $out[] = ['How many MTA projects are behind schedule?', count($f['late']) . ' projects have an estimated finish date later than their goal date, and ' . count($f['over']) . ' are expected to cost more than their goal budget.'];
    return $out;
}

function mta_nt_projects_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    $f = mta_nt_projects_facts();
    if (!$f) {
        return '<p>Project data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $cost = $f['cost'];
    $o = mta_nt_hero('MTA Construction Projects: Second Avenue Subway, IBX, New Trains & More', 'Every project in the MTA\'s capital project tracker: what it is, what it costs and when it should be done, updated monthly · Not affiliated with the MTA',
        [mta_more_tile(number_format(count($f['all'])), 'projects'), mta_more_tile(str_replace(' billion', 'B', mta_more_money($f['total'])), 'listed costs'),
         mta_more_tile(number_format($f['stages']['Construction'] ?? 0), 'under construction'), mta_more_tile(number_format(count($f['late'])), 'running late')]);

    $o .= mta_more_sec('The biggest projects', mta_more_table(['Project', 'Stage', 'Cost', 'Finish'], mta_nt_proj_rows(array_slice($f['all'], 0, 12), $cost, true)),
        'By estimated cost (or goal cost if there is no estimate yet). Finish is the MTA\'s current estimate.');

    if ($f['sas']) {
        $o .= mta_more_sec('Second Avenue Subway Phase 2', '<p>' . esc_html('Phase 2 takes the Q train up Second Avenue from 96 St to 125 St in East Harlem, with new stations at 106 St, 116 St and 125 St.') . '</p>' .
            mta_more_table(['Contract', 'Stage', 'Cost', 'Finish'], mta_nt_proj_rows($f['sas'], $cost)), 'Contracts listed in the tracker so far. See <a href="https://www.mta.info/project/second-avenue-subway" rel="noopener">mta.info</a> for the whole project.');
    }
    if ($f['ibx']) {
        $o .= mta_more_sec('Interborough Express (IBX)', '<p>' . esc_html(wp_trim_words($f['ibx']['description'], 80)) . '</p><p class="mta-note">' . esc_html('Stage: ' . $f['ibx']['stage'] . ($f['ibx']['phase'] ? ' (' . $f['ibx']['phase'] . ')' : '') . '.') . '</p>',
            'The MTA\'s own description from the tracker.');
    }
    if ($f['cars']) {
        $o .= mta_more_sec('New trains, cars and buses', mta_more_table(['Order', 'Stage', 'Cost', 'Finish'], mta_nt_proj_rows($f['cars'], $cost)));
    }
    if ($f['ada']) {
        $o .= mta_more_sec('Making stations accessible', mta_more_table(['Project', 'Stage', 'Cost', 'Finish'], mta_nt_proj_rows(array_slice($f['ada'], 0, 15), $cost)),
            count($f['ada']) . ' accessibility projects (elevators, ramps and station rebuilds). The biggest ' . min(15, count($f['ada'])) . ' are shown.');
    }
    if ($f['late']) {
        $o .= mta_more_sec('Running late', mta_more_table(['Project', 'Stage', 'Cost', 'Finish'], mta_nt_proj_rows(array_slice($f['late'], 0, 15), $cost)),
            count($f['late']) . ' projects have an estimated finish later than their goal. The ones furthest behind are shown first.');
    }
    $bars = [];
    foreach ($f['agencies'] as $a => $v) {
        $bars[] = [$a, $v['cost'], mta_more_money($v['cost']), $v['n'] . ' projects'];
    }
    $o .= mta_more_sec('Spending by agency', mta_more_bars($bars), 'Listed costs; a project shared by two agencies is split evenly.');

    // Every project, by stage.
    $all = '';
    foreach (['Construction', 'Procurement', 'Planning', 'Complete'] as $st) {
        $ps = array_values(array_filter($f['all'], function ($p) use ($st) { return $p['stage'] === $st; }));
        if ($ps) {
            $all .= '<details><summary>' . esc_html($st . ' (' . count($ps) . ')') . '</summary>' . mta_more_table(['Project', 'Stage', 'Cost', 'Finish'], mta_nt_proj_rows($ps, $cost, true)) . '</details>';
        }
    }
    $o .= mta_more_sec('Every project', $all, 'Tap a stage to open the list.');

    $o .= mta_tm_faq_html(mta_nt_projects_faq($f));
    $o .= mta_ridership_ssr_more('projects');
    $o .= mta_nt_source(['https://data.ny.gov/d/9hy6-8j6t' => 'MTA Capital Project Details: Beginning 2015'], $f['x']['updated']);
    return mta_nt_wrap($o);
}

add_shortcode('mta_projects', 'mta_nt_projects_page');

/* ---------- LIRR and Metro-North fares ---------- */

/** LIRR fares between fare zones, from the LIRR fare chart effective January 4, 2026: [from][to] => [peak, offpeak, senior, day_wk, day_we, weekly, monthly, onb_peak, onb_off]. */
function mta_nt_lirr_fares()
{
    $z = [1, 3, 4, 7, 9, 10, 12, 14];
    $t = [
        1 => [[7.25, 7.25, 13.50, 15.25, 18.25, 21.50, 25.50, 33.00], [5.25, 5.25, 10.00, 11.25, 13.50, 16.00, 18.75, 24.50], [3.50, 3.50, 6.75, 7.50, 9.00, 10.75, 12.75, 16.50], [14.50, 14.50, 24.25, 27.50, 32.75, 38.75, 46.00, 59.50], [10.50, 10.50, 20.00, 22.50, 27.00, 32.00, 37.50, 49.00], [67.75, 81.50, 94.00, 106.50, 126.75, 140.25, 160.75, 173.50], [172.50, 207.00, 264.25, 299.75, 356.50, 394.50, 452.00, 487.75], [15.00, 15.00, 22.00, 23.00, 26.00, 30.00, 34.00, 41.00], [13.00, 13.00, 18.00, 19.00, 22.00, 24.00, 27.00, 33.00]],
        3 => [[7.25, 6.00, 9.00, 10.75, 13.25, 16.50, 22.00, 28.25], [5.25, 4.50, 6.75, 8.00, 9.75, 12.25, 16.25, 21.00], [3.50, 3.00, 4.50, 5.25, 6.50, 8.25, 11.00, 14.00], [14.50, 10.75, 16.25, 19.25, 23.75, 29.75, 39.50, 50.75], [10.50, 9.00, 13.50, 16.00, 19.50, 24.50, 32.50, 42.00], [81.50, 49.50, 67.75, 79.50, 95.50, 112.75, 135.75, 149.50], [207.00, 130.50, 197.25, 230.75, 277.75, 327.75, 394.50, 434.25], [15.00, 14.00, 17.00, 19.00, 21.00, 25.00, 30.00, 36.00], [13.00, 13.00, 15.00, 16.00, 18.00, 20.00, 24.00, 29.00]],
        4 => [[13.50, 9.00, 3.75, 3.75, 6.50, 8.25, 12.25, 19.50], [10.00, 6.75, 3.75, 3.75, 6.50, 8.25, 12.25, 19.50], [6.75, 4.50, 1.75, 1.75, 3.25, 4.00, 6.00, 9.75], [24.25, 16.25, 6.75, 6.75, 11.75, 14.75, 22.00, 35.00], [20.00, 13.50, 6.75, 6.75, 11.75, 14.75, 22.00, 35.00], [94.00, 67.75, 33.00, 33.00, 55.75, 75.00, 96.00, 116.25], [264.25, 197.25, 96.00, 96.00, 162.00, 218.25, 278.75, 338.25], [22.00, 17.00, 12.00, 12.00, 15.00, 16.00, 20.00, 28.00], [18.00, 15.00, 12.00, 12.00, 15.00, 16.00, 20.00, 28.00]],
        7 => [[15.25, 10.75, 3.75, 3.75, 3.75, 6.50, 10.75, 18.00], [11.25, 8.00, 3.75, 3.75, 3.75, 6.50, 10.75, 18.00], [7.50, 5.25, 1.75, 1.75, 1.75, 3.25, 5.25, 9.00], [27.50, 19.25, 6.75, 6.75, 6.75, 11.75, 19.25, 32.50], [22.50, 16.00, 6.75, 6.75, 6.75, 11.75, 19.25, 32.50], [106.50, 79.50, 33.00, 33.00, 33.00, 55.75, 83.00, 104.00], [299.75, 230.75, 96.00, 96.00, 96.00, 162.00, 241.50, 302.50], [23.00, 19.00, 12.00, 12.00, 12.00, 15.00, 19.00, 26.00], [19.00, 16.00, 12.00, 12.00, 12.00, 15.00, 19.00, 26.00]],
        9 => [[18.25, 13.25, 6.50, 3.75, 3.75, 3.75, 8.25, 14.75], [13.50, 9.75, 6.50, 3.75, 3.75, 3.75, 8.25, 14.75], [9.00, 6.50, 3.25, 1.75, 1.75, 1.75, 4.00, 7.25], [32.75, 23.75, 11.75, 6.75, 6.75, 6.75, 14.75, 26.50], [27.00, 19.50, 11.75, 6.75, 6.75, 6.75, 14.75, 26.50], [126.75, 95.50, 55.75, 33.00, 33.00, 33.00, 71.50, 92.00], [356.50, 277.75, 162.00, 96.00, 96.00, 96.00, 208.00, 267.50], [26.00, 21.00, 15.00, 12.00, 12.00, 12.00, 16.00, 23.00], [22.00, 18.00, 15.00, 12.00, 12.00, 12.00, 16.00, 23.00]],
        10 => [[21.50, 16.50, 8.25, 6.50, 3.75, 3.75, 3.75, 10.50], [16.00, 12.25, 8.25, 6.50, 3.75, 3.75, 3.75, 10.50], [10.75, 8.25, 4.00, 3.25, 1.75, 1.75, 1.75, 5.25], [38.75, 29.75, 14.75, 11.75, 6.75, 6.75, 6.75, 19.00], [32.00, 24.50, 14.75, 11.75, 6.75, 6.75, 6.75, 19.00], [140.25, 112.75, 75.00, 55.75, 33.00, 33.00, 33.00, 71.50], [394.50, 327.75, 218.25, 162.00, 96.00, 96.00, 96.00, 207.75], [30.00, 25.00, 16.00, 15.00, 12.00, 12.00, 12.00, 19.00], [24.00, 20.00, 16.00, 15.00, 12.00, 12.00, 12.00, 19.00]],
        12 => [[25.50, 22.00, 12.25, 10.75, 8.25, 3.75, 3.75, 7.25], [18.75, 16.25, 12.25, 10.75, 8.25, 3.75, 3.75, 7.25], [12.75, 11.00, 6.00, 5.25, 4.00, 1.75, 1.75, 3.50], [46.00, 39.50, 22.00, 19.25, 14.75, 6.75, 6.75, 13.00], [37.50, 32.50, 22.00, 19.25, 14.75, 6.75, 6.75, 13.00], [160.75, 135.75, 96.00, 83.00, 71.50, 33.00, 33.00, 58.50], [452.00, 394.50, 278.75, 241.50, 208.00, 96.00, 96.00, 170.25], [34.00, 30.00, 20.00, 19.00, 16.00, 12.00, 12.00, 15.00], [27.00, 24.00, 20.00, 19.00, 16.00, 12.00, 12.00, 15.00]],
        14 => [[33.00, 28.25, 19.50, 18.00, 14.75, 10.50, 7.25, 3.75], [24.50, 21.00, 19.50, 18.00, 14.75, 10.50, 7.25, 3.75], [16.50, 14.00, 9.75, 9.00, 7.25, 5.25, 3.50, 1.75], [59.50, 50.75, 35.00, 32.50, 26.50, 19.00, 13.00, 6.75], [49.00, 42.00, 35.00, 32.50, 26.50, 19.00, 13.00, 6.75], [173.50, 149.50, 116.25, 104.00, 92.00, 71.50, 58.50, 33.00], [487.75, 434.25, 338.25, 302.50, 267.50, 207.75, 170.25, 96.00], [41.00, 36.00, 28.00, 26.00, 23.00, 19.00, 15.00, 12.00], [33.00, 29.00, 28.00, 26.00, 23.00, 19.00, 15.00, 12.00]],
    ];
    $out = [];
    foreach ($t as $from => $cols) {
        foreach ($z as $i => $to) {
            $out[$from][$to] = array_map(function ($c) use ($i) { return $c[$i]; }, $cols);
        }
    }
    return $out;
}

/** Metro-North fares to and from Grand Central / Harlem-125 St by zone: [peak, offpeak, senior, day_wk, day_we, weekly, monthly, onb_peak, onb_off]. NY stations from January 4, 2026; Connecticut stations (zones 15+) from July 1, 2026. */
function mta_nt_mnr_fares()
{
    return [
        1 => [7.25, 5.25, 3.50, 14.50, 10.50, 64.00, 162.00, 15.00, 13.00],
        2 => [7.25, 5.25, 3.50, 14.50, 10.50, 74.00, 187.50, 15.00, 13.00],
        3 => [12.50, 9.25, 6.25, 22.50, 18.50, 86.50, 243.50, 21.00, 17.00],
        4 => [13.75, 10.25, 6.75, 24.75, 20.50, 96.50, 271.50, 22.00, 18.00],
        5 => [16.00, 11.75, 8.00, 28.75, 23.50, 111.25, 313.00, 24.00, 20.00],
        6 => [19.00, 14.00, 9.50, 34.25, 28.00, 133.25, 374.75, 27.00, 22.00],
        7 => [21.75, 16.00, 10.75, 39.25, 32.00, 151.25, 425.50, 30.00, 24.00],
        8 => [25.00, 18.50, 12.50, 45.00, 37.00, 165.75, 466.25, 33.00, 27.00],
        9 => [28.25, 21.00, 14.00, 50.75, 42.00, 177.75, 500.00, 36.00, 29.00],
        10 => [29.75, 22.00, 14.75, 53.50, 44.00, 177.75, 500.00, 38.00, 30.00],
        11 => [7.25, 5.25, 3.50, 14.50, 10.50, 74.00, 187.50, 15.00, 13.00],
        12 => [12.25, 9.00, 6.00, 22.00, 18.00, 86.50, 243.25, 20.00, 17.00],
        13 => [13.75, 10.25, 6.75, 24.75, 20.50, 96.50, 271.50, 22.00, 18.00],
        14 => [15.00, 11.00, 7.50, 27.00, 22.00, 104.00, 292.25, 23.00, 19.00],
        15 => [16.00, 11.75, 8.00, 28.75, 23.50, 111.75, 314.25, 24.00, 20.00],
        16 => [17.75, 13.25, 8.75, 32.00, 26.50, 124.25, 349.50, 26.00, 21.00],
        17 => [19.00, 14.00, 9.50, 34.25, 28.00, 132.50, 372.75, 27.00, 22.00],
        18 => [20.75, 15.25, 10.25, 37.25, 30.50, 144.00, 405.25, 29.00, 23.00],
        19 => [22.75, 16.75, 11.25, 41.00, 33.50, 156.50, 440.50, 31.00, 25.00],
        20 => [24.25, 18.00, 12.00, 43.75, 36.00, 165.50, 465.25, 32.00, 26.00],
        21 => [27.25, 20.25, 13.50, 49.00, 40.50, 184.25, 518.50, 35.00, 28.00],
        31 => [17.75, 13.25, 8.75, 32.00, 26.50, 124.25, 349.50, 26.00, 21.00],
        41 => [19.75, 14.50, 9.75, 35.50, 29.00, 137.50, 386.75, 28.00, 23.00],
        42 => [20.75, 15.25, 10.25, 37.25, 30.50, 145.00, 407.75, 29.00, 23.00],
        51 => [24.25, 18.00, 12.00, 43.75, 36.00, 168.25, 473.50, 32.00, 26.00],
    ];
}

const MTA_NT_FARE_KEYS = ['peak' => 'One way, peak', 'offpeak' => 'One way, off-peak', 'senior' => 'One way, senior / disability / Medicare', 'day_wk' => 'Day Pass, weekday',
                          'day_we' => 'Day Pass, weekend', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'onb_peak' => 'Bought on the train, peak', 'onb_off' => 'Bought on the train, off-peak'];

/** Stations by fare zone for one railroad: zone => [names]. */
function mta_nt_fare_zones($k)
{
    $z = [];
    foreach (mta_tm_rail_stations($k) as $s) {
        if ($s['zone'] !== '') {
            $z[(int) $s['zone']][] = $s['name'];
        }
    }
    ksort($z);
    foreach ($z as &$n) {
        sort($n);
    }
    return $z;
}

function mta_nt_money($v)
{
    return '$' . number_format((float) $v, 2);
}

function mta_nt_fares_faq()
{
    $l = mta_nt_lirr_fares();
    $m = mta_nt_mnr_fares();
    return [['How much is the LIRR from Penn Station?', 'Within the city (Zones 1 and 3) a one-way ticket is $7.25 peak and $5.25 off-peak. Zone 4 (western Nassau, like Mineola) is ' . mta_nt_money($l[1][4][0]) . ' peak and ' .
                mta_nt_money($l[1][4][1]) . ' off-peak, and the farthest zone, Zone 14 (the Hamptons, Montauk and Greenport), is ' . mta_nt_money($l[1][14][0]) . ' peak.'],
            ['What are LIRR peak hours?', 'Weekday trains arriving at New York City terminals between 6 and 10 AM, or leaving them between 4 and 8 PM. Every other train, and all weekend and holiday trains, are off-peak.'],
            ['How much is Metro-North to Grand Central?', 'From the Bronx (Zone 2) a one-way ticket is $7.25 peak and $5.25 off-peak. From Stamford (Zone 16) it is ' . mta_nt_money($m[16][0]) . ' peak and ' . mta_nt_money($m[16][1]) .
                ' off-peak; from Poughkeepsie (Zone 9) it is ' . mta_nt_money($m[9][0]) . ' peak.'],
            ['Is it cheaper to buy a ticket before boarding?', 'Yes. Buying on the train costs much more, for example $15.00 instead of $7.25 for a peak ride within the city. Buy in the TrainTime app or at a ticket machine first, unless your station has no ticket machine.'],
            ['Do seniors get a discount on the LIRR and Metro-North?', 'Yes. Riders 65 and over, riders with disabilities and Medicare cardholders pay about half fare, for example $3.50 for a ride within the city. Some weekday morning rush-hour trains to the city are not discounted; check mta.info before you ride.']];
}

function mta_nt_fares_page()
{
    if (!mta_nt_live()) {
        return '';
    }
    $zl = mta_nt_fare_zones('lirr');
    $zm = mta_nt_fare_zones('mnr');
    if (!$zl || !$zm) {
        return '<p>Station data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $l = mta_nt_lirr_fares();
    $m = mta_nt_mnr_fares();
    $keys = array_keys(MTA_NT_FARE_KEYS);
    $st = [];
    foreach (['lirr', 'mnr'] as $k) {
        foreach (mta_tm_rail_stations($k) as $s) {
            if ($s['zone'] !== '') {
                $st[$k][] = [$s['name'], (int) $s['zone'], esc_url_raw(mta_tm_rail_url($k, $s['slug']))];
            }
        }
        usort($st[$k], function ($a, $b) { return strcasecmp($a[0], $b[0]); });
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_script('mta-fares', $base . 'assets/mta-fares.js', [], '1.0.0', true);
    wp_add_inline_script('mta-fares', 'window.MTA_FARES = ' . wp_json_encode(['stations' => $st, 'lirr' => $l, 'mnr' => $m, 'keys' => MTA_NT_FARE_KEYS], JSON_UNESCAPED_SLASHES) . ';', 'before');

    $o = mta_nt_hero('LIRR & Metro-North Fare Calculator (2026)', 'Peak, off-peak, weekly and monthly ticket prices between any two LIRR stations, and from any Metro-North station to Grand Central, from the official fare charts · Not affiliated with the MTA',
        [mta_more_tile('$7.25', 'peak ride in the city'), mta_more_tile(mta_nt_money($l[1][14][0]), 'longest LIRR ride, peak'), mta_more_tile(number_format(count($st['lirr']) + count($st['mnr'])), 'stations')]);

    $o .= mta_more_sec('Find your fare', '<div class="mta-fares" data-mta-fares>' .
        '<div class="mta-bullets" role="group" aria-label="Railroad"><button type="button" class="mta-find-pick on" data-rr="lirr" aria-pressed="true">LIRR</button><button type="button" class="mta-find-pick" data-rr="mnr" aria-pressed="false">Metro-North</button></div>' .
        '<p><label>From <select data-fare-from></select></label> <label>To <select data-fare-to></select></label> <button type="button" class="mta-find-pick" data-fare-swap aria-label="Swap stations">⇄</button></p>' .
        '<div data-fare-out aria-live="polite"><p class="mta-note">Pick two stations to see the fares.</p></div></div>',
        'LIRR: any two stations. Metro-North: to or from Grand Central / Harlem-125 St (trips between two other Metro-North stations have their own prices; see mta.info).');

    // The same numbers without the script: fares to the city for each zone.
    $rows = [];
    foreach ($zl as $z => $names) {
        $f = $l[1][$z] ?? null;
        if ($f) {
            $rows[] = ['<strong>Zone ' . $z . '</strong><br><small class="mta-note">' . esc_html(implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? ' and ' . (count($names) - 6) . ' more' : '')) . '</small>',
                       mta_nt_money($f[0]), mta_nt_money($f[1]), mta_nt_money($f[5]), mta_nt_money($f[6])];
        }
    }
    $o .= mta_more_sec('LIRR fares to Penn Station, Grand Central and Atlantic Terminal', mta_more_table(['Zone and stations', 'Peak', 'Off-peak', 'Weekly', 'Monthly'], $rows),
        'One-way prices to or from Zone 1 (Manhattan, Brooklyn and western Queens). Effective January 4, 2026. For trips between Zones 4 and 14, peak and off-peak cost the same.');

    $rows = [];
    foreach ($zm as $z => $names) {
        $f = $m[$z] ?? null;
        if ($f && $z > 1) {
            $rows[] = ['<strong>Zone ' . $z . '</strong><br><small class="mta-note">' . esc_html(implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? ' and ' . (count($names) - 6) . ' more' : '')) . '</small>',
                       mta_nt_money($f[0]), mta_nt_money($f[1]), mta_nt_money($f[5]), mta_nt_money($f[6])];
        }
    }
    $o .= mta_more_sec('Metro-North fares to Grand Central', mta_more_table(['Zone and stations', 'Peak', 'Off-peak', 'Weekly', 'Monthly'], $rows),
        'One-way prices to or from Grand Central and Harlem-125 St. New York stations since January 4, 2026; Connecticut stations since July 1, 2026. Zones 31-51 are the New Canaan, Danbury and Waterbury branches.');

    $o .= mta_more_sec('Ways to pay less', '<ul><li>' . esc_html('Buy before you board: tickets bought on the train cost about twice as much in the city.') . '</li>' .
        '<li>' . esc_html('Ride off-peak when you can: off-peak tickets are about 25% cheaper on trips to the city.') . '</li>' .
        '<li>' . esc_html('Off-peak rides inside the city (LIRR Zones 1 and 3, Metro-North Zones 1 and 2) are $5.25.') . '</li>' .
        '<li>' . esc_html('A monthly ticket costs about the same as 10 to 12 peak round trips, so it pays off if you ride most weekdays.') . '</li>' .
        '<li><a href="' . esc_url(home_url('/' . MTA_MORE_PAGES['lirr'] . '/')) . '">' . esc_html('LIRR on-time performance and ridership') . '</a> · <a href="' . esc_url(home_url('/' . MTA_MORE_PAGES['mnr'] . '/')) . '">' . esc_html('Metro-North on-time performance') . '</a></li></ul>');

    $o .= mta_tm_faq_html(mta_nt_fares_faq());
    $o .= mta_ridership_ssr_more('fares');
    $o .= '<p class="mta-source mta-source-ssr">Prices: MTA fare charts (<a href="https://www.mta.info/fares-tolls/lirr-metro-north" rel="noopener">mta.info</a>): LIRR effective January 4, 2026; Metro-North New York stations January 4, 2026 and Connecticut stations July 1, 2026. ' .
        'Stations and zones: MTA open data. Checked ' . esc_html(gmdate('M j, Y', strtotime(MTA_NT_PRICES_CHECKED))) . '.<br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return mta_nt_wrap($o);
}

add_shortcode('mta_rail_fares', 'mta_nt_fares_page');

/* ---------- titles, descriptions, FAQ markup and link previews ---------- */

add_filter('mta_nt_seo', function ($s) {
    if ($s || !mta_nt_live()) {
        return $s;
    }
    if (is_page(MTA_NT_PAGES['changes']) && ($f = mta_nt_changes_facts())) {
        return ['title' => 'NYC Subway Service Changes & Delays This Weekend',
                'description' => 'Live NYC subway delays and this weekend\'s planned work for every line, in the MTA\'s own words, plus which lines have the most delays. Refreshed every few minutes.',
                'faq' => mta_nt_changes_faq($f)];
    }
    if (is_page(MTA_NT_PAGES['projects']) && ($f = mta_nt_projects_facts())) {
        return ['title' => 'MTA Construction Projects: Second Avenue Subway, IBX & Costs',
                'description' => 'All ' . count($f['all']) . ' MTA capital projects: Second Avenue Subway Phase 2, the Interborough Express, new train cars and accessible stations, with costs and finish dates.',
                'faq' => mta_nt_projects_faq($f)];
    }
    if (is_page(MTA_NT_PAGES['fares'])) {
        return ['title' => 'LIRR & Metro-North Fare Calculator 2026: Peak, Off-Peak & Monthly',
                'description' => 'Find the LIRR or Metro-North fare for your trip: one-way peak and off-peak, weekly and monthly tickets for every station, from the January 2026 fare charts.',
                'faq' => mta_nt_fares_faq()];
    }
    return $s;
});

add_filter('mta_share_cards', function ($cards) {
    if (!mta_nt_live() && !(defined('WP_CLI') && WP_CLI)) {
        return $cards;
    }
    return $cards + [
        'changes' => ['share.jpg', function () {
            $f = mta_nt_changes_facts();
            if (!$f) {
                return null;
            }
            $bars = [];
            foreach (array_slice($f['lines'], 0, 6, true) as $c => $n) {
                $bars[$f['names'][$c][1]] = $n;
            }
            return ['title' => 'NYC Subway Service Changes', 'subtitle' => 'Delays right now and this weekend\'s planned work', 'stats' => [[number_format($f['per_day']), 'delay alerts a day'],
                    [mta_ridership_hour_name($f['peak_hour']), 'busiest hour for delays']], 'bars_title' => 'Most delay alerts (12 months)', 'bars' => mta_tm_card_bars($bars), 'fmt' => 'number_format',
                    'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['changes'] . '  ·  MTA alerts, live'];
        }],
        'projects' => ['share.jpg', function () {
            $f = mta_nt_projects_facts();
            if (!$f) {
                return null;
            }
            $bars = [];
            foreach (array_slice($f['all'], 0, 6) as $p) {
                $bars[$p['title']] = round(($f['cost'])($p) / 1e6);
            }
            return ['title' => 'MTA Construction Projects', 'subtitle' => 'Second Avenue Subway, IBX, new trains', 'stats' => [[number_format(count($f['all'])), 'projects'],
                    [str_replace(' billion', 'B', mta_more_money($f['total'])), 'listed costs']], 'bars_title' => 'Biggest projects ($ millions)', 'bars' => mta_tm_card_bars($bars),
                    'fmt' => function ($v) { return '$' . number_format($v) . 'M'; }, 'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['projects'] . '  ·  MTA open data, updated monthly'];
        }],
        'fares' => ['share.jpg', function () {
            $l = mta_nt_lirr_fares();
            $bars = [];
            foreach ([3 => 'Zone 3 (Queens)', 4 => 'Zone 4 (W. Nassau)', 7 => 'Zone 7', 9 => 'Zone 9', 10 => 'Zone 10', 14 => 'Zone 14 (East End)'] as $z => $n) {
                $bars[$n] = $l[1][$z][0];
            }
            return ['title' => 'LIRR & Metro-North Fares', 'subtitle' => 'Fare calculator for every station, 2026', 'stats' => [['$7.25', 'peak ride in the city'], ['$5.25', 'off-peak in the city']],
                    'bars_title' => 'LIRR peak fare to Penn Station', 'bars' => mta_tm_card_bars($bars), 'fmt' => function ($v) { return '$' . number_format($v, 2); },
                    'footer' => 'mantiereidii.com/' . MTA_NT_PAGES['fares'] . '  ·  MTA fare charts, January 2026'];
        }],
    ];
});
