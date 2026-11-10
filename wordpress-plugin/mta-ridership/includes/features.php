<?php
/**
 * Small interactive extras on the MTA pages, in review (?mta_preview=1) until the owner says they can go live:
 *  - a "Search or ask" box: finds pages, stations, lines, bus routes and railroad stations, and answers questions
 *    from the site's own FAQ answers (no AI: word matching in the visitor's browser over /wp-json/mta-ridership/v1/index);
 *  - "Share" buttons on the big numbers at the top of each page;
 *  - "My stations": save subway stations on their pages and get quick links to them on every page (kept only in the
 *    visitor's own browser);
 *  - a "Which station is busier?" quiz and a two-station comparison (/subway-station-quiz/).
 * All of it is in assets/mta-extras.js and assets/mta-quiz.js.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_FEAT_QUIZ_SLUG = 'subway-station-quiz';

function mta_feat_live()
{
    return true; // live since 2026-11-04; return mta_ridership_preview() to put it back in review
}

/** Shorter station name for lists: "Times Sq-42 St (1,2,3...)/..." => "Times Sq-42 St". */
function mta_feat_station_name($name)
{
    return trim(preg_replace('/\s*\(.*$/', '', explode('/', $name)[0]));
}

/** Every FAQ answer that can be built without a page being open: [[question, answer, url], ...]. Each part is skipped if its data is missing. */
function mta_feat_faqs()
{
    $out = [];
    $add = function ($url, callable $fn) use (&$out) {
        try {
            foreach ((array) $fn() as $qa) {
                if (is_array($qa) && isset($qa[0], $qa[1])) {
                    $out[] = [(string) $qa[0], wp_strip_all_tags((string) $qa[1]), $url];
                }
            }
        } catch (Throwable $e) {
            // leave this page's answers out
        }
    };
    if (function_exists('mta_nt_live') && mta_nt_live()) {
        $add(mta_nt_url('sif'), function () { return ($f = mta_nt_sif_facts()) ? mta_nt_sif_faq($f) : []; });
        $add(mta_nt_url('ferry'), function () { return ($f = mta_nt_ferry_facts()) ? mta_nt_ferry_faq($f) : []; });
        $add(mta_nt_url('path'), function () { return ($f = mta_nt_path_facts()) ? mta_nt_path_faq($f) : []; });
        $add(mta_nt_url('omny'), function () { return ($f = mta_nt_omny_facts()) ? mta_nt_omny_faq($f) : []; });
        $add(mta_nt_url('tolls'), 'mta_nt_tolls_faq');
        $add(mta_nt_url('changes'), function () { return ($f = mta_nt_changes_facts()) ? mta_nt_changes_faq($f) : []; });
        $add(mta_nt_url('projects'), function () { return ($f = mta_nt_projects_facts()) ? mta_nt_projects_faq($f) : []; });
        $add(mta_nt_url('fares'), 'mta_nt_fares_faq');
    }
    if (function_exists('mta_tm_live') && mta_tm_live()) {
        $add(mta_tm_url('aar'), function () { return ($f = mta_tm_aar_facts()) ? mta_tm_aar_faq($f) : []; });
        $add(mta_tm_url('jobs'), function () { return ($f = mta_tm_jobs_facts()) ? mta_tm_jobs_faq($f) : []; });
        $add(mta_tm_url('sir'), function () { return ($f = mta_tm_sir_facts()) ? mta_tm_sir_faq($f) : []; });
        $add(mta_tm_url('ezpass'), function () { return ($e = get_option('mta_tm_ezpass')) ? mta_tm_ezpass_faq($e) : []; });
    }
    if (function_exists('mta_more_live') && mta_more_live()) {
        foreach (['lirr', 'mnr'] as $k) {
            $add(mta_more_url($k), function () use ($k) { return ($f = mta_more_rail_facts($k)) ? mta_more_rail_faq($k, $f) : []; });
        }
        $add(mta_more_url('pay'), function () { return ($p = get_option('mta_more_pay')) ? mta_more_pay_faq($p) : []; });
    }
    if (function_exists('mta_crime_live') && mta_crime_live() && ($p = mta_crime_page())) {
        $add(get_permalink($p), function () { return ($f = mta_crime_facts()) ? mta_crime_faq($f) : []; });
    }
    if (defined('MTA_LINES_OPTION')) {
        $add(home_url('/' . MTA_LINES_SLUG . '/'), function () { return ($d = get_option(MTA_LINES_OPTION)) ? mta_lines_faq($d) : []; });
    }
    if (defined('MTA_BUSES_OPTION')) {
        $add(mta_buses_url(), function () { return ($d = get_option(MTA_BUSES_OPTION)) ? mta_buses_faq($d) : []; });
    }
    return $out;
}

/** The search index: [title, url, kind, extra words] for every page, station, line, bus route and railroad station, plus the FAQ answers. */
function mta_feat_index()
{
    $key = 'mta_feat_index_' . (mta_ridership_preview() ? 'p' : 'l');
    $cached = get_transient($key);
    if ($cached !== false) {
        return $cached;
    }
    $items = [];
    // Pages: the same list as "More NYC transit data".
    if (preg_match_all('/<li><a href="([^"]+)">([^<]+)<\/a>: ([^<]+)<\/li>/', mta_ridership_ssr_more(''), $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $items[] = [html_entity_decode($x[2], ENT_QUOTES), html_entity_decode($x[1]), 'Page', html_entity_decode($x[3], ENT_QUOTES)];
        }
    }
    foreach (mta_stations_all() as $s) {
        $items[] = [mta_feat_station_name($s['name']), mta_stations_url($s['slug']), 'Subway station', $s['borough'] . ' · ' . number_format($s['avg']) . ' riders a day · ' . $s['name']];
    }
    foreach (mta_lines_list() as $code => [$slug, $name]) {
        $items[] = [$name, home_url('/' . MTA_LINES_SLUG . '/' . $slug . '/'), 'Subway line', 'on-time delays stops map'];
    }
    if (defined('MTA_BUSES_OPTION')) {
        foreach ((get_option(MTA_BUSES_OPTION)['routes'] ?? []) as $r) {
            if (!empty($r['slug'])) {
                $items[] = [mta_buses_label($r['id'] ?? $r['slug']) . ' bus', mta_buses_url($r['slug']), 'Bus route', ($r['name'] ?? '') . ' riders speed'];
            }
        }
    }
    if (function_exists('mta_tm_live') && mta_tm_live()) {
        foreach (['lirr' => 'LIRR station', 'mnr' => 'Metro-North station'] as $k => $kind) {
            foreach (mta_tm_rail_stations($k) as $s) {
                $items[] = [$s['name'], mta_tm_rail_url($k, $s['slug']), $kind, $s['branch'] . ' · Zone ' . $s['zone']];
            }
        }
    }
    $out = ['items' => $items, 'faq' => mta_feat_faqs(), 'built' => gmdate('c')];
    set_transient($key, $out, 12 * HOUR_IN_SECONDS);
    return $out;
}

add_action('rest_api_init', function () {
    register_rest_route('mta-ridership/v1', '/index', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true', // public: the same titles, links and answers the pages show
        'callback'            => function () {
            $res = new WP_REST_Response(mta_feat_index());
            $res->header('Cache-Control', 'public, max-age=3600');
            return $res;
        },
    ]);
});

// Drop the cached index when any data step finishes, so new numbers show up.
add_action('mta_ridership_step_done', function () {
    delete_transient('mta_feat_index_p');
    delete_transient('mta_feat_index_l');
});

/** The extras script on every page that has the plugin's content. */
add_action('wp_enqueue_scripts', function () {
    if (!mta_feat_live() || is_admin()) {
        return;
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_script('mta-extras', $base . 'assets/mta-extras.js', [], '1.0.0', true);
    wp_add_inline_script('mta-extras', 'window.MTA_EXTRAS = ' . wp_json_encode([
        'index' => add_query_arg(mta_ridership_preview() ? ['mta_preview' => 1] : [], rest_url('mta-ridership/v1/index')),
        'stations' => mta_stations_url(), 'quiz' => home_url('/' . MTA_FEAT_QUIZ_SLUG . '/'),
    ], JSON_UNESCAPED_SLASHES) . ';', 'before');
});

/* ---------- quiz and comparison page ---------- */

add_shortcode('mta_quiz', function () {
    if (!mta_feat_live()) {
        return '';
    }
    $all = mta_stations_all();
    if (!$all) {
        return '<p>Station data is loading. Check back in a few minutes.</p>';
    }
    mta_tm_css();
    $st = [];
    foreach ($all as $s) {
        $peak = $s['hours'] ? array_search(max($s['hours']), $s['hours'], true) : null;
        $st[] = [mta_feat_station_name($s['name']), $s['borough'], (int) $s['avg'], (int) $s['rank'], mta_stations_url($s['slug']), implode(' ', mta_stations_lines_of($s['name'])),
                 $peak === null ? '' : mta_ridership_hour_name($peak), $s['yoy']];
    }
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_script('mta-quiz', $base . 'assets/mta-quiz.js', [], '1.0.0', true);
    wp_add_inline_script('mta-quiz', 'window.MTA_QUIZ = ' . wp_json_encode(['stations' => $st, 'count' => count($st)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';', 'before');

    $o = mta_nt_hero('Subway Station Quiz: Which Station Is Busier?', 'Guess which of two NYC subway stations has more riders, then compare any two stations side by side. From MTA ridership data, updated daily · Not affiliated with the MTA',
        [mta_more_tile(number_format(count($st)), 'stations'), mta_more_tile(number_format(max(array_column($st, 2))), 'riders a day at the busiest')]);
    $o .= mta_more_sec('Which station is busier?', '<div class="mta-quiz" data-mta-quiz><p class="mta-note">Loading the quiz…</p></div>',
        'Tap the station you think has more riders on an average day. Your best streak is kept in this browser.');
    $o .= mta_more_sec('Compare two stations', '<div class="mta-compare" data-mta-compare><p class="mta-note">Loading…</p></div>',
        'Pick any two stations to see their riders, rank, lines and busiest hour side by side.');
    $o .= '<p class="mta-source mta-source-ssr">Data: <a href="https://data.ny.gov/d/5wq4-mkjj" rel="noopener">MTA Subway Hourly Ridership</a>, average riders a day over the last 12 months. ' .
        '<a href="' . esc_url(mta_stations_url()) . '">Every station ranked &rarr;</a><br>Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a> · Analysis by Mantie Reid II (CC BY 4.0).</p>';
    return '<div class="mta-ridership">' . $o . mta_ridership_ssr_more('quiz') . mta_ridership_disclaimer() . '</div>';
});

add_action('template_redirect', function () {
    if (is_page(MTA_FEAT_QUIZ_SLUG) && !mta_feat_live()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    }
});
add_filter('wp_robots', function ($r) {
    return is_page(MTA_FEAT_QUIZ_SLUG) && mta_ridership_preview() ? ['noindex' => true, 'nofollow' => true] + $r : $r;
});
add_filter('wpseo_exclude_from_sitemap_by_post_ids', function ($ids) {
    if ((!mta_feat_live() || mta_ridership_preview()) && ($p = get_page_by_path(MTA_FEAT_QUIZ_SLUG, OBJECT, 'page'))) {
        $ids[] = $p->ID;
    }
    return $ids;
});
foreach (['wpseo_title', 'wpseo_opengraph_title'] as $f) {
    add_filter($f, function ($t) use ($f) {
        if (!is_page(MTA_FEAT_QUIZ_SLUG) || !mta_feat_live()) {
            return $t;
        }
        $title = 'NYC Subway Quiz: Which Station Is Busier?';
        return $f === 'wpseo_title' ? mta_ridership_seo_title($title) : $title;
    });
}
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc'] as $f) {
    add_filter($f, function ($t) {
        return is_page(MTA_FEAT_QUIZ_SLUG) && mta_feat_live()
            ? mta_ridership_seo_desc('Guess which of two NYC subway stations has more riders, keep a streak, and compare any two of the 424 stations side by side. From MTA ridership data.') : $t;
    });
}
