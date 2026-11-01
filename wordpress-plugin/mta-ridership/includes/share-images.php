<?php
/**
 * Link-preview images (1200x630) that follow the data: after each data step, every card is worked out from the
 * stored data and redrawn only when what it shows has changed. Each new image gets a new file name (a hash of
 * its contents), so Facebook, LinkedIn and others fetch the new one instead of a copy they saved earlier.
 *
 * Drawn with GD in the same layout as tools/make_share_images.py (and the same DejaVu Sans font files, bundled in
 * assets/fonts). The images in assets/ are the fallback until the first card is drawn.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_SHARE_OPTION = 'mta_ridership_share_images';
const MTA_SHARE_DIR    = 'mta-ridership/share'; // inside wp-content/uploads
const MTA_SHARE_KEEP   = 2;                     // image versions kept per card, for pages shared just before a change

/** Card name => [fallback image in assets/, function returning the card or null when its data isn't there]. */
function mta_share_cards()
{
    return [
        'ridership'  => ['ridership-share.jpg', 'mta_share_card_ridership'],
        'lines'      => ['lines-share.jpg', 'mta_share_card_lines'],
        'buses'      => ['buses-share.jpg', 'mta_share_card_buses'],
        'stations'   => ['stations-share.jpg', 'mta_share_card_stations'],
        'congestion' => ['congestion-share.jpg', 'mta_share_card_congestion'],
    ];
}

/** The current image for a card: the latest drawn one, or the fallback in assets/. */
function mta_share_url($card)
{
    $files = get_option(MTA_SHARE_OPTION, []);
    $file = $files[$card]['files'][0] ?? null;
    if ($file && file_exists(mta_share_dir() . '/' . $file)) {
        return wp_upload_dir()['baseurl'] . '/' . MTA_SHARE_DIR . '/' . $file;
    }
    return plugin_dir_url(dirname(__DIR__) . '/mta-ridership.php') . 'assets/' . mta_share_cards()[$card][0];
}

function mta_share_dir()
{
    return wp_upload_dir()['basedir'] . '/' . MTA_SHARE_DIR;
}

/* ---------- what each card shows ---------- */

function mta_share_card_ridership()
{
    $d = get_option(MTA_RIDERSHIP_OPTION);
    if (empty($d['hourly']) || empty($d['monthly'])) {
        return null;
    }
    $h = mta_ridership_headline($d);
    $hourly = $d['hourly'][(string) max(array_keys($d['hourly']))];
    arsort($hourly);
    $bars = [];
    foreach (array_slice($hourly, 0, 6, true) as $hour => $v) {
        $bars[] = [mta_ridership_hour_name($hour), (int) $v];
    }
    return [
        'title' => 'NYC Subway Ridership', 'subtitle' => 'Daily riders, busiest stations & rush hours',
        'stats' => [[$h['per_day'], 'subway rides a day'], [$h['peak'], 'busiest hour']],
        'bars_title' => 'Busiest hours (average riders)', 'bars' => $bars, 'fmt' => 'number_format',
        'footer' => 'mantiereidii.com/mta-ridership  ·  MTA open data, updated daily',
    ];
}

function mta_share_card_lines()
{
    $d = get_option(MTA_LINES_OPTION);
    if (empty($d['lines']) || !isset($d['system']['otp'])) {
        return null;
    }
    $lines = array_values(array_filter($d['lines'], function ($l) { return isset($l['otp']); }));
    usort($lines, function ($a, $b) { return $a['otp'] <=> $b['otp']; });
    $bars = [];
    foreach (array_slice($lines, 0, 6) as $l) {
        $bars[] = [$l['name'], $l['otp']];
    }
    return [
        'title' => 'NYC Subway Lines Ranked', 'subtitle' => 'Which line is the worst? Delays & on-time rate',
        'stats' => [[$d['system']['otp'] . '%', 'subway trips on time'], [(string) count($d['lines']), 'lines with report cards']],
        'bars_title' => 'Least on-time lines', 'bars' => $bars, 'fmt' => function ($v) { return $v . '%'; }, 'top' => 100,
        'footer' => 'mantiereidii.com/subway-lines  ·  MTA open data, updated monthly',
    ];
}

function mta_share_card_buses()
{
    $d = get_option(MTA_BUSES_OPTION);
    if (empty($d['routes']) || empty($d['system']['riders'])) {
        return null;
    }
    $routes = $d['routes'];
    uasort($routes, function ($a, $b) { return ($b['riders'] ?? 0) <=> ($a['riders'] ?? 0); });
    $bars = [];
    foreach (array_slice($routes, 0, 6, true) as $id => $r) {
        $bars[] = [mta_buses_label($id), (int) $r['riders']];
    }
    return [
        'title' => 'NYC Bus Routes Ranked', 'subtitle' => 'Busiest, slowest & least reliable buses',
        'stats' => [[number_format($d['system']['riders'] / 1e6, 1) . 'M', 'bus riders a day'], [$d['system']['mph'] . ' mph', 'local bus average']],
        'bars_title' => 'Busiest routes (riders a day)', 'bars' => $bars, 'fmt' => 'number_format',
        'footer' => 'mantiereidii.com/bus-routes  ·  ' . count($routes) . ' routes, MTA open data',
    ];
}

function mta_share_card_stations()
{
    $all = array_values(array_filter(mta_stations_all(), function ($s) { return isset($s['avg']); }));
    if (!$all) {
        return null;
    }
    usort($all, function ($a, $b) { return $b['avg'] <=> $a['avg']; });
    $short = function ($name) { return explode('/', mta_stations_display($name))[0]; };
    $bars = [];
    foreach (array_slice($all, 0, 6) as $s) {
        $bars[] = [$short($s['name']), (int) $s['avg']];
    }
    $n = count(mta_stations_all());
    return [
        'title' => 'Busiest NYC Subway Stations', 'subtitle' => "All $n stations ranked, with busiest hours",
        'stats' => [[number_format($all[0]['avg']), 'riders a day at the busiest'], [(string) $n, 'stations']],
        'bars_title' => 'Busiest stations (riders a day)', 'bars' => $bars, 'fmt' => 'number_format',
        'footer' => 'mantiereidii.com/stations  ·  MTA open data, updated daily',
    ];
}

function mta_share_card_congestion()
{
    $c = get_option(MTA_CONGESTION_OPTION);
    if (empty($c['cur_per_day'])) {
        return null;
    }
    $stats = [['$' . (0 + mta_congestion_rates()['car'][1]), 'peak toll for cars']];
    if (!empty($c['prev_per_day'])) {
        $pct = round(($c['cur_per_day'] / $c['prev_per_day'] - 1) * 100, 1);
        $stats[] = [($pct > 0 ? '+' : '') . number_format($pct, 1) . '%', 'vehicles vs ' . ($c['year'] - 1)];
    }
    return [
        'title' => 'NYC Congestion Pricing', 'subtitle' => 'Map, hours, cost & is it working?', 'stats' => $stats,
        'line' => number_format($c['cur_per_day']) . ' vehicles a day enter the zone',
        'background' => 'congestion-share-bg.png', // the zone map on the right
        'footer' => 'mantiereidii.com/congestion-pricing  ·  MTA open data, updated daily',
    ];
}

/* ---------- drawing ---------- */

/** Draw a card and return the JPEG bytes. Positions and sizes match tools/make_share_images.py (Pillow). */
function mta_share_draw(array $card)
{
    $assets = dirname(__DIR__) . '/assets/';
    $reg = $assets . 'fonts/DejaVuSans.ttf';
    $bold = $assets . 'fonts/DejaVuSans-Bold.ttf';
    $im = !empty($card['background']) ? imagecreatefrompng($assets . $card['background']) : imagecreatetruecolor(1200, 630);
    imagealphablending($im, true);
    $col = function ($r, $g, $b) use ($im) { return imagecolorallocate($im, $r, $g, $b); };
    $bg = $col(11, 18, 32);
    $text = $col(230, 237, 246);
    $muted = $col(163, 173, 189);
    $accent = $col(138, 180, 248);
    $bar_bg = $col(40, 50, 68);
    if (empty($card['background'])) {
        imagefilledrectangle($im, 0, 0, 1199, 629, $bg);
    }
    // Pillow draws text from the top of the font's ascender and sizes it in pixels; GD draws from the baseline in
    // points (96 dpi). DejaVu Sans's ascender is 0.928 of the size.
    $put = function ($x, $y, $s, $str, $font, $color, $right = false) use ($im) {
        $pt = $s * 0.75;
        if ($right) {
            $box = imagettfbbox($pt, 0, $font, $str);
            $x -= $box[2] - $box[0];
        }
        imagettftext($im, $pt, 0, (int) round($x), (int) ($y + ceil($s * 0.928)), $color, $font, $str);
    };
    $width = function ($s, $str, $font) {
        $box = imagettfbbox($s * 0.75, 0, $font, $str);
        return $box[2] - $box[0];
    };
    $pill = function ($x1, $y1, $x2, $y2, $color) use ($im) {
        $r = ($y2 - $y1) / 2;
        if ($x2 - $x1 > 2 * $r) {
            imagefilledrectangle($im, (int) ($x1 + $r), $y1, (int) ($x2 - $r), $y2, $color);
        }
        imagefilledellipse($im, (int) ($x1 + $r), (int) ($y1 + $r), (int) (2 * $r + 1), (int) (2 * $r + 1), $color);
        imagefilledellipse($im, (int) ($x2 - $r), (int) ($y1 + $r), (int) (2 * $r + 1), (int) (2 * $r + 1), $color);
    };

    $put(60, 80, 54, $card['title'], $bold, $text);
    $put(60, 160, 30, $card['subtitle'], $reg, $muted);
    $x = 60;
    foreach ($card['stats'] as [$big, $small]) {
        $put($x, 255, 68, $big, $bold, $accent);
        $put($x, 345, 24, $small, $reg, $muted);
        $x += max($width(68, $big, $bold), $width(24, $small, $reg)) + 60;
    }
    if (!empty($card['line'])) {
        $put(60, 464, 28, $card['line'], $reg, $text);
    }
    if (!empty($card['bars'])) {
        [$bx, $by, $bw] = [720, 250, 420];
        $put($bx, $by - 50, 22, $card['bars_title'], $bold, $text);
        $top = $card['top'] ?? max(array_column($card['bars'], 1));
        foreach ($card['bars'] as $i => [$label, $v]) {
            $y = $by + $i * 52;
            $put($bx, $y, 20, $label, $reg, $text);
            $put($bx + $bw, $y, 20, call_user_func($card['fmt'], $v), $bold, $accent, true);
            $pill($bx, $y + 28, $bx + $bw, $y + 36, $bar_bg);
            $pill($bx, $y + 28, $bx + max(8, (int) ($bw * $v / max(1, $top))), $y + 36, $accent);
        }
    }
    $put(60, 560, 22, $card['footer'], $reg, $muted);

    ob_start();
    imagejpeg($im, null, 88);
    imagedestroy($im);
    return ob_get_clean();
}

/* ---------- keeping them current ---------- */

/**
 * Redraw every card whose content changed; returns the names of the cards that changed. Each changed card clears
 * the cached copies of the pages that show it, so they point at the new image.
 */
function mta_share_refresh($force = false)
{
    if (!function_exists('imagettftext')) {
        return [];
    }
    $files = get_option(MTA_SHARE_OPTION, []);
    $dir = mta_share_dir();
    if (!wp_mkdir_p($dir)) {
        return [];
    }
    $changed = [];
    foreach (mta_share_cards() as $name => [, $fn]) {
        try {
            $card = call_user_func($fn);
            if (!$card) {
                continue;
            }
            // What the card shows, as text: the number formatter is a function, so use its output instead.
            $fmt = $card['fmt'] ?? null;
            $sig = $card;
            $sig['fmt'] = is_string($fmt) ? $fmt : ($fmt ? 'fn' : null);
            $sig['bars'] = array_map(function ($b) use ($fmt) { return [$b[0], $fmt ? call_user_func($fmt, $b[1]) : $b[1], $b[1]]; }, $card['bars'] ?? []);
            $hash = substr(md5(wp_json_encode($sig) . filemtime(__FILE__)), 0, 10);
            $old = $files[$name] ?? ['hash' => '', 'files' => []];
            if (!$force && $old['hash'] === $hash && !empty($old['files']) && file_exists("$dir/{$old['files'][0]}")) {
                continue;
            }
            $file = "$name-$hash.jpg";
            if (!file_put_contents("$dir/$file", mta_share_draw($card))) {
                continue;
            }
            $list = array_values(array_unique(array_merge([$file], $old['files'])));
            foreach (array_slice($list, MTA_SHARE_KEEP) as $gone) {
                @unlink("$dir/$gone");
            }
            $files[$name] = ['hash' => $hash, 'files' => array_slice($list, 0, MTA_SHARE_KEEP), 'updated' => gmdate('c')];
            $changed[] = $name;
        } catch (Throwable $e) {
            error_log("[mta-ridership] share image $name failed: " . $e->getMessage());
        }
    }
    if ($changed) {
        update_option(MTA_SHARE_OPTION, $files, false);
        mta_share_clear_cache($changed);
        error_log('[mta-ridership] share images redrawn: ' . implode(', ', $changed));
    }
    return $changed;
}

function mta_share_clear_cache(array $cards)
{
    if (!function_exists('rocket_clean_post')) {
        return;
    }
    if (in_array('stations', $cards, true) && function_exists('rocket_clean_domain')) {
        rocket_clean_domain(); // the stations card is on all of the station pages
        return;
    }
    foreach ($cards as $card) {
        if ($card === 'ridership') {
            mta_ridership_clear_page_cache();
            if (function_exists('rocket_clean_files')) {
                rocket_clean_files([home_url('/' . MTA_STATUS_SLUG . '/')]);
            }
        } elseif ($card === 'lines') {
            mta_lines_clear_cache();
        } elseif ($card === 'buses') {
            mta_buses_clear_cache();
        } elseif ($card === 'congestion') {
            mta_congestion_clear_cache();
        }
    }
}

// After every data step that worked (status.php records each run), bring the cards up to date.
add_action('mta_ridership_step_done', function ($key, $ok) {
    if ($ok) {
        mta_share_refresh();
    }
}, 10, 2);
