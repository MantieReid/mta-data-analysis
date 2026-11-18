<?php
/**
 * Train and ferry timetables on station pages, from the small per-station files tools/timetables.py writes
 * (uploads/mta-timetables/<feed>/<station>.json, rebuilt on the server each night when the MTA's schedule changes).
 * The weekday, Saturday and Sunday tables are in the page for search engines; "Next trains" is filled in by the browser.
 */
if (!defined('ABSPATH')) {
    exit;
}

function mta_tt_dir()
{
    return wp_upload_dir()['basedir'] . '/mta-timetables';
}

function mta_tt_url()
{
    return wp_upload_dir()['baseurl'] . '/mta-timetables';
}

/** The station's timetable file, or null. */
function mta_tt_load($feed, $code)
{
    static $cache = [];
    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $code);
    if ($code === '') {
        return null;
    }
    if (!array_key_exists("$feed/$code", $cache)) {
        $f = mta_tt_dir() . "/$feed/$code.json";
        $cache["$feed/$code"] = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
    }
    return $cache["$feed/$code"];
}

function mta_tt_index($feed)
{
    $f = mta_tt_dir() . "/$feed/index.json";
    return is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
}

/** "7:05 AM" from minutes after midnight (wraps past midnight). */
function mta_tt_time($m)
{
    $m = ((int) $m) % 1440;
    $h = intdiv($m, 60);
    return ($h % 12 ?: 12) . ':' . sprintf('%02d', $m % 60) . ' ' . ($h < 12 ? 'AM' : 'PM');
}

/** Departures on one date: [[minute, to, train, arrives, route], ...], no duplicates. */
function mta_tt_day($t, $date)
{
    $on = array_flip($t['s'][$date] ?? []);
    $out = [];
    foreach ($t['d'] as [$m, $sv, $to, $train, $arr, $route]) {
        if (isset($on[$sv])) {
            $out[$m . '|' . $to . '|' . $train] = [$m, $to, $train, $arr, $route];
        }
    }
    ksort($out, SORT_NATURAL);
    return array_values($out);
}

/** The first date in the file that is a weekday (Mon-Fri), a Saturday and a Sunday. */
function mta_tt_sample_days($t)
{
    $pick = [];
    foreach (array_keys($t['s']) as $d) {
        $n = (int) date('N', strtotime($d));
        $k = $n <= 5 ? 'Weekday' : ($n === 6 ? 'Saturday' : 'Sunday');
        // Skip holidays-looking weekdays: a weekday with clearly fewer trains than the busiest one.
        if (!isset($pick[$k])) {
            $pick[$k] = $d;
        }
    }
    return $pick;
}

/** Departures grouped by where the train goes, for one day. */
function mta_tt_by_dest(array $rows)
{
    $g = [];
    foreach ($rows as $r) {
        $g[$r[1]][] = $r;
    }
    uasort($g, function ($a, $b) { return count($b) <=> count($a); });
    return $g;
}

/**
 * The "Schedule" section for a station page.
 * $what: 'train' or 'ferry' (wording), $label: e.g. "Hicksville LIRR".
 */
function mta_tt_section($feed, $code, $label, $what = 'train')
{
    $b = mta_tt_body($feed, $code, $what);
    return $b === '' ? '' : mta_more_sec("$label schedule", $b, 'Every scheduled departure, by where it goes.');
}

function mta_tt_body($feed, $code, $what = 'train')
{
    $t = mta_tt_load($feed, $code);
    if (!$t || empty($t['d'])) {
        return '';
    }
    $days = mta_tt_sample_days($t);
    $tabs = '';
    $panes = '';
    $first = true;
    $plural = $what === 'ferry' ? 'boats' : 'trains';
    $counts = [];
    foreach ($days as $k => $d) {
        $rows = mta_tt_day($t, $d);
        $counts[$k] = count($rows);
        $id = 'mta-tt-' . strtolower($k);
        $tabs .= '<button type="button" aria-pressed="' . ($first ? 'true' : 'false') . '" data-tt-tab="' . $id . '">' . esc_html($k) . '</button> ';
        $body = '';
        $open = true;
        foreach (mta_tt_by_dest($rows) as $to => $list) {
            $n = count($list);
            $body .= '<details class="mta-tt-dest"' . ($open ? ' open' : '') . '><summary><span class="mta-tt-to">To ' . esc_html($to) . '</span>' .
                '<span class="mta-tt-meta">' . $n . ' ' . ($n === 1 ? substr($plural, 0, -1) : $plural) . ' · first ' . mta_tt_time($list[0][0]) . ' · last ' . mta_tt_time($list[$n - 1][0]) . '</span></summary>' .
                '<div class="mta-tt-wrap"><table class="mta-tt"><thead><tr><th>Leaves</th><th>Arrives</th>' .
                ($what === 'train' ? '<th>Train</th>' : '') . '<th>Line</th></tr></thead><tbody>';
            foreach ($list as [$m, , $train, $arr, $route]) {
                $body .= '<tr><td><strong>' . mta_tt_time($m) . '</strong></td><td>' . mta_tt_time($arr) . ' <span class="mta-note">' . max(1, $arr - $m) . ' min</span></td>' .
                    ($what === 'train' ? '<td>' . esc_html($train) . '</td>' : '') . '<td>' . esc_html(preg_replace('/ Branch$/', '', $route)) . '</td></tr>';
            }
            $body .= '</tbody></table></div></details>';
            $open = false;
        }
        $panes .= '<div id="' . $id . '" data-tt-pane' . ($first ? '' : ' hidden') . '><p class="mta-note">' . esc_html($k . ' schedule, shown for ' . date_i18n('l, F j', strtotime($d)) . ': ' . count($rows) . ' ' . $plural . '.') . '</p>' . $body . '</div>';
        $first = false;
    }
    // Next departures: the browser picks them from today's and tomorrow's rows (the page itself may be cached).
    $today = current_time('Ymd');
    $tomorrow = date('Ymd', strtotime($today . ' +1 day'));
    $next = ['now' => (int) current_time('G') * 60 + (int) current_time('i'), 'days' => [$today => mta_tt_day($t, $today), $tomorrow => mta_tt_day($t, $tomorrow)]];
    $o = '<div class="mta-tt-card"><div class="mta-tt-next" data-tt-next="' . esc_attr(wp_json_encode($next)) . '"><p class="mta-note">Next ' . $plural . ' load here.</p></div>' .
        '<div class="mta-view-switch mta-tt-days">' . $tabs . '</div>' . $panes .
        '<p class="mta-note mta-tt-foot">Scheduled times from the published timetable, updated ' . esc_html($t['u']) . '. Check live times and service changes on ' .
        ($what === 'ferry' ? '<a href="https://www.ferry.nyc/" rel="noopener">ferry.nyc</a>' : '<a href="https://www.mta.info/" rel="noopener">mta.info</a> or the TrainTime app') . ' before you go.</p></div>';
    mta_tt_style();
    mta_tt_script();
    return $o;
}

/** Looks for the schedule, once per page (uses the site's colors). */
function mta_tt_style()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    wp_add_inline_style('mta-ridership', '
.mta-ridership .mta-tt-card { padding: 1.1em 1.2em; border: 1px solid var(--line, var(--mta-border)); border-radius: 12px; background: var(--card-2, transparent); }
.mta-ridership .mta-tt-label { margin: 0 0 .6em; font-weight: 600; color: var(--text, inherit); }
.mta-ridership .mta-tt-board { display: grid; grid-template-columns: repeat(3, 1fr); gap: .7em; margin-bottom: 1.3em; }
.mta-ridership .mta-tt-dep { background: var(--card, transparent); border: 1px solid var(--line, var(--mta-border)); border-radius: 12px; padding: .75em 1em; min-width: 0; }
.mta-ridership .mta-tt-dep strong { display: block; font-size: 1.55rem; font-weight: 600; line-height: 1.15; color: var(--figure, var(--mta-accent)); font-variant-numeric: tabular-nums; }
.mta-ridership .mta-tt-dep span { display: block; font-size: .88rem; color: var(--muted, var(--mta-muted)); overflow-wrap: anywhere; }
.mta-ridership .mta-tt-dep .mta-tt-in { margin: .15em 0; color: var(--text, inherit); font-weight: 600; }
.mta-ridership .mta-tt-days { margin: 0 0 .5em; }
.mta-ridership .mta-tt-dest { background: var(--card, transparent); border: 1px solid var(--line, var(--mta-border)); border-radius: 12px; margin: 0 0 .6em; overflow: hidden; }
.mta-ridership .mta-tt-dest > summary { cursor: pointer; padding: .75em 1em; display: flex; flex-wrap: wrap; gap: .1em .8em; align-items: baseline; list-style: none; }
.mta-ridership .mta-tt-dest > summary::-webkit-details-marker { display: none; }
.mta-ridership .mta-tt-dest > summary:hover { background: var(--row-hover, transparent); }
.mta-ridership .mta-tt-to { font-weight: 600; color: var(--text, inherit); }
.mta-ridership .mta-tt-to::before { content: "+"; display: inline-block; width: 1.1em; color: var(--link, var(--mta-accent)); font-weight: 700; }
.mta-ridership .mta-tt-dest[open] .mta-tt-to::before { content: "\2212"; }
.mta-ridership .mta-tt-meta { color: var(--muted, var(--mta-muted)); font-size: .88rem; }
.mta-ridership .mta-tt-wrap { border-top: 1px solid var(--line, var(--mta-border)); overflow-x: auto; }
.mta-ridership table.mta-tt { width: 100%; border-collapse: collapse; margin: 0; font-size: .93rem; font-variant-numeric: tabular-nums; }
.mta-ridership table.mta-tt th { text-align: left; font-size: .78rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--muted, var(--mta-muted)); padding: .55em 1em; background: var(--card-2, transparent); }
.mta-ridership table.mta-tt td, .mta-ridership table.mta-tt th { border-left: 0; border-right: 0; box-shadow: none; }
.mta-ridership table.mta-tt td { padding: .5em 1em; border-top: 1px solid var(--line, var(--mta-border)); white-space: nowrap; }
.mta-ridership table.mta-tt td strong { color: var(--text, inherit); font-weight: 600; }
.mta-ridership table.mta-tt tbody tr:hover { background: var(--row-hover, transparent); }
.mta-ridership .mta-tt-foot { margin: .8em 0 0; }
.mta-ridership .mta-tt-pick select { font: inherit; padding: .45em .7em; border: 1px solid var(--line-2, var(--mta-border)); border-radius: 8px; background: var(--card, transparent); color: inherit; max-width: 100%; }
@media (max-width: 560px) { .mta-ridership table.mta-tt th:last-child, .mta-ridership table.mta-tt td:last-child { display: none; } .mta-ridership table.mta-tt td, .mta-ridership table.mta-tt th { padding-left: .7em; padding-right: .7em; } .mta-ridership .mta-tt-board { grid-template-columns: 1fr 1fr; } .mta-ridership .mta-tt-board .mta-tt-dep:nth-child(n+5) { display: none; } .mta-ridership .mta-tt-card { padding: .8em; } }
');
}

/** Tabs and "next departures", once per page. */
function mta_tt_script()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    add_action('wp_footer', function () {
        ?>
<script>
(function () {
  document.querySelectorAll('[data-tt-tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-tt-tab]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      document.querySelectorAll('[data-tt-pane]').forEach(function (p) { p.hidden = p.id !== b.getAttribute('data-tt-tab'); });
    });
  });
  function fmt(m) { m = ((m % 1440) + 1440) % 1440; var h = Math.floor(m / 60), mm = m % 60; return ((h % 12) || 12) + ':' + (mm < 10 ? '0' : '') + mm + (h < 12 ? ' AM' : ' PM'); }
  document.querySelectorAll('[data-tt-next]').forEach(function (el) {
    var d = JSON.parse(el.getAttribute('data-tt-next'));
    var now = new Date(new Date().toLocaleString('en-US', { timeZone: 'America/New_York' }));
    var key = function (dt) { return dt.getFullYear() + ('0' + (dt.getMonth() + 1)).slice(-2) + ('0' + dt.getDate()).slice(-2); };
    var t = now.getHours() * 60 + now.getMinutes(), y = new Date(now); y.setDate(y.getDate() - 1);
    var list = [];
    // Trains after midnight belong to the day before; a day's rows can pass 1440.
    (d.days[key(y)] || []).forEach(function (r) { if (r[0] >= 1440 && r[0] - 1440 >= t) list.push([r[0] - 1440, r]); });
    (d.days[key(now)] || []).forEach(function (r) { if (r[0] >= t) list.push([r[0], r]); });
    var tm = new Date(now); tm.setDate(tm.getDate() + 1);
    (d.days[key(tm)] || []).forEach(function (r) { list.push([r[0] + 1440, r]); });
    list.sort(function (a, b) { return a[0] - b[0]; });
    list = list.slice(0, 6);
    if (!list.length) { el.innerHTML = '<p class="mta-note">No more scheduled departures in the next day.</p>'; return; }
    var h = '<p class="mta-tt-label">Next departures <span class="mta-note">(scheduled)</span></p><div class="mta-tt-board">';
    list.forEach(function (x) {
      var mins = x[0] - t, r = x[1];
      h += '<div class="mta-tt-dep"><strong>' + fmt(r[0]) + '</strong><span class="mta-tt-in">' + (mins < 1 ? 'now' : mins < 60 ? 'in ' + mins + ' min' : 'in ' + Math.floor(mins / 60) + ' h ' + (mins % 60) + ' min') + '</span><span>To ' + String(r[1]).replace(/[<>&]/g, '') + '</span></div>';
    });
    el.innerHTML = h + '</div>';
  });
})();
</script>
        <?php
    }, 30);
}

/** Ferry and Staten Island Railway pages: pick a stop; the choice reloads the page with ?stop= (the default stop is what search engines see). */
function mta_tt_picker($feed, $label, $default = '')
{
    $idx = mta_tt_index($feed);
    if (!$idx || empty($idx['stations'])) {
        return '';
    }
    $names = $idx['stations'];
    asort($names);
    $want = isset($_GET['stop']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) wp_unslash($_GET['stop'])) : '';
    $pick = isset($names[$want]) ? $want : (isset($names[$default]) ? $default : array_key_first($names));
    $opts = '';
    foreach ($names as $c => $n) {
        $opts .= '<option value="' . esc_attr($c) . '"' . selected($c, $pick, false) . '>' . esc_html($n) . '</option>';
    }
    $o = '<form method="get" class="mta-tt-pick"><label>Stop: <select name="stop" onchange="this.form.submit()">' . $opts . '</select></label> <noscript><button>Show</button></noscript></form>' .
        mta_tt_body($feed, $pick, $feed === 'ferry' ? 'ferry' : 'train');
    return mta_more_sec("$label schedule: " . $names[$pick], $o, 'Pick a stop to see every scheduled departure.');
}
