<?php
/**
 * Search engine support for the page that uses [mta_ridership]:
 * - the headline, stat tiles and top-5 stations are written as plain HTML (JS adds the rest),
 * - a page title, meta description and share image (through Yoast SEO's filters),
 * - schema.org Dataset data, so the page can appear in Google Dataset Search.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** On for everyone. Return !empty($_GET['mta_preview']) here to test changes before going live. */
function mta_ridership_seo_enabled()
{
    return true;
}

const MTA_RIDERSHIP_REPO = 'https://github.com/MantieReid/mta-data-analysis';

/** Changes still in review show only with ?mta_preview=1. */
function mta_ridership_preview()
{
    return !empty($_GET['mta_preview']);
}

/**
 * The SEO pass of 2026-10-29 (shorter titles and descriptions, h2 section headings, share images, sub-page
 * data, stops and elevator lists, the "Where do riders go" card). Live; return mta_ridership_preview()
 * here to put it back behind ?mta_preview=1.
 */
function mta_ridership_seo_live()
{
    return true;
}

/** Source line at the bottom of the page: data source plus a link to the code. */
function mta_ridership_ssr_source($data)
{
    return '<p class="mta-source mta-source-ssr">Data: <a href="https://data.ny.gov/d/5wq4-mkjj" rel="noopener">MTA Subway Hourly Ridership</a>' .
        ' (data.ny.gov), ' . esc_html(mta_ridership_nice_date($data['first_day'])) . ' – ' . esc_html(mta_ridership_nice_date($data['latest_day'])) .
        '. Updated ' . esc_html(gmdate('M j, Y', strtotime($data['updated']))) . '.<br>' .
        'Source code and method: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a>' .
        ' · Analysis by Mantie Reid II, free to reuse with credit (CC BY 4.0).' . mta_ridership_status_link() . '</p>';
}

/** The disclaimer at the bottom of every MTA data page (the owner's wording). */
function mta_ridership_disclaimer()
{
    return '<div class="mta-disclaimer"><p>This is an independent data analysis project and is not affiliated with, sponsored by, or endorsed by the Metropolitan Transportation Authority (MTA).</p>' .
        '<p>Data is sourced from the MTA through the <a href="https://data.ny.gov/" rel="noopener">New York State Open Data Portal</a>. Visualizations, calculations, and interpretations are independently produced. ' .
        'Data may be revised, incomplete, or delayed. For official transit information, visit <a href="https://www.mta.info/" rel="noopener">mta.info</a>.</p></div>';
}

/** Page title for search results: the site name is added only while the whole title stays within
 *  60 characters (Google cuts longer ones). Off (mta_ridership_seo_live()) keeps the suffix. */
function mta_ridership_seo_title($t)
{
    $full = $t . ' - ' . get_bloginfo('name');
    return mta_ridership_seo_live() && mb_strlen($full) > 60 ? $t : $full;
}

/** Search description within 155 characters: cut at the last full sentence (or word) that fits. */
function mta_ridership_seo_desc($d)
{
    if (!mta_ridership_seo_live() || mb_strlen($d) <= 155) {
        return $d;
    }
    $cut = mb_substr($d, 0, 155);
    $dot = mb_strrpos($cut, '. ');
    return $dot !== false && $dot > 90 ? mb_substr($cut, 0, $dot + 1) : rtrim(mb_substr($cut, 0, mb_strrpos($cut, ' ')), ',;:') . '…';
}

/** Heading tag for a section (or a sub-heading inside one): h2/h3 under the page's h1 (h3/h4 if the SEO pass is off). */
function mta_ridership_h($sub = false)
{
    return mta_ridership_seo_live() ? ($sub ? 'h3' : 'h2') : ($sub ? 'h4' : 'h3');
}

// The scripts draw some sections too: tell them which heading tags to use.
add_action('wp_head', function () {
    if (mta_ridership_seo_live()) {
        echo "<script>window.MTA_H2 = true;</script>\n";
    }
    if (mta_ridership_look_v2()) {
        echo "<script>window.MTA_V2 = true;</script>\n";
    }
}, 1);

/**
 * Look v2 (2026-10-29): bold section headings, short lead sentences with the method in "How this is
 * measured", stat cards, sortable tables. Live; return mta_ridership_preview() here to put it back
 * behind ?mta_preview=1.
 */
function mta_ridership_look_v2()
{
    return true;
}
/**
 * Link-preview image for a page: $is_page says whether the current request is that page.
 * Yoast doesn't always print og:image for these pages, so print it ourselves (as congestion.php does).
 * Part of the SEO pass (mta_ridership_seo_live()).
 */
function mta_ridership_share_image(callable $is_page, $file, $w = 1200, $h = 630)
{
    $url = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/' . $file;
    add_filter('wpseo_twitter_image', function ($img) use ($is_page, $url) {
        return mta_ridership_seo_live() && $is_page() ? $url : $img;
    });
    add_action('wpseo_head', function () use ($is_page, $url, $w, $h) {
        if (mta_ridership_seo_live() && $is_page()) {
            echo '<meta property="og:image" content="' . esc_url($url) . "\" />\n<meta property=\"og:image:width\" content=\"$w\" />\n" .
                 "<meta property=\"og:image:height\" content=\"$h\" />\n<meta property=\"og:image:type\" content=\"image/jpeg\" />\n";
        }
    }, 30);
}
add_action('init', function () {
    mta_ridership_share_image(function () { return (bool) mta_lines_seo(); }, 'lines-share.jpg');
    mta_ridership_share_image(function () { return (bool) mta_buses_seo(); }, 'buses-share.jpg');
    mta_ridership_share_image(function () { return is_page(MTA_STATIONS_SLUG); }, 'stations-share.jpg');
    mta_ridership_share_image('mta_ridership_status_is_page', 'share.jpg', 800, 544);
});

add_filter('body_class', function ($c) {
    if (mta_ridership_seo_live()) {
        $c[] = 'mta-h2'; // the stylesheet styles the new heading levels like the old ones
    }
    if (mta_ridership_look_v2()) {
        $c[] = 'mta-v2';
    }
    return $c;
});

function mta_ridership_station_name($name)
{
    return preg_replace('/\s*\(.*$/', '', $name);
}

function mta_ridership_station_lines($name)
{
    return preg_match('/\(([^)]*)\)/', $name, $m) ? $m[1] : '';
}

function mta_ridership_hour_name($h)
{
    return ($h % 12 ?: 12) . ($h < 12 ? ' AM' : ' PM');
}

function mta_ridership_nice_date($ymd)
{
    return gmdate('M j, Y', strtotime("$ymd UTC"));
}

/** The three headline numbers: same math as the hero in mta-ridership.js. */
function mta_ridership_headline($data)
{
    $riders = $days = 0;
    foreach ($data['monthly'] as $m) {
        if (!$m['partial']) {
            $riders += $m['riders'];
            $days += $m['days'];
        }
    }
    $years = array_keys($data['top_years']);
    $top = $data['top_years'][end($years)]['stations'][0]['name'] ?? '';
    $hours = $data['hourly'][(string) max(array_keys($data['hourly']))];
    return [
        'per_day' => number_format($riders / max(1, $days) / 1e6, 1) . 'M',
        'station' => preg_replace(['/-42 St.*$/', '/\/.*$/'], '', mta_ridership_station_name($top)),
        'peak'    => mta_ridership_hour_name(array_search(max($hours), $hours)),
        'years'   => substr($data['first_day'], 0, 4) . '–' . substr($data['latest_day'], 0, 4),
    ];
}

function mta_ridership_ssr_hero($data)
{
    $h = mta_ridership_headline($data);
    $tile = function ($big, $small) {
        return '<div class="mta-tile"><strong>' . esc_html($big) . '</strong><span>' . esc_html($small) . '</span></div>';
    };
    $code = true // live; use mta_ridership_preview() to hide a change behind ?mta_preview=1
        ? '<p class="mta-hero-links">Updated daily from MTA open data · ' .
          '<a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">See the source code on GitHub &rarr;</a></p>'
        : '';
    return '<header class="mta-hero"><h1>NYC Subway Ridership</h1>' . $code . '<div class="mta-tiles">' .
        $tile($h['per_day'], 'riders a day') . $tile($h['station'], 'busiest station') . $tile($h['peak'], 'busiest hour') .
        '</div></header>';
}

function mta_ridership_ssr_top5($data)
{
    // Each name links to its station page.
    $slugs = [];
    if (mta_ridership_seo_live() && function_exists('mta_stations_all')) {
        foreach (mta_stations_all() as $id => $st) {
            $slugs[(string) $id] = $st['slug'];
        }
    }
    $list = function ($stations) use ($slugs) {
        $max = max(1, $stations[0]['riders'] ?? 1);
        $out = '<ol class="mta-top">';
        foreach ($stations as $s) {
            $name = esc_html(mta_ridership_station_name($s['name']));
            $slug = $slugs[(string) ($s['id'] ?? '')] ?? null;
            $out .= '<li><div class="mta-top-row"><span class="mta-top-name">' . ($slug ? '<a href="' . esc_url(mta_stations_url($slug)) . '">' . $name . '</a>' : $name) .
                ' <span class="mta-lines">' . esc_html(mta_ridership_station_lines($s['name'])) . '</span></span>' .
                '<span class="mta-top-num">' . number_format($s['riders']) . '</span></div>' .
                '<div class="mta-bar"><span style="width:' . round(100 * $s['riders'] / $max, 1) . '%"></span></div>' .
                '<div class="mta-borough">' . esc_html($s['borough']) . '</div></li>';
        }
        return $out . '</ol>';
    };
    $latest = mta_ridership_nice_date($data['latest_day']);
    return '<section class="mta-section"><' . mta_ridership_h() . '>Busiest stations</' . mta_ridership_h() . '>' .
        '<p class="mta-note">Latest data from MTA: <strong>' . esc_html($latest) . '</strong> (' . number_format($data['latest_total']) .
        ' subway riders that day). MTA publishes ridership about 1–2 weeks after the fact; this page refreshes daily.</p>' .
        '<div class="mta-grid"><div class="mta-card"><' . mta_ridership_h(true) . '>Top 5 on ' . esc_html($latest) . '</' . mta_ridership_h(true) . '>' . $list($data['top_latest']) . '</div>' .
        '<div class="mta-card"><' . mta_ridership_h(true) . '>Top 5, ' . esc_html(mta_ridership_nice_date($data['week_from'])) . ' – ' . esc_html($latest) . '</' . mta_ridership_h(true) . '>' .
        $list($data['top_week']) . '</div></div></section>';
}

/**
 * Plain-language answers to the questions people search for ("busiest subway station in NYC",
 * "NYC subway daily ridership"), used by the key findings, the FAQ and its schema.org data.
 */
function mta_ridership_answers($data)
{
    $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION) ?: [];
    $lk = $x['lookup'] ?? [];
    if (count($lk) < 3) {
        return null;
    }
    $h = mta_ridership_headline($data);
    $hours = $data['hourly'][(string) max(array_keys($data['hourly']))];
    $am = array_slice($hours, 0, 12);
    $st = function ($s) {
        return mta_ridership_station_name($s['name']) . ' (' . $s['borough'] . ')';
    };
    $range = mta_ridership_nice_date($x['from']) . ' – ' . mta_ridership_nice_date($x['to']);
    $a = [
        'busiest' => 'The busiest subway station in NYC is ' . $st($lk[0]) . ', with about ' . number_format($lk[0]['avg']) .
            ' riders a day (' . $range . '). Next are ' . $st($lk[1]) . ' at ' . number_format($lk[1]['avg']) .
            ' and ' . $st($lk[2]) . ' at ' . number_format($lk[2]['avg']) . ' riders a day.',
        'daily'   => 'About ' . str_replace('M', ' million', $h['per_day']) . ' subway rides a day on average. On ' .
            mta_ridership_nice_date($data['latest_day']) . ', the latest day of MTA data, there were ' .
            number_format($data['latest_total']) . ' rides. Counts are entries through the turnstiles and OMNY readers.',
        'time'    => 'The evening rush is the busiest: ' . $h['peak'] . ' has the most riders on an average day, with about ' .
            number_format(max($hours)) . ' entries. The morning peak is ' . mta_ridership_hour_name(array_search(max($am), $am)) .
            ' (' . number_format(max($am)) . ').',
        'least'   => 'The least used station is ' . $st(end($lk)) . ', with about ' . number_format(end($lk)['avg']) .
            ' riders a day over the same period.',
    ];
    if (!empty($x['yoy'])) {
        $y = $x['yoy'];
        $a['trend'] = 'Ridership in ' . $y['year'] . ' is ' . ($y['total_pct'] >= 0 ? 'up ' : 'down ') . abs($y['total_pct']) .
            '% on the same days of ' . $y['prev_year'] . ' (Jan 1 – ' . gmdate('M j', strtotime($y['year'] . '-' . $y['through'] . ' UTC')) . ').';
    }
    $mc = mta_ridership_metrocard_facts($x);
    if ($mc) {
        $a['metrocard'] = 'Very few: the MetroCard was used for ' . $mc['last_pct'] . '% of entries in ' . $mc['last_month'] .
            ' (about ' . number_format($mc['last_per_day']) . ' swipes a day), down from ' . $mc['first_pct'] . '% in ' .
            $mc['first_month'] . '. Almost everyone now taps with OMNY.';
        $a['still'] = 'MetroCard sales and refills ended on December 31, 2025. Existing cards kept working after that, and ' .
            'about ' . number_format($mc['last_per_day']) . ' entries a day were still paid by MetroCard in ' . $mc['last_month'] .
            '. The MTA hasn\'t set a final day as of this writing, so check mta.info for the latest.';
    }
    return $a;
}

/** The MetroCard's fade-out, from the OMNY vs MetroCard counts by month (complete months only). */
function mta_ridership_metrocard_facts($x)
{
    $full = array_values(array_filter($x['payment'] ?? [], function ($p) { return !$p['partial']; }));
    if (count($full) < 2) {
        return null;
    }
    $month = function ($p) { return gmdate('F Y', strtotime($p['month'] . '-01 UTC')); };
    $first = $full[0];
    $last = end($full);
    $crossed = function ($share) use ($full, $month) { // first month OMNY had at least this share
        foreach ($full as $p) {
            if ($p['omny_share'] >= $share) {
                return $month($p);
            }
        }
        return null;
    };
    return [
        'first_month' => $month($first), 'first_pct' => round((1 - $first['omny_share']) * 100),
        'first_per_day' => (int) round($first['metrocard'] / gmdate('t', strtotime($first['month'] . '-01 UTC'))),
        'last_month' => $month($last), 'last_pct' => round((1 - $last['omny_share']) * 100, 1),
        'last_per_day' => (int) round($last['metrocard'] / gmdate('t', strtotime($last['month'] . '-01 UTC'))),
        'omny_90' => $crossed(0.9), 'omny_99' => $crossed(0.99),
    ];
}

function mta_ridership_ssr_metrocard($data)
{
    $mc = mta_ridership_metrocard_facts(get_option(MTA_RIDERSHIP_EXTRAS_OPTION) ?: []);
    if (!$mc) {
        return '';
    }
    $drop = round((1 - $mc['last_per_day'] / max(1, $mc['first_per_day'])) * 100);
    if (mta_ridership_look_v2()) {
        $card = function ($cls, $big, $label, $text) {
            return '<div class="mta-stat ' . $cls . '"><strong>' . esc_html($big) . '</strong><span class="mta-stat-label">' . esc_html($label) . '</span><p>' . esc_html($text) . '</p></div>';
        };
        $steps = [];
        if ($mc['omny_90']) {
            $steps[] = [$mc['omny_90'], 'OMNY passed 90% of subway entries.'];
        }
        $steps[] = ['December 31, 2025', 'MetroCard sales and refills ended. Cards already in people\'s wallets kept working.'];
        if ($mc['omny_99']) {
            $steps[] = [$mc['omny_99'], 'OMNY passed 99% of entries.'];
        }
        $tl = '';
        foreach ($steps as [$when, $what]) {
            $tl .= '<li><time>' . esc_html($when) . '</time>' . esc_html($what) . '</li>';
        }
        return '<section class="mta-section mta-findings"><' . mta_ridership_h() . '>OMNY vs MetroCard: OMNY took over</' . mta_ridership_h() . '>' .
            '<p class="mta-lead">MetroCard swipes fell <strong>' . $drop . '%</strong> in under two years. Almost everyone now taps with OMNY.</p>' .
            '<div class="mta-ba">' . $card('old', $mc['first_pct'] . '%', 'paid with MetroCard, ' . $mc['first_month'], 'About ' . number_format($mc['first_per_day']) . ' swipes a day') .
            '<span class="mta-ba-arrow" aria-hidden="true">&rarr;</span>' .
            $card('', $mc['last_pct'] . '%', 'paid with MetroCard, ' . $mc['last_month'], 'About ' . number_format($mc['last_per_day']) . ' swipes a day') . '</div>' .
            '<ol class="mta-timeline">' . $tl . '</ol>' .
            '<p class="mta-caveat">The MTA hasn\'t announced a final day for existing MetroCards as of this writing. <a href="https://www.mta.info/" rel="noopener">mta.info</a> has the latest.</p></section>';
    }
    return '<section class="mta-section mta-findings"><' . mta_ridership_h() . '>' . (mta_ridership_seo_live() ? 'OMNY vs MetroCard: OMNY took over' : 'The end of the MetroCard: OMNY took over') . '</' . mta_ridership_h() . '><ul>' .
        '<li>In ' . esc_html($mc['first_month']) . ', ' . esc_html($mc['first_pct']) . '% of subway entries were paid with a MetroCard, about ' .
        number_format($mc['first_per_day']) . ' swipes a day. By ' . esc_html($mc['last_month']) . ' it was ' . esc_html($mc['last_pct']) .
        '%, about ' . number_format($mc['last_per_day']) . ' a day: ' . $drop . '% fewer.</li>' .
        ($mc['omny_90'] ? '<li>OMNY passed 90% of entries in ' . esc_html($mc['omny_90']) .
            ($mc['omny_99'] ? ' and 99% in ' . esc_html($mc['omny_99']) : '') . '.</li>' : '') .
        '<li>MetroCard sales and refills ended on December 31, 2025, but existing cards kept working; the MTA hasn\'t ' .
        'announced a final day as of this writing (<a href="https://www.mta.info/" rel="noopener">mta.info</a> has the latest).</li>' .
        '</ul><p class="mta-note">The chart below shows the MetroCard\'s share month by month.</p></section>';
}

/**
 * Links between the transit data pages (the ones that are published), leaving out the current one.
 * $current: 'ridership', 'congestion' or 'lines'.
 */
function mta_ridership_ssr_more($current)
{
    $pages = [
        'ridership'  => ['mta-ridership', 'NYC subway ridership', 'Busiest stations, find your station and an animated subway map, updated daily.'],
        'congestion' => ['congestion-pricing', 'NYC congestion pricing', 'Zone map, hours, cost and daily data on whether it\'s working.'],
        'lines'      => ['subway-lines', 'Every subway line ranked', 'Which line is the worst? On-time rates, delays and a report card for each line.'],
        'buses'      => ['bus-routes', 'NYC bus routes ranked', 'The busiest, slowest and least reliable buses, with a report card for each route.'],
    ];
    $li = '';
    foreach ($pages as $k => [$slug, $title, $text]) {
        $p = get_page_by_path($slug, OBJECT, 'page');
        if ($k !== $current && $p && $p->post_status === 'publish') {
            $li .= '<li><a href="' . esc_url(get_permalink($p)) . '">' . esc_html($title) . '</a>: ' . esc_html($text) . '</li>';
        }
        if ($k === 'ridership' && $current !== 'stations' && function_exists('mta_stations_url')) { // built by the plugin, no WordPress page
            $li .= '<li><a href="' . esc_url(mta_stations_url()) . '">Every subway station ranked</a>: riders a day, busiest hours, accessibility and elevator reliability for all ' .
                count(mta_stations_all()) . ' stations.</li>';
        }
    }
    return $li ? '<section class="mta-section mta-findings mta-more"><' . mta_ridership_h() . '>More NYC transit data</' . mta_ridership_h() . '><ul>' . $li . '</ul></section>' : '';
}

// Projects page: a description for search results (Yoast has none set).
add_filter('wpseo_metadesc', function ($desc) {
    if ($desc || !is_page('projects')) {
        return $desc;
    }
    return 'Projects by Mantie Reid II: a Java calculator, a C# chat app and rock paper scissors game, a C++ word game, an NYC MetroCard reader, ' .
        'a Unity shooter and 100 Days of Code challenges, with code on GitHub.';
});

/** Invitation to talk and share: the page is meant to start conversations about transit data. */
function mta_ridership_ssr_talk($unused = null, $share_text = null)
{
    $url = rawurlencode(get_permalink(get_queried_object_id()) ?: home_url('/mta-ridership/'));
    $text = rawurlencode($share_text ?: 'NYC subway ridership, updated daily: busiest stations, an animated map and the end of the MetroCard');
    $links = [
        'Reddit'   => "https://www.reddit.com/submit?url=$url&title=$text",
        'Bluesky'  => "https://bsky.app/intent/compose?text=$text%20$url",
        'X'        => "https://x.com/intent/post?url=$url&text=$text",
        'LinkedIn' => "https://www.linkedin.com/sharing/share-offsite/?url=$url",
        'Facebook' => "https://www.facebook.com/sharer/sharer.php?u=$url",
    ];
    $share = '';
    foreach ($links as $name => $href) {
        $share .= '<a class="mta-find-pick" href="' . esc_url($href) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>';
    }
    return '<section class="mta-section mta-card mta-talk"><' . mta_ridership_h() . '>Let\'s talk about transit data</' . mta_ridership_h() . '>' .
        '<p>Found something surprising about your station? Have a question the data could answer, or an idea for what to dig into next ' .
        '(congestion pricing, a train line, an event)? I\'d love to hear it, and I\'m always up for talking about transit data.</p>' .
        '<p><a href="' . esc_url(home_url('/#contact')) . '">Get in touch &rarr;</a> · ' .
        '<a href="' . MTA_RIDERSHIP_REPO . '/issues" rel="noopener">Suggest an idea on GitHub</a></p>' .
        '<p class="mta-talk-share">Share this page: ' . $share . '</p></section>';
}

function mta_ridership_faq_items($data)
{
    $a = mta_ridership_answers($data);
    if (!$a) {
        return [];
    }
    $q = [
        'busiest'   => 'What is the busiest subway station in NYC?',
        'daily'     => 'How many people ride the NYC subway each day?',
        'time'      => 'What time is the NYC subway busiest?',
        'least'     => 'What is the least used subway station in NYC?',
        'trend'     => 'Is NYC subway ridership going up?',
        'metrocard' => 'How many people still use a MetroCard?',
        'still'     => 'Can I still use a MetroCard on the NYC subway?',
    ];
    $items = [];
    foreach ($q as $k => $question) {
        if (isset($a[$k])) {
            $items[] = [$question, $a[$k]];
        }
    }
    $items[] = ['Where does this data come from?', 'From the MTA\'s "Subway Hourly Ridership" open dataset on data.ny.gov. ' .
        'This page refreshes daily; the MTA publishes ridership about 1–2 weeks after the fact.'];
    return $items;
}

function mta_ridership_ssr_findings($data)
{
    $a = mta_ridership_answers($data);
    if (!$a) {
        return '';
    }
    if (mta_ridership_look_v2()) { // stat cards; the full sentences stay in the FAQ below
        $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION) ?: [];
        $lk = $x['lookup'];
        $h = mta_ridership_headline($data);
        $hours = $data['hourly'][(string) max(array_keys($data['hourly']))];
        $am = array_slice($hours, 0, 12);
        $card = function ($cls, $big, $label, $text) {
            return '<li class="mta-stat ' . $cls . '"><strong>' . esc_html($big) . '</strong><span class="mta-stat-label">' . esc_html($label) . '</span><p>' . esc_html($text) . '</p></li>';
        };
        $name = function ($s) { return mta_ridership_station_name($s['name']); };
        $short = function ($s) use ($name) { return preg_replace('/\/.*$/', '', $name($s)); }; // "Times Sq-42 St"
        $o = $card('', $h['per_day'], 'subway rides a day', 'On ' . mta_ridership_nice_date($data['latest_day']) . ', the latest day of data: ' . number_format($data['latest_total']) . ' rides.');
        $o .= $card('', number_format($lk[0]['avg']), 'riders a day at ' . $short($lk[0]), 'The busiest station. Next: ' . $name($lk[1]) . ' (' . number_format($lk[1]['avg']) . ') and ' . $name($lk[2]) . ' (' . number_format($lk[2]['avg']) . ').');
        $o .= $card('', $h['peak'], 'busiest hour', 'About ' . number_format(max($hours)) . ' entries on an average day. Morning peak: ' . mta_ridership_hour_name(array_search(max($am), $am)) . ' (' . number_format(max($am)) . ').');
        if (!empty($x['yoy'])) {
            $y = $x['yoy'];
            $o .= $card($y['total_pct'] >= 0 ? 'pos' : 'neg', ($y['total_pct'] > 0 ? '+' : '') . $y['total_pct'] . '%', 'riders vs ' . $y['prev_year'], 'Same days of each year, Jan 1 – ' . gmdate('M j', strtotime($y['year'] . '-' . $y['through'] . ' UTC')) . '.');
        }
        if ($mc = mta_ridership_metrocard_facts($x)) {
            $o .= $card('', $mc['last_pct'] . '%', 'of entries by MetroCard', $mc['last_month'] . ', down from ' . $mc['first_pct'] . '% in ' . $mc['first_month'] . '.');
        }
        return '<section class="mta-section mta-findings"><' . mta_ridership_h() . '>NYC subway ridership: key numbers</' . mta_ridership_h() . '><ul class="mta-stats">' . $o . '</ul>' .
            '<p class="mta-caveat">Rides are entries through the turnstiles and OMNY readers. Station figures are averages for ' .
            esc_html(mta_ridership_nice_date($x['from']) . ' – ' . mta_ridership_nice_date($x['to'])) . '.</p></section>';
    }
    $li = '';
    foreach (['daily', 'busiest', 'time', 'trend', 'metrocard'] as $k) {
        $li .= isset($a[$k]) ? '<li>' . esc_html($k === 'metrocard' ? preg_replace('/^Very few: the/', 'The', $a[$k]) : $a[$k]) . '</li>' : '';
    }
    return '<section class="mta-section mta-findings"><' . mta_ridership_h() . '>NYC subway ridership: key numbers</' . mta_ridership_h() . '><ul>' . $li . '</ul></section>';
}

function mta_ridership_ssr_faq($data)
{
    $out = '';
    foreach (mta_ridership_faq_items($data) as [$q, $a]) {
        $out .= '<details><summary>' . esc_html($q) . '</summary><p>' . esc_html($a) . '</p></details>';
    }
    return $out ? '<section class="mta-section mta-faq"><' . mta_ridership_h() . '>Questions about NYC subway ridership</' . mta_ridership_h() . '>' . $out . '</section>' : '';
}

/** The page currently being viewed uses the shortcode. */
function mta_ridership_is_page()
{
    if (!is_singular()) {
        return false;
    }
    $post = get_queried_object();
    return $post && has_shortcode($post->post_content ?? '', 'mta_ridership');
}

function mta_ridership_seo_texts()
{
    $data = get_option(MTA_RIDERSHIP_OPTION);
    if (!$data) {
        return null;
    }
    $h = mta_ridership_headline($data);
    // Wording that matches what people search for ("NYC subway daily ridership",
    // "busiest subway station in NYC", "NYC subway map"). Live; the old text stays for a quick switch back.
    $new = true;
    if (mta_ridership_seo_live()) { // shorter title and description
        $year = substr($h['years'], -4);
        return [
            'title'       => "NYC Subway Ridership $year: Daily Riders & Busiest Stations",
            'description' => "NYC subway ridership: about {$h['per_day']} rides a day. Look up any station's riders and busiest hour, " .
                             'the busiest stations and a live map. From MTA open data.',
            'image'       => plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/share.jpg',
            'data'        => $data,
        ];
    }
    return [
        'title'       => $new ? "NYC Subway Ridership {$h['years']}: Daily Riders, Busiest Stations & " . (true ? 'Interactive Map' : 'Live Map')
                              : "NYC Subway Ridership {$h['years']}: Live Map & Busiest Stations",
        'description' => $new ? "NYC subway daily ridership: about {$h['per_day']} rides a day. Find any station's riders and busiest hour, " .
                                "see the busiest subway stations in NYC and an animated subway map. Updated daily from MTA open data."
                              : "Animated map and charts of NYC subway ridership {$h['years']}: busiest stations, rush hours and unusual days. " .
                                "About {$h['per_day']} rides a day, updated daily from MTA open data.",
        'image'       => plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/share.jpg',
        'data'        => $data,
    ];
}

add_filter('wpseo_title', function ($title) {
    if (!mta_ridership_seo_enabled() || !mta_ridership_is_page() || !($t = mta_ridership_seo_texts())) {
        return $title;
    }
    return mta_ridership_seo_title($t['title']);
});
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($desc) {
        if (!mta_ridership_seo_enabled() || !mta_ridership_is_page() || !($t = mta_ridership_seo_texts())) {
            return $desc;
        }
        return mta_ridership_seo_desc($t['description']);
    });
}
add_filter('wpseo_opengraph_title', function ($title) {
    if (!mta_ridership_seo_enabled() || !mta_ridership_is_page() || !($t = mta_ridership_seo_texts())) {
        return $title;
    }
    return $t['title'];
});
add_action('wpseo_add_opengraph_images', function ($images) {
    if (mta_ridership_seo_enabled() && mta_ridership_is_page() && ($t = mta_ridership_seo_texts())) {
        $images->add_image(['url' => $t['image'], 'width' => 800, 'height' => 544, 'type' => 'image/jpeg']);
    }
});
add_filter('wpseo_twitter_image', function ($img) {
    if (!mta_ridership_seo_enabled() || !mta_ridership_is_page() || !($t = mta_ridership_seo_texts())) {
        return $img;
    }
    return $t['image'];
});

// schema.org Dataset (Google Dataset Search).
add_action('wp_head', function () {
    if (!mta_ridership_seo_enabled() || !mta_ridership_is_page() || !($t = mta_ridership_seo_texts())) {
        return;
    }
    $d = $t['data'];
    $url = get_permalink(get_queried_object_id());
    $ld = [
        '@context' => 'https://schema.org', '@type' => 'Dataset',
        'name' => $t['title'], 'description' => $t['description'] . ' Includes riders per station per month, ' .
            'hour of day, day of week and season, year-over-year change, fare types and OMNY vs MetroCard.',
        'url' => $url, 'sameAs' => 'https://github.com/MantieReid/mta-data-analysis',
        'creator' => ['@type' => 'Person', 'name' => 'Mantie Reid II', 'url' => home_url('/')],
        'license' => 'https://creativecommons.org/licenses/by/4.0/',
        'isAccessibleForFree' => true,
        'isBasedOn' => 'https://data.ny.gov/d/5wq4-mkjj',
        'keywords' => ['NYC subway', 'MTA', 'ridership', 'New York City', 'public transit', 'open data'],
        'temporalCoverage' => $d['first_day'] . '/' . $d['latest_day'],
        'spatialCoverage' => ['@type' => 'Place', 'name' => 'New York City, NY, USA'],
        'dateModified' => $d['updated'],
        'distribution' => [
            ['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => rest_url('mta-ridership/v1/data')],
            ['@type' => 'DataDownload', 'encodingFormat' => 'text/csv',
             'contentUrl' => 'https://github.com/MantieReid/mta-data-analysis/tree/master/output'],
        ],
    ];
    echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_SLASHES) . "</script>\n";
    $faq = mta_ridership_faq_items($d);
    if ($faq) {
        echo '<script type="application/ld+json">' . wp_json_encode([
            '@context' => 'https://schema.org', '@type' => 'FAQPage',
            'mainEntity' => array_map(function ($i) {
                return ['@type' => 'Question', 'name' => $i[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i[1]]];
            }, $faq),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
    }
});

/* ---------- Sub-pages (one line, bus route or station): their own page data for search engines. ---------- */

/** The line, bus route or station sub-page being viewed: its URL, name and parent page; null elsewhere. */
function mta_ridership_subpage()
{
    if (!is_singular()) {
        return null;
    }
    if (($s = mta_lines_seo()) && $s['l']) {
        return ['url' => $s['url'], 'name' => $s['l']['name'], 'parent' => 'Subway lines ranked', 'parent_url' => mta_lines_url()];
    }
    if (($s = mta_buses_seo()) && $s['r']) {
        return ['url' => $s['url'], 'name' => $s['r']['label'] . ' bus', 'parent' => 'Bus routes ranked', 'parent_url' => mta_buses_url()];
    }
    if (is_page(MTA_STATIONS_SLUG) && ($st = mta_stations_current())) {
        return ['url' => mta_stations_url($st['slug']), 'name' => mta_stations_display($st['name']) . ' station',
                'parent' => 'Subway stations ranked', 'parent_url' => mta_stations_url(), 'station' => $st];
    }
    return null;
}

// Breadcrumb: Home > Subway lines ranked > A train (Yoast stops at the parent page), with plain "&" in names.
add_filter('wpseo_breadcrumb_links', function ($links) {
    if (!mta_ridership_seo_live()) {
        return $links;
    }
    foreach ($links as &$l) {
        if (isset($l['text'])) {
            $l['text'] = html_entity_decode($l['text'], ENT_QUOTES, 'UTF-8');
        }
    }
    unset($l);
    if ($sub = mta_ridership_subpage()) {
        $last = count($links) - 1;
        $links[$last] = ['url' => $sub['parent_url'], 'text' => $sub['parent']];
        $links[] = ['url' => $sub['url'], 'text' => $sub['name']];
    }
    return $links;
});

// Yoast describes a sub-page as its parent page: give it its own address and name.
add_filter('wpseo_schema_webpage', function ($data) {
    if (mta_ridership_seo_live() && ($sub = mta_ridership_subpage())) {
        $data['url'] = $sub['url'];
        $data['name'] = wp_get_document_title();
    }
    return $data;
});

// Ranking pages: a Dataset entry (like the ridership page); station pages: the station as a place.
add_action('wp_head', function () {
    if (!mta_ridership_seo_live() || !is_singular()) {
        return;
    }
    $ld = null;
    $base = ['@context' => 'https://schema.org', '@type' => 'Dataset', 'creator' => ['@type' => 'Person', 'name' => 'Mantie Reid II', 'url' => home_url('/')],
             'license' => 'https://creativecommons.org/licenses/by/4.0/', 'isAccessibleForFree' => true,
             'sameAs' => 'https://github.com/MantieReid/mta-data-analysis', 'spatialCoverage' => ['@type' => 'Place', 'name' => 'New York City, NY, USA']];
    if (($s = mta_lines_seo()) && !$s['l']) {
        $ld = $base + ['name' => 'NYC subway line report cards', 'description' => $s['description'], 'url' => $s['url'],
                       'isBasedOn' => ['https://data.ny.gov/d/f6rf-2a3t', 'https://data.ny.gov/d/r7qk-6tcy', 'https://data.ny.gov/d/9zbp-wz3y'],
                       'keywords' => ['NYC subway', 'MTA', 'on-time performance', 'subway delays'], 'temporalCoverage' => $s['d']['from'] . '/' . $s['d']['latest']];
    } elseif (($s = mta_buses_seo()) && !$s['r']) {
        $ld = $base + ['name' => 'NYC bus route report cards', 'description' => $s['description'], 'url' => $s['url'],
                       'isBasedOn' => 'https://data.ny.gov/d/gxb3-akrn', 'keywords' => ['NYC bus', 'MTA', 'bus ridership', 'bus speed'],
                       'temporalCoverage' => $s['d']['from'] . '/' . $s['d']['latest']];
    } elseif (is_page(MTA_STATIONS_SLUG) && mta_stations_all()) {
        $st = mta_stations_current();
        $x = get_option(MTA_RIDERSHIP_EXTRAS_OPTION);
        if ($st) {
            $ld = ['@context' => 'https://schema.org', '@type' => 'TrainStation', 'name' => mta_stations_display($st['name']) . ' subway station',
                   'url' => mta_stations_url($st['slug']), 'publicAccess' => true,
                   'address' => ['@type' => 'PostalAddress', 'addressLocality' => $st['borough'], 'addressRegion' => 'NY', 'addressCountry' => 'US']];
            if ($st['lat'] !== null) {
                $ld['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $st['lat'], 'longitude' => $st['lon']];
            }
        } else {
            $ld = $base + ['name' => 'NYC subway station ridership ranking', 'url' => mta_stations_url(),
                           'description' => 'Average riders a day at all ' . count(mta_stations_all()) . ' NYC subway stations, with change vs last year.',
                           'isBasedOn' => 'https://data.ny.gov/d/5wq4-mkjj', 'keywords' => ['NYC subway', 'MTA', 'busiest stations', 'ridership'],
                           'temporalCoverage' => $x['from'] . '/' . $x['to']];
        }
    }
    if ($ld) {
        echo '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "</script>\n";
    }
});

/* ---------- Home and Projects: titles and descriptions sized for search results. ---------- */

add_filter('wpseo_title', function ($t) {
    if (!mta_ridership_seo_live()) {
        return $t;
    }
    if (is_front_page()) {
        return 'Mantie Reid II | IT Support Engineer in New York City';
    }
    return is_page('projects') ? 'Projects by Mantie Reid II: Java, C#, C++ & Unity' : $t;
}, 20);
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($d) {
        if (!mta_ridership_seo_live()) {
            return $d;
        }
        if (is_front_page()) {
            return 'Mantie Reid II, IT support engineer in New York City: systems administration, ITSM, automation and API integrations, plus NYC transit data projects.';
        }
        return is_page('projects') ? 'Projects by Mantie Reid II: a Java calculator, C# chat app, C++ word game, NYC MetroCard reader and Unity shooter, with code on GitHub.' : $d;
    }, 20);
}

/* ---------- Yoast in the editor: analyse the page's real text, not the bare [mta_...] shortcode. ---------- */

// Editor screen only: fetch the published page and add its plugin-built text to what Yoast analyses,
// so the keyphrase, length and link checks see what visitors see.
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'post.php' || !($post = get_post()) || $post->post_type !== 'page') {
        return;
    }
    $codes = ['mta_ridership', 'mta_congestion', 'mta_lines', 'mta_buses', 'mta_stations', 'mta_status'];
    if (!array_filter($codes, function ($c) use ($post) { return has_shortcode($post->post_content, $c); })) {
        return;
    }
    wp_register_script('mta-yoast', '', ['jquery'], '1', true);
    wp_enqueue_script('mta-yoast');
    wp_add_inline_script('mta-yoast', '(function ($) {
  var url = ' . wp_json_encode(get_permalink($post)) . ', html = "";
  function hook() {
    var app = window.YoastSEO && window.YoastSEO.app;
    if (!app || !app.registerPlugin) return false;
    app.registerPlugin("mtaRidership", { status: "loading" });
    app.registerModification("content", function (c) { return c + html; }, "mtaRidership", 5);
    fetch(url, { credentials: "omit" }).then(function (r) { return r.text(); }).then(function (t) {
      var m = new DOMParser().parseFromString(t, "text/html").querySelector(".mta-ridership");
      html = m ? m.innerHTML : "";
    }).finally(function () { app.pluginReady("mtaRidership"); app.pluginReloaded("mtaRidership"); });
    return true;
  }
  if (!hook()) $(window).on("YoastSEO:ready", hook);
})(jQuery);');
});
