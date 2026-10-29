<?php
/**
 * Public data status page, [mta_status]: for each dataset the site shows, the newest MTA data date, when we
 * last refreshed it, and whether it's current, delayed or unavailable, with a plain note on whether a delay
 * comes from the MTA (it hasn't published yet) or from our own refresh. Built from mta_ridership_health()
 * but only ever exposes dataset names, dates and the state: no error messages, paths, timings or internal
 * steps. The detailed view (errors, durations) stays in Settings > MTA Ridership for administrators.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_STATUS_SLUG = 'data-status';

/** How often the MTA publishes each public dataset (shown on the page). */
function mta_ridership_status_cadence()
{
    return [
        'refresh'    => 'Weekly, usually 1–2 weeks behind',
        'congestion' => 'Weekly, usually 1–2 weeks behind',
        'lines'      => 'Monthly, about 3–4 weeks after the month ends',
        'buses'      => 'Monthly, about 3–4 weeks after the month ends',
    ];
}

/** Option that holds each public dataset's own "updated" stamp (fallback for "last refreshed"). */
function mta_ridership_status_options()
{
    return ['refresh' => 'mta_ridership_data', 'congestion' => 'mta_ridership_congestion',
            'lines' => 'mta_ridership_lines', 'buses' => 'mta_ridership_buses'];
}

/**
 * Safe rows for the public page and endpoint. Only fields listed here ever leave the server.
 * 'note' says in plain words what the state means.
 */
function mta_ridership_public_status($now = null)
{
    $names = mta_ridership_status_options();
    $cad = mta_ridership_status_cadence();
    $rows = [];
    foreach (mta_ridership_health($now) as $k => $h) {
        if (!$h['public'] || !isset($names[$k])) {
            continue;
        }
        $opt = get_option($names[$k]);
        $refreshed = $h['last_success'] ?: ($opt['updated'] ?? null);
        if ($h['state'] === 'current') {
            $note = 'Up to date: the newest data the MTA has published.';
        } elseif ($h['state'] === 'unavailable') {
            $note = 'No data yet.';
        } elseif ($h['reason'] === 'refresh') {
            $note = 'Our refresh has hit a problem and is retrying automatically. The MTA may have published newer data than we show.';
        } else {
            $note = 'Waiting on the MTA: it hasn\'t published newer data than this yet. Our refreshes are working.';
        }
        $rows[] = ['id' => $k, 'name' => $h['label'], 'state' => $h['state'],
                   'reason' => $h['state'] === 'delayed' ? ($h['reason'] === 'refresh' ? 'our-refresh' : 'mta') : null,
                   'data_through' => $h['through'], 'last_refreshed' => $refreshed ? gmdate('c', strtotime($refreshed)) : null,
                   'publishes' => $cad[$k] ?? '', 'note' => $note];
    }
    return $rows;
}

/** Link to the status page for the footers of the data pages, or '' until the page is published. */
function mta_ridership_status_link()
{
    $p = get_page_by_path(MTA_STATUS_SLUG, OBJECT, 'page');
    return $p && $p->post_status === 'publish' ? ' · <a href="' . esc_url(get_permalink($p)) . '">Data status</a>' : '';
}

function mta_ridership_status_html($now = null)
{
    $rows = mta_ridership_public_status($now);
    $label = ['current' => 'Current', 'delayed' => 'Delayed', 'unavailable' => 'Unavailable'];
    $delayed = count(array_filter($rows, function ($r) { return $r['state'] !== 'current'; }));
    $summary = $delayed === 0 ? 'All datasets are current.'
        : $delayed . ' of ' . count($rows) . ' datasets ' . ($delayed === 1 ? 'is' : 'are') . ' behind their usual schedule.';
    $tr = '';
    foreach ($rows as $r) {
        $when = $r['last_refreshed'] ? gmdate('M j, Y H:i', strtotime($r['last_refreshed'])) . ' UTC' : 'Not recorded yet';
        $tr .= '<tr><td>' . esc_html($r['name']) . '</td>' .
            '<td class="mta-state-' . esc_attr($r['state']) . '"><strong>' . esc_html($label[$r['state']]) . '</strong>' .
            ($r['reason'] === 'mta' ? ' (MTA)' : ($r['reason'] === 'our-refresh' ? ' (our refresh)' : '')) . '<br><span class="mta-note">' . esc_html($r['note']) . '</span></td>' .
            '<td>' . ($r['data_through'] ? esc_html(mta_ridership_nice_date($r['data_through'])) : '–') . '</td>' .
            '<td>' . esc_html($when) . '</td><td>' . esc_html($r['publishes']) . '</td></tr>';
    }
    return '<div class="mta-ridership mta-status"><header class="mta-hero"><h1>NYC Transit Data Status</h1>' .
        '<p class="mta-hero-links">Is the data on this site current? ' . esc_html($summary) . ' Checked ' . esc_html(gmdate('M j, Y H:i', $now ?? time())) . ' UTC.</p></header>' .
        '<section class="mta-section"><h3>Datasets</h3><div class="mta-table-wrap"><table class="mta-table"><thead><tr>' .
        '<th>Dataset</th><th>Status</th><th>Latest MTA data</th><th>Last refreshed</th><th>How often the MTA publishes</th></tr></thead><tbody>' . $tr . '</tbody></table></div></section>' .
        '<section class="mta-section mta-faq"><h3>How to read this</h3>' .
        '<details open><summary>Why is data from a week or two ago normal?</summary><p>The MTA publishes ridership and traffic data with a delay: usually 1–2 weeks for the weekly datasets and 3–4 weeks after month end for the monthly ones. "Latest MTA data" is the newest day or month the MTA has released, not today.</p></details>' .
        '<details open><summary>What does "Delayed" mean?</summary><p>The newest data is older than that dataset\'s usual schedule. "(MTA)" means the MTA hasn\'t published newer data yet and our refreshes are working. "(our refresh)" means a refresh on this site hit a problem and is retrying automatically.</p></details>' .
        '<details open><summary>How often does this site refresh?</summary><p>Every day, around 6 AM New York time, plus automatic retries if a refresh fails. Pages show new data as soon as the MTA publishes it and the next refresh runs.</p></details></section>' .
        '<p class="mta-source mta-source-ssr">Data from <a href="https://data.ny.gov/" rel="noopener">data.ny.gov</a>. Source code: <a href="' . MTA_RIDERSHIP_REPO . '" rel="noopener">github.com/MantieReid/mta-data-analysis</a>. ' .
        'Machine-readable: <a href="' . esc_url(rest_url('mta-ridership/v1/status')) . '" rel="noopener">status JSON</a>.</p></div>';
}

add_shortcode('mta_status', function () {
    $base = plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php');
    wp_enqueue_style('mta-ridership', $base . 'assets/mta-ridership.css', [], '1.14.0');
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true); // always live: don't let a page cache serve an old status
    }
    return mta_ridership_status_html();
});

add_action('rest_api_init', function () {
    register_rest_route('mta-ridership/v1', '/status', [
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => function () {
            $res = new WP_REST_Response(['checked' => gmdate('c'), 'datasets' => mta_ridership_public_status()]);
            $res->header('Cache-Control', 'public, max-age=300');
            return $res;
        },
    ]);
});

function mta_ridership_status_is_page()
{
    if (!is_singular()) {
        return false;
    }
    $post = get_queried_object();
    return $post && has_shortcode($post->post_content ?? '', 'mta_status');
}

add_filter('wpseo_title', function ($t) {
    return mta_ridership_status_is_page() ? 'NYC Transit Data Status: Is the MTA Data Current? - ' . get_bloginfo('name') : $t;
});
add_filter('wpseo_metadesc', function ($t) {
    return mta_ridership_status_is_page() ? 'Is the NYC subway, bus and congestion pricing data on this site current? Latest MTA data date and last refresh for each dataset, and whether a delay is the MTA\'s or ours.' : $t;
});
