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

/** Source line at the bottom of the page: data source plus a link to the code. */
function mta_ridership_ssr_source($data)
{
    return '<p class="mta-source mta-source-ssr">Data: <a href="https://data.ny.gov/d/5wq4-mkjj" rel="noopener">MTA Subway Hourly Ridership</a>' .
        ' (data.ny.gov), ' . esc_html(mta_ridership_nice_date($data['first_day'])) . ' – ' . esc_html(mta_ridership_nice_date($data['latest_day'])) .
        '. Updated ' . esc_html(gmdate('M j, Y', strtotime($data['updated']))) . '.<br>' .
        'Source code and method: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a>' .
        ' · Analysis by Mantie Reid II, free to reuse with credit (CC BY 4.0).</p>';
}

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
    $list = function ($stations) {
        $max = max(1, $stations[0]['riders'] ?? 1);
        $out = '<ol class="mta-top">';
        foreach ($stations as $s) {
            $out .= '<li><div class="mta-top-row"><span class="mta-top-name">' . esc_html(mta_ridership_station_name($s['name'])) .
                ' <span class="mta-lines">' . esc_html(mta_ridership_station_lines($s['name'])) . '</span></span>' .
                '<span class="mta-top-num">' . number_format($s['riders']) . '</span></div>' .
                '<div class="mta-bar"><span style="width:' . round(100 * $s['riders'] / $max, 1) . '%"></span></div>' .
                '<div class="mta-borough">' . esc_html($s['borough']) . '</div></li>';
        }
        return $out . '</ol>';
    };
    $latest = mta_ridership_nice_date($data['latest_day']);
    return '<section class="mta-section"><h3>Busiest stations</h3>' .
        '<p class="mta-note">Latest data from MTA: <strong>' . esc_html($latest) . '</strong> (' . number_format($data['latest_total']) .
        ' subway riders that day). MTA publishes ridership about 1–2 weeks after the fact; this page refreshes daily.</p>' .
        '<div class="mta-grid"><div class="mta-card"><h4>Top 5 on ' . esc_html($latest) . '</h4>' . $list($data['top_latest']) . '</div>' .
        '<div class="mta-card"><h4>Top 5, ' . esc_html(mta_ridership_nice_date($data['week_from'])) . ' – ' . esc_html($latest) . '</h4>' .
        $list($data['top_week']) . '</div></div></section>';
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
    return [
        'title'       => "NYC Subway Ridership {$h['years']}: Live Map & Busiest Stations",
        'description' => "Animated map and charts of NYC subway ridership {$h['years']}: busiest stations, rush hours and unusual days. " .
                         "About {$h['per_day']} rides a day, updated daily from MTA open data.",
        'image'       => plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/share.jpg',
        'data'        => $data,
    ];
}

add_filter('wpseo_title', function ($title) {
    if (!mta_ridership_seo_enabled() || !mta_ridership_is_page() || !($t = mta_ridership_seo_texts())) {
        return $title;
    }
    return $t['title'] . ' - ' . get_bloginfo('name');
});
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc', 'wpseo_twitter_description'] as $f) {
    add_filter($f, function ($desc) {
        if (!mta_ridership_seo_enabled() || !mta_ridership_is_page() || !($t = mta_ridership_seo_texts())) {
            return $desc;
        }
        return $t['description'];
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
});
