<?php
/**
 * Subway line report cards, [mta_lines]: every line ranked by how often its trains run on time, plus a
 * report card per line at /subway-lines/<line>/ (on-time rate, trips 5+ minutes late, extra wait,
 * delays and their causes, major incidents), which the MTA publishes only as separate dashboards.
 * Data (monthly, per line): Terminal On-Time Performance (f6rf-2a3t), Customer Journey-Focused Metrics
 * (r7qk-6tcy), Trains Delayed (9zbp-wz3y), Wait Assessment (s666-h6b7), Major Incidents (ereg-mcvp).
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_LINES_HOOK   = 'mta_ridership_lines_step';
const MTA_LINES_OPTION = 'mta_ridership_lines';
const MTA_LINES_SLUG   = 'subway-lines';

/** MTA line code => [slug, display name]. The W has no on-time data, so it's left out. */
function mta_lines_list()
{
    $l = [];
    foreach (['1', '2', '3', '4', '5', '6', '7', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'L', 'M', 'N', 'Q', 'R'] as $x) {
        $l[$x] = [strtolower($x), $x . ' train'];
    }
    $l['JZ'] = ['j-z', 'J/Z train'];
    $l['S 42nd'] = ['42-st-shuttle', '42 St Shuttle'];
    $l['S Fkln'] = ['franklin-av-shuttle', 'Franklin Av Shuttle'];
    $l['S Rock'] = ['rockaway-park-shuttle', 'Rockaway Park Shuttle'];
    return $l;
}

/** Wait Assessment uses its own names for a few lines. */
function mta_lines_code($line)
{
    $alias = ['GS' => 'S 42nd', 'FS' => 'S Fkln', 'H' => 'S Rock', 'J' => 'JZ'];
    return $alias[$line] ?? $line;
}

add_action(MTA_LINES_HOOK, 'mta_lines_step');

add_action('init', function () {
    add_rewrite_rule('^' . MTA_LINES_SLUG . '/([a-z0-9-]+)/?$', 'index.php?pagename=' . MTA_LINES_SLUG . '&mta_line=$matches[1]', 'top');
    if (get_option('mta_lines_rewrite') !== '1') {
        flush_rewrite_rules(false);
        update_option('mta_lines_rewrite', '1');
    }
    if (!get_option(MTA_LINES_OPTION) && get_option(MTA_RIDERSHIP_OPTION)) {
        mta_lines_schedule(10);
    }
});
add_filter('query_vars', function ($v) {
    $v[] = 'mta_line';
    return $v;
});

function mta_lines_schedule($delay)
{
    if (!wp_next_scheduled(MTA_LINES_HOOK)) {
        wp_schedule_single_event(time() + $delay, MTA_LINES_HOOK);
    }
}

function mta_lines_api($id, array $params)
{
    return mta_ridership_api("https://data.ny.gov/resource/$id.json", $params + ['$limit' => 50000]);
}

function mta_lines_step()
{
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    try {
        $known = mta_lines_list();
        $max = mta_lines_api('f6rf-2a3t', ['$select' => 'max(month) AS m']);
        $latest = substr($max[0]['m'] ?? '', 0, 7);
        if (!$latest) {
            throw new Exception('No on-time data returned');
        }
        $month = function ($n) use ($latest) { return gmdate('Y-m', strtotime("$latest-01 -$n month UTC")); };
        $start = $month(23);
        $year_from = $month(11); // last 12 months; the 12 before them are for the trend
        $where = "month >= '$start-01'";
        $in_year = function ($m) use ($year_from) { return $m >= $year_from; };

        // [line][month] => metric parts
        $m = [];
        foreach (mta_lines_api('f6rf-2a3t', ['$select' => 'month, line, sum(num_on_time_trips) AS a, sum(num_sched_trips) AS b',
                                             '$where' => $where, '$group' => 'month, line']) as $r) {
            $m[$r['line']][substr($r['month'], 0, 7)]['otp'] = [(float) $r['a'], (float) $r['b']];
        }
        foreach (mta_lines_api('r7qk-6tcy', ['$select' => 'month, line, sum(num_passengers) AS p, sum(total_apt) AS apt, sum(total_att) AS att, sum(over_five_mins) AS o5',
                                             '$where' => $where, '$group' => 'month, line']) as $r) {
            $m[$r['line']][substr($r['month'], 0, 7)]['cj'] = [(float) $r['p'], (float) $r['apt'], (float) $r['att'], (float) $r['o5']];
        }
        foreach (mta_lines_api('9zbp-wz3y', ['$select' => 'month, line, reporting_category AS c, sum(delays) AS d',
                                             '$where' => $where, '$group' => 'month, line, c']) as $r) {
            $mm = substr($r['month'], 0, 7);
            $m[$r['line']][$mm]['delays'][$r['c']] = ($m[$r['line']][$mm]['delays'][$r['c']] ?? 0) + (float) $r['d'];
        }
        $wait = [];
        foreach (mta_lines_api('s666-h6b7', ['$select' => 'line, period, sum(num_timepoints_passing_wait_assessment) AS a, sum(num_sched_timepoints) AS b',
                                             '$where' => "month >= '$year_from-01'", '$group' => 'line, period']) as $r) {
            $wait[mta_lines_code($r['line'])][$r['period']] = [(float) $r['a'], (float) $r['b']];
        }
        $incidents = [];
        foreach (mta_lines_api('ereg-mcvp', ['$select' => 'line, sum(count) AS n', '$where' => "month >= '$year_from-01'", '$group' => 'line']) as $r) {
            if (!empty($r['line'])) {
                $incidents[$r['line']] = (int) $r['n'];
            }
        }

        // Per line: last 12 months, the 12 before, and the monthly series. System = all lines added up.
        $sum = function ($rows, $key, $from, $to) {
            $out = [];
            foreach ($rows as $mm => $x) {
                if ($mm >= $from && $mm <= $to && isset($x[$key])) {
                    foreach ((array) $x[$key] as $i => $v) {
                        $out[$i] = ($out[$i] ?? 0) + $v;
                    }
                }
            }
            return $out;
        };
        $months = [];
        for ($i = 23; $i >= 0; $i--) {
            $months[] = $month($i);
        }
        $all = [];
        foreach ($m as $line => $rows) {
            foreach ($rows as $mm => $x) {
                foreach ($x as $k => $v) {
                    foreach ((array) $v as $i => $n) {
                        $all[$mm][$k][$i] = ($all[$mm][$k][$i] ?? 0) + $n;
                    }
                }
            }
        }
        $card = function ($rows, $line) use ($sum, $latest, $year_from, $month, $months, $wait, $incidents) {
            $otp = $sum($rows, 'otp', $year_from, $latest);
            $otp_prev = $sum($rows, 'otp', $month(23), $month(12));
            $cj = $sum($rows, 'cj', $year_from, $latest);
            $causes = $sum($rows, 'delays', $year_from, $latest);
            arsort($causes);
            $pct = function ($a) { return isset($a[1]) && $a[1] > 0 ? round($a[0] / $a[1] * 100, 1) : null; };
            $w = $line ? ($wait[$line] ?? []) : null;
            return [
                'otp' => $pct($otp), 'otp_prev' => $pct($otp_prev),
                'late5' => !empty($cj[0]) ? round($cj[3] / $cj[0] * 100, 1) : null,
                'extra_wait' => !empty($cj[0]) ? round($cj[1] / $cj[0], 2) : null,  // minutes
                'extra_ride' => !empty($cj[0]) ? round($cj[2] / $cj[0], 2) : null,
                'riders_month' => !empty($cj[0]) ? (int) round($cj[0] / 12) : null,
                'delays_month' => (int) round(array_sum($causes) / 12),
                'causes' => array_map(function ($v) { return (int) round($v); }, $causes),
                'wait_peak' => $w ? $pct($w['peak'] ?? []) : null, 'wait_offpeak' => $w ? $pct($w['offpeak'] ?? []) : null,
                'incidents' => $line ? ($incidents[$line] ?? 0) : array_sum($incidents),
                'series' => array_map(function ($mm) use ($rows, $pct) {
                    $c = $rows[$mm]['cj'] ?? null;
                    return [$pct($rows[$mm]['otp'] ?? []), $c && $c[0] ? round($c[3] / $c[0] * 100, 1) : null];
                }, $months),
            ];
        };
        $lines = [];
        foreach ($known as $code => [$slug, $name]) {
            if (!empty($m[$code])) {
                $lines[$code] = ['code' => $code, 'slug' => $slug, 'name' => $name] + $card($m[$code], $code);
            }
        }
        // Rank: 1 = most on time.
        uasort($lines, function ($a, $b) { return $b['otp'] <=> $a['otp']; });
        $i = 0;
        foreach ($lines as &$l) {
            $l['rank'] = ++$i;
        }
        unset($l);

        update_option(MTA_LINES_OPTION, [
            'updated' => gmdate('c'), 'latest' => $latest, 'from' => $year_from, 'months' => $months,
            'system' => $card($all, null), 'lines' => $lines,
        ], false);
        delete_option('mta_ridership_lines_error');
        mta_lines_clear_cache();
    } catch (Throwable $e) {
        update_option('mta_ridership_lines_error', gmdate('c') . ' ' . $e->getMessage(), false);
    }
}

function mta_lines_page()
{
    return get_page_by_path(MTA_LINES_SLUG, OBJECT, 'page');
}

function mta_lines_clear_cache()
{
    $page = mta_lines_page();
    if (!$page || !function_exists('rocket_clean_post')) {
        return;
    }
    rocket_clean_post($page->ID);
    $d = get_option(MTA_LINES_OPTION);
    if ($d && function_exists('rocket_clean_files')) {
        rocket_clean_files(array_map('mta_lines_url', array_column($d['lines'], 'slug')));
    }
}

function mta_lines_url($slug = '')
{
    return home_url('/' . MTA_LINES_SLUG . '/' . ($slug ? $slug . '/' : ''));
}

/** The line being viewed (/subway-lines/<slug>/), or null on the ranking page. */
function mta_lines_current()
{
    $slug = get_query_var('mta_line');
    $d = get_option(MTA_LINES_OPTION);
    if (!$slug || !$d) {
        return null;
    }
    foreach ($d['lines'] as $l) {
        if ($l['slug'] === $slug) {
            return $l;
        }
    }
    return null;
}

// An unknown line slug is a real 404, not the ranking page.
add_action('template_redirect', function () {
    if (get_query_var('mta_line') && !mta_lines_current()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    }
});

function mta_lines_month($ym)
{
    return gmdate('F Y', strtotime("$ym-01 UTC"));
}

function mta_lines_causes_text($l)
{
    $t = array_sum($l['causes']);
    $out = [];
    foreach (array_slice($l['causes'], 0, 3, true) as $c => $n) {
        $out[] = strtolower($c) . ' (' . round($n / max(1, $t) * 100) . '%)';
    }
    return implode(', ', $out);
}

function mta_lines_answers($d, $l = null)
{
    $s = $d['system'];
    $range = mta_lines_month($d['from']) . ' – ' . mta_lines_month($d['latest']);
    $n = count($d['lines']);
    if (!$l) {
        $worst = array_values($d['lines'])[$n - 1];
        $best = array_values(array_filter($d['lines'], function ($x) { return strpos($x['code'], 'S ') !== 0; }))[0];
        return [
            'worst' => 'By on-time rate, the least reliable line is the ' . $worst['name'] . ': ' . $worst['otp'] . '% of its trips reached the end of ' .
                'the line within 5 minutes of schedule (' . $range . '), compared with ' . $s['otp'] . '% for the whole subway.',
            'best' => 'Apart from the short shuttles, the most reliable line is the ' . $best['name'] . ', with ' . $best['otp'] . '% of trips on time.',
            'why' => 'The main causes of subway delays over the last 12 months were ' . mta_lines_causes_text($s) . '.',
            'late' => 'Across the subway, ' . $s['late5'] . '% of trips took at least 5 minutes longer than scheduled, and riders waited on average ' .
                round($s['extra_wait'] * 60) . ' seconds longer on the platform than the schedule says.',
        ];
    }
    $better = $l['otp'] >= $s['otp'];
    $a = [
        'reliable' => 'The ' . $l['name'] . ' ran ' . $l['otp'] . '% of its trips on time (' . $range . '), ' . ($better ? 'better' : 'worse') .
            ' than the subway as a whole (' . $s['otp'] . '%). It ranks #' . $l['rank'] . ' of ' . $n . ' lines, where #1 is the most on time.',
        'why' => 'The ' . $l['name'] . ' averaged about ' . number_format($l['delays_month']) . ' delayed trains a month. The main causes: ' . mta_lines_causes_text($l) . '.',
    ];
    if ($l['late5'] !== null) {
        $a['late'] = 'About ' . $l['late5'] . '% of ' . $l['name'] . ' trips took at least 5 minutes longer than scheduled (subway: ' . $s['late5'] . '%). ' .
            'Riders waited on average ' . round($l['extra_wait'] * 60) . ' seconds longer on the platform than the schedule says.';
    }
    if ($l['otp_prev'] !== null) {
        $diff = round($l['otp'] - $l['otp_prev'], 1);
        $a['trend'] = 'Its on-time rate ' . ($diff >= 0 ? 'improved by ' : 'fell by ') . abs($diff) . ' points compared with the 12 months before (' . $l['otp_prev'] . '%).';
    }
    return $a;
}

function mta_lines_faq($d, $l = null)
{
    $a = mta_lines_answers($d, $l);
    $q = $l ? ['reliable' => "How reliable is the {$l['name']}?", 'why' => "Why is the {$l['name']} delayed?",
               'late' => "How often is the {$l['name']} late?", 'trend' => "Is the {$l['name']} getting better or worse?"]
            : ['worst' => 'What is the worst subway line in NYC?', 'best' => 'What is the most reliable subway line in NYC?',
               'why' => 'Why is the NYC subway delayed?', 'late' => 'How often is the NYC subway late?'];
    $out = [];
    foreach ($q as $k => $question) {
        if (isset($a[$k])) {
            $out[] = [$question, $a[$k]];
        }
    }
    return $out;
}

add_shortcode('mta_lines', function () {
    $d = get_option(MTA_LINES_OPTION);
    if (!$d) {
        mta_lines_schedule(5);
        return '<p>Subway line data is loading. Check back in a few minutes.</p>';
    }
    $l = mta_lines_current();
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.9.2');
    wp_enqueue_script('mta-lines', $base . 'assets/mta-lines.js', ['chartjs'], '1.9.2', true);
    $js = ['months' => $d['months'], 'system' => $d['system'], 'line' => $l];
    if (!$l) {
        $js['lines'] = array_values(array_map(function ($x) { return ['name' => $x['name'], 'otp' => $x['otp'], 'late5' => $x['late5']]; }, $d['lines']));
    }
    wp_add_inline_script('mta-lines', 'window.MTA_LINES = ' . wp_json_encode($js) . ';', 'before');

    $s = $d['system'];
    $n = count($d['lines']);
    $range = esc_html(mta_lines_month($d['from']) . ' – ' . mta_lines_month($d['latest']));
    $tile = function ($big, $small, $cls = '') {
        return '<div class="mta-tile' . $cls . '"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>';
    };
    $sec = function ($title, $body, $note = '') {
        return '<section class="mta-section"><h3>' . esc_html($title) . '</h3>' . ($note ? '<p class="mta-note">' . $note . '</p>' : '') . $body . '</section>';
    };
    $pills = '<p class="mta-find-picks">' . ($l ? '<a class="mta-find-pick" href="' . esc_url(mta_lines_url()) . '">All lines ranked</a> ' : '');
    foreach ($d['lines'] as $x) {
        $pills .= '<a class="mta-find-pick' . ($l && $x['code'] === $l['code'] ? ' on' : '') . '" href="' . esc_url(mta_lines_url($x['slug'])) . '">' . esc_html(str_replace(' train', '', $x['name'])) . '</a> ';
    }
    $pills .= '</p>';
    $a = mta_lines_answers($d, $l);
    $o = '';

    if ($l) {
        $o .= '<header class="mta-hero"><h1>' . esc_html($l['name']) . ': Delays, On-Time Rate &amp; Reliability</h1>' .
            '<p class="mta-hero-links">Report card from MTA open data, ' . $range . ' · Not affiliated with the MTA · ' .
            '<a href="https://www.mta.info/alerts" rel="noopener">Live service status on mta.info &rarr;</a></p><div class="mta-tiles mta-tiles-find">' .
            $tile($l['otp'] . '%', 'trips on time (subway: ' . $s['otp'] . '%)') .
            $tile('#' . $l['rank'] . ' of ' . $n, 'most on-time lines') .
            ($l['late5'] !== null ? $tile($l['late5'] . '%', 'trips 5+ min late') : '') .
            $tile(number_format($l['delays_month']), 'delayed trains a month') .
            '</div></header>';
        $o .= $sec('How reliable is the ' . $l['name'] . '?', '<p>' . esc_html($a['reliable']) . (isset($a['trend']) ? ' ' . esc_html($a['trend']) : '') . '</p><div data-slot="trend"></div>',
            'Share of trips reaching the last stop within 5 minutes of schedule, by month, vs the whole subway.');
        $o .= $sec('Why is the ' . $l['name'] . ' delayed?', '<p>' . esc_html($a['why']) . '</p><div data-slot="causes"></div>',
            'Delayed trains by cause, ' . $range . '. "Planned ROW work" is scheduled track work.');
        $rows = [
            ['Trips on time', $l['otp'] . '%', $s['otp'] . '%'],
            ['Trips 5+ minutes late', $l['late5'] !== null ? $l['late5'] . '%' : '–', $s['late5'] . '%'],
            ['Extra wait on the platform', $l['extra_wait'] !== null ? round($l['extra_wait'] * 60) . ' sec' : '–', round($s['extra_wait'] * 60) . ' sec'],
            ['Extra time on the train', $l['extra_ride'] !== null ? round($l['extra_ride'] * 60) . ' sec' : '–', round($s['extra_ride'] * 60) . ' sec'],
            ['Gaps kept to schedule, rush hour', $l['wait_peak'] !== null ? $l['wait_peak'] . '%' : '–', '–'],
            ['Major incidents (50+ trains delayed)', (string) $l['incidents'], (string) $s['incidents']],
            // Per-line trips (a transfer counts once on each line), so a subway total would double-count.
            ['Trips a month on this line (MTA estimate)', $l['riders_month'] ? number_format($l['riders_month']) : '–', '–'],
        ];
        $tr = '';
        foreach ($rows as [$k, $v, $sv]) {
            $tr .= '<tr><td>' . esc_html($k) . '</td><td>' . esc_html($v) . '</td><td>' . esc_html($sv) . '</td></tr>';
        }
        $o .= $sec('Report card', '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th></th><th>' . esc_html($l['name']) .
            '</th><th>Whole subway</th></tr></thead><tbody>' . $tr . '</tbody></table></div>', $range . '. Major incidents are counted per line, so the subway total is the sum.');
        $o .= $sec('Other lines', $pills);
    } else {
        $o .= '<header class="mta-hero"><h1>Which NYC Subway Line Is the Worst? Every Line Ranked</h1>' .
            '<p class="mta-hero-links">Report cards from MTA open data, ' . $range . ', updated monthly · Not affiliated with the MTA</p><div class="mta-tiles">' .
            $tile($s['otp'] . '%', 'subway trips on time') . $tile($s['late5'] . '%', 'trips 5+ min late') .
            $tile(number_format($s['delays_month']), 'delayed trains a month') . '</div></header>';
        $o .= $sec('Pick a line', $pills);
        $tr = '';
        foreach ($d['lines'] as $x) {
            $diff = $x['otp_prev'] !== null ? round($x['otp'] - $x['otp_prev'], 1) : null;
            $tr .= '<tr><td>' . $x['rank'] . '</td><td><a href="' . esc_url(mta_lines_url($x['slug'])) . '">' . esc_html($x['name']) . '</a></td><td>' . $x['otp'] .
                '%</td><td class="' . ($diff !== null && $diff < 0 ? 'neg' : 'pos') . '">' . ($diff === null ? '–' : ($diff > 0 ? '+' : '') . $diff) .
                '</td><td>' . ($x['late5'] !== null ? $x['late5'] . '%' : '–') . '</td><td>' . esc_html(ucfirst(strtolower((string) array_key_first($x['causes'])))) . '</td></tr>';
        }
        $o .= $sec('Every line ranked by on-time rate',
            '<p>' . esc_html($a['worst']) . ' ' . esc_html($a['best']) . '</p>' .
            '<div class="mta-table-wrap"><table class="mta-table"><thead><tr><th>#</th><th>Line</th><th>On time</th><th>Change (pts)</th><th>5+ min late</th><th>Top delay cause</th></tr></thead><tbody>' .
            $tr . '</tbody></table></div><div data-slot="ranking"></div>',
            $range . '. On time = reached the last stop within 5 minutes of schedule. Change is vs the 12 months before.');
        $o .= $sec('Why is the subway delayed?', '<p>' . esc_html($a['why']) . '</p><div data-slot="causes"></div>');
    }

    $faq = '';
    foreach (mta_lines_faq($d, $l) as [$q, $ans]) {
        $faq .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($ans) . '</p></details>';
    }
    $o .= '<section class="mta-section mta-faq"><h3>Questions</h3>' . $faq . '</section>';
    $o .= mta_ridership_ssr_more('lines');
    $o .= mta_ridership_ssr_talk(null, $l ? "How reliable is the {$l['name']}? Report card from MTA open data" : 'Which NYC subway line is the worst? Every line ranked from MTA open data');
    $o .= '<p class="mta-source mta-source-ssr">Data (data.ny.gov): <a href="https://data.ny.gov/d/f6rf-2a3t" rel="noopener">Terminal On-Time Performance</a>, ' .
        '<a href="https://data.ny.gov/d/r7qk-6tcy" rel="noopener">Customer Journey-Focused Metrics</a>, <a href="https://data.ny.gov/d/9zbp-wz3y" rel="noopener">Trains Delayed</a>, ' .
        '<a href="https://data.ny.gov/d/s666-h6b7" rel="noopener">Wait Assessment</a>, <a href="https://data.ny.gov/d/ereg-mcvp" rel="noopener">Major Incidents</a>. ' .
        'Updated ' . esc_html(gmdate('M j, Y', strtotime($d['updated']))) . '. The MTA\'s own charts: <a href="https://metrics.mta.info/?subway/operationalmetrics" rel="noopener">metrics.mta.info</a>.<br>' .
        'Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership mta-lines">' . $o . '</div>';
});

/* ---------- SEO: one title, description and canonical URL per line ---------- */

function mta_lines_is_page()
{
    if (!is_singular()) {
        return false;
    }
    $post = get_queried_object();
    return $post && has_shortcode($post->post_content ?? '', 'mta_lines');
}

function mta_lines_seo()
{
    $d = get_option(MTA_LINES_OPTION);
    if (!$d || !mta_lines_is_page()) {
        return null;
    }
    $l = mta_lines_current();
    $s = $d['system'];
    if ($l) {
        return ['d' => $d, 'l' => $l, 'url' => mta_lines_url($l['slug']),
                'title' => "{$l['name']} Delays & On-Time Rate: How Reliable Is It? (" . substr($d['latest'], 0, 4) . ')',
                'description' => "{$l['name']} report card: {$l['otp']}% of trips on time (subway: {$s['otp']}%), #{$l['rank']} of " . count($d['lines']) .
                    ' lines' . ($l['late5'] !== null ? ", {$l['late5']}% of trips 5+ minutes late" : '') . ', and what causes its delays. From MTA open data.'];
    }
    return ['d' => $d, 'l' => null, 'url' => mta_lines_url(),
            'title' => 'Worst NYC Subway Line? Every Line Ranked by Delays & On-Time Rate',
            'description' => "Every NYC subway line ranked by on-time rate, late trips and delays, with a report card for each line. Subway-wide: {$s['otp']}% of trips on time. From MTA open data."];
}

add_filter('wpseo_title', function ($t) { return ($s = mta_lines_seo()) ? $s['title'] . ' - ' . get_bloginfo('name') : $t; });
add_filter('wpseo_opengraph_title', function ($t) { return ($s = mta_lines_seo()) ? $s['title'] : $t; });
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($t) { return ($s = mta_lines_seo()) ? $s['description'] : $t; });
}
foreach (['wpseo_canonical', 'wpseo_opengraph_url'] as $f) {
    add_filter($f, function ($u) { return ($s = mta_lines_seo()) ? $s['url'] : $u; });
}
add_action('wp_head', function () {
    if (!($s = mta_lines_seo())) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode([
        '@context' => 'https://schema.org', '@type' => 'FAQPage',
        'mainEntity' => array_map(function ($i) {
            return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
        }, mta_lines_faq($s['d'], $s['l'])),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
});

// List every line's report card in Yoast's page sitemap (only once the page is published).
add_filter('wpseo_sitemap_page_content', function ($xml) {
    $page = mta_lines_page();
    $d = get_option(MTA_LINES_OPTION);
    if (!$page || $page->post_status !== 'publish' || !$d) {
        return $xml;
    }
    foreach ($d['lines'] as $l) {
        $xml .= '<url><loc>' . esc_url(mta_lines_url($l['slug'])) . '</loc><lastmod>' . esc_html(gmdate('c', strtotime($d['updated']))) . "</lastmod></url>\n";
    }
    return $xml;
});
