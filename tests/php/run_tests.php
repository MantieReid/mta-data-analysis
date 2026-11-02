<?php
/**
 * Plugin tests (plain PHP, no PHPUnit needed): history merge, run lock, step status, alerts, health.
 * Run: php tests/php/run_tests.php   (exits non-zero if any test fails)
 */
require __DIR__ . '/bootstrap.php';

$failed = 0;
$count = 0;
function check($name, $cond)
{
    global $failed, $count;
    $count++;
    if (!$cond) {
        $failed++;
        fwrite(STDERR, "FAIL: $name\n");
    }
}
function row($sid, $day, $riders)
{
    return ['station_complex_id' => $sid, 'station_complex' => "Station $sid", 'borough' => 'M', 'day' => "{$day}T00:00:00.000", 'riders' => (string) $riders];
}

/* ---------- history merge (the 30-day re-check) ---------- */

$h = ['start' => '2026-10-01', 'ndays' => 3, 'names' => ['1' => ['Station 1', 'M'], '2' => ['Station 2', 'M']],
      'st' => ['1' => [0 => 100, 1 => 110, 2 => 120], '2' => [1 => 50]]];
$window = [row('1', '2026-10-02', 110), row('2', '2026-10-02', 50), row('1', '2026-10-03', 120)];

[$h1, $c1] = mta_ridership_history_apply($h, $window, '2026-10-02', '2026-10-03');
check('re-applying unchanged rows changes nothing', $h1['st'] == $h['st'] && $c1 == ['new' => 0, 'changed' => 0, 'unchanged' => 3, 'removed' => 0]);
[$h2, $c2] = mta_ridership_history_apply($h1, $window, '2026-10-02', '2026-10-03');
check('running twice is idempotent', $h2 == $h1 && $c2 == $c1);

$revised = [row('1', '2026-10-02', 115), row('1', '2026-10-03', 120), row('1', '2026-10-04', 130)];
[$h3, $c3] = mta_ridership_history_apply($h, $revised, '2026-10-02', '2026-10-04');
check('counts revised, new, unchanged and removed station-days', $c3 == ['new' => 1, 'changed' => 1, 'unchanged' => 1, 'removed' => 1]);
check('a revised day overwrites the old value', $h3['st']['1'][1] === 115);
check('a removed station-day disappears', !isset($h3['st']['2'][1]));
check('days before the window are untouched', $h3['st']['1'][0] === 100);
check('the history grows to cover new days', $h3['ndays'] === 4 && $h3['st']['1'][3] === 130);
check('no double counting', array_sum($h3['st']['1']) === 100 + 115 + 120 + 130);

/* ---------- a revision flows through to the unusual-days result ---------- */

$fx = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/trends.json'), true);
$hist = ['ndays' => $fx['ndays'], 'st' => array_map(function ($s) { return array_combine(array_map('intval', array_keys($s)), $s); }, $fx['st'])];
$sys = array_combine(array_map('intval', array_keys($fx['sys_ratio'])), $fx['sys_ratio']);
$has = function ($events, $sid, $first) {
    foreach ($events as $e) {
        if ($e['id'] === $sid && $e['first'] === $first) {
            return true;
        }
    }
    return false;
};
check('before the revision, no event for C on day 120', !$has(mta_ridership_find_events($hist, [], $sys, true), 'C', 120));
$hist['st']['C'][120] = 12000;
check('after the revision, C on day 120 is an unusual day', $has(mta_ridership_find_events($hist, [], $sys, true), 'C', 120));

/* ---------- run lock ---------- */

reset_wp();
check('first run takes the lock', mta_ridership_lock('a') === true);
check('a second run cannot take it', mta_ridership_lock('b') === false);
update_option(MTA_RIDERSHIP_LOCK_OPTION, ['owner' => 'a', 'until' => time() - 1]);
check('an expired lock (crashed run) is taken over', mta_ridership_lock('b') === true);
mta_ridership_unlock();
check('unlock frees it', mta_ridership_lock('c') === true);
mta_ridership_unlock();

/* ---------- step status, overlap, failures and alerts (with fake steps, no network) ---------- */

$GLOBALS['fake_result'] = 'ok';
function fake_step()
{
    if ($GLOBALS['fake_result'] === 'ok') {
        update_option('mta_ridership_lines', ['latest' => gmdate('Y-m', strtotime('-1 month'))]);
        delete_option('mta_ridership_lines_error');
    } else {
        update_option('mta_ridership_lines_error', gmdate('c') . ' cURL error 28: Operation timed out');
    }
}
$GLOBALS['wp_filters']['mta_ridership_steps'] = [function ($steps) {
    $steps['lines']['fn'] = 'fake_step';
    return $steps;
}];

reset_wp();
mta_ridership_run_step('lines');
$st = get_option(MTA_RIDERSHIP_STATUS_OPTION)['lines'];
check('a successful run is recorded', $st['failures'] === 0 && !empty($st['last_success']) && $st['error'] === '' && $st['through'] !== null);
check('the lock is released after the run', get_option(MTA_RIDERSHIP_LOCK_OPTION) === false);

update_option(MTA_RIDERSHIP_LOCK_OPTION, ['owner' => 'buses', 'until' => time() + 600]);
check('an overlapping run is postponed, not run', mta_ridership_run_step('lines') === 'postponed' && wp_next_scheduled('mta_ridership_lines_step') > time() && get_option(MTA_RIDERSHIP_STATUS_OPTION)['lines']['last_attempt'] === $st['last_attempt']);
mta_ridership_unlock();

update_option('mta_ridership_alert_email', 'owner@example.test');
$GLOBALS['fake_result'] = 'fail';
mta_ridership_run_step('lines');
$st = get_option(MTA_RIDERSHIP_STATUS_OPTION)['lines'];
check('a failed run is recorded with its error', $st['failures'] === 1 && strpos($st['error'], 'timed out') !== false && !empty($st['last_success']));
check('one failure sends no alert (it retries)', count($GLOBALS['wp_mails']) === 0);
mta_ridership_run_step('lines');
check('two failures in a row send one alert', count($GLOBALS['wp_mails']) === 1 && strpos($GLOBALS['wp_mails'][0]['subject'], 'Subway lines') !== false);
mta_ridership_run_step('lines');
check('further failures the same day send no more alerts', count($GLOBALS['wp_mails']) === 1);
check('a failing step shows as delayed by our refresh', mta_ridership_health()['lines']['state'] === 'delayed' && mta_ridership_health()['lines']['reason'] === 'refresh');
$GLOBALS['fake_result'] = 'ok';
mta_ridership_run_step('lines');
check('a success resets the failure count', get_option(MTA_RIDERSHIP_STATUS_OPTION)['lines']['failures'] === 0);
update_option('mta_ridership_alert_email', '');

/* ---------- health: MTA publishing delays vs no data ---------- */

reset_wp();
update_option('mta_ridership_data', ['latest_day' => gmdate('Y-m-d', strtotime('-10 days'))]);
update_option('mta_ridership_congestion', ['latest' => gmdate('Y-m-d', strtotime('-40 days'))]);
$hl = mta_ridership_health();
check('ridership 10 days behind is current', $hl['refresh']['state'] === 'current' && $hl['refresh']['age'] === 10);
check('congestion 40 days behind is delayed by the MTA', $hl['congestion']['state'] === 'delayed' && $hl['congestion']['reason'] === 'source');
check('a step with no data yet is unavailable', $hl['buses']['state'] === 'unavailable');

/* ---------- lazy-loaded data blocks (chunks) ---------- */

reset_wp();
$stations = [];
$lookup = [];
for ($i = 1; $i <= 200; $i++) { // about as many stations as the real data
    $stations[] = ['id' => "s$i", 'name' => "Station $i", 'borough' => 'M', 'lat' => 40.7, 'lon' => -74.0, 'hours' => array_fill(0, 24, $i)];
    $lookup[] = ['id' => "s$i", 'name' => "Station $i", 'borough' => 'M', 'avg' => 1000 * (201 - $i), 'yoy' => 1.5, 'rank' => $i];
}
$calendar = ['updated' => 'c1', 'days' => ['2026-10-01' => 1.0]];
$extras = ['updated' => 'e1', 'stations' => $stations, 'lookup' => $lookup, 'to' => '2026-10-14', 'yoy' => ['x' => 1]];
update_option(MTA_RIDERSHIP_EXTRAS_OPTION, $extras);
update_option(MTA_RIDERSHIP_CALENDAR_OPTION, $calendar);

check('only the three listed blocks can be fetched', mta_ridership_chunk('options') === null && mta_ridership_chunk('../x') === null
    && mta_ridership_chunk('mta_ridership_app_token') === null && mta_ridership_chunk('') === null);
check('the three blocks come back as stored', mta_ridership_chunk('stations') === $stations && mta_ridership_chunk('lookup') === $lookup && mta_ridership_chunk('calendar') === $calendar);
update_option(MTA_RIDERSHIP_EXTRAS_OPTION, ['updated' => 'e1']);
check('a block that is not built yet is not found', mta_ridership_chunk('stations') === null);
update_option(MTA_RIDERSHIP_EXTRAS_OPTION, $extras);

$full = ['extras' => $extras, 'calendar' => $calendar, 'monthly' => [['month' => '2026-10']]];
$p = mta_ridership_lazy_payload($full, ['map', 'lookup', 'holidays']);
check('the big blocks are not embedded in the page', !isset($p['extras']['stations']) && !isset($p['extras']['lookup']) && !isset($p['calendar']));
check('small data stays embedded', $p['extras']['to'] === '2026-10-14' && $p['extras']['yoy'] === ['x' => 1] && $p['monthly'] === $full['monthly']);
check('the top five stations come with their hourly riders', count($p['lookup_top']) === 5 && $p['lookup_top'][0]['id'] === 's1' && $p['lookup_top'][0]['hours'] === array_fill(0, 24, 1));
check('the station total is sent', $p['lookup_total'] === 200);
check('the payload is much smaller', strlen(json_encode($p)) < strlen(json_encode($full)) / 2);
$v1 = $p['chunks']['v'];
update_option(MTA_RIDERSHIP_EXTRAS_OPTION, ['updated' => 'e2'] + $extras);
check('the fetch address changes when the data is rebuilt', mta_ridership_lazy_payload($full, ['map'])['chunks']['v'] !== $v1);
check('the calendar is only fetched when the page has it', $p['chunks']['calendar'] === true && mta_ridership_lazy_payload($full, ['map'])['chunks']['calendar'] === false);

/* ---------- public data status: only safe fields ---------- */

reset_wp();
update_option('mta_ridership_data', ['latest_day' => gmdate('Y-m-d', strtotime('-9 days')), 'updated' => gmdate('c')]);
update_option('mta_ridership_congestion', ['latest' => gmdate('Y-m-d', strtotime('-50 days')), 'updated' => gmdate('c')]);
update_option(MTA_RIDERSHIP_STATUS_OPTION, [
    'trends' => ['failures' => 3, 'error' => 'cURL error 28 at /home/mantiere/public_html/secret-file.php', 'last_attempt' => gmdate('c'), 'seconds' => 351.2],
    'lines'  => ['failures' => 2, 'error' => 'HTTP 500: stack trace in /home/mantiere/x.php', 'last_attempt' => gmdate('c')],
]);
$rows = mta_ridership_public_status();
check('only the four public datasets are listed', array_column($rows, 'id') === ['refresh', 'congestion', 'lines', 'buses']);
check('a recent dataset is current', $rows[0]['state'] === 'current' && $rows[0]['reason'] === null);
check('an old dataset with a healthy refresh is delayed by the MTA', $rows[1]['state'] === 'delayed' && $rows[1]['reason'] === 'mta');
check('a dataset with no data is unavailable', $rows[3]['state'] === 'unavailable');
update_option('mta_ridership_lines', ['latest' => gmdate('Y-m', strtotime('-1 month')), 'updated' => gmdate('c')]);
check("a failing refresh is reported as ours, not the MTA's", mta_ridership_public_status()[2]['reason'] === 'our-refresh');
$all = json_encode(mta_ridership_public_status()) . mta_ridership_status_html();
check('no error text, file paths, timings or internal steps leak', strpos($all, 'cURL') === false && strpos($all, '/home/') === false
    && strpos($all, 'secret-file') === false && strpos($all, 'stack trace') === false && strpos($all, 'seconds') === false
    && stripos($all, 'trends') === false && stripos($all, 'extras') === false);
check('the page explains the difference between an MTA delay and ours', strpos($all, 'Waiting on the MTA') !== false && strpos($all, 'retrying automatically') !== false);
check('no status link until the page is published', mta_ridership_status_link() === '');

// ---- Live subway map: stop order, branches and part-time stops from a tiny GTFS (tests/fixtures/gtfs_mini.zip) ----
$m = mta_map_build(dirname(__DIR__) . '/fixtures/gtfs_mini.zip');
$a = $m['routes']['A'] ?? null;
check('map: every route is read, and 6X folds into the 6', isset($a, $m['routes']['6']) && !isset($m['routes']['6X']));
check('map: one branch per pair of end stations, busiest first', $a && count($a['b']) === 2 && $a['b'][0][0] === 'S1' && $a['b'][0][1] === 'S4' && $a['b'][1][1] === 'S5');
check('map: a short turn inside a longer pattern is not a branch', $a && array_sum(array_column($a['b'], 2)) === 20);
check('map: the branch lists every stop in order, from its longest pattern', $a && array_column($a['b'][0][3], 0) === ['S1', 'S2', 'SX', 'S3', 'S4']);
check('map: a stop fewer than half the trains make is part-time', $a && array_column($a['b'][0][3], 1) === [0, 0, 1, 0, 0]);
check('map: the 6 counts trips of the 6 and the 6X', ($m['routes']['6']['b'][0][2] ?? 0) === 5);
check('map: one shape per pattern kept, encoded', $a && count($a['sh']) === 3 && $a['sh'][0] !== '');
check('map: the encoded shape decodes back to its points', mta_map_encode([[38.5, -120.2], [40.7, -120.95], [43.252, -126.453]]) === '_p~iF~ps|U_ulLnnqC_mqNvxq`@');
check('map: only stops a branch uses are kept', count($m['stops']) === 6 && $m['stops']['SX'][0] === 'Local');
check('map: each line knows its live feed', $a['f'] === 'gtfs-ace' && $m['routes']['6']['f'] === 'gtfs');

// ---- Alerts: only active ones, in the MTA's words, delays first ----
$t = 1800000000;
$en = function ($s) { return ['translation' => [['text' => $s, 'language' => 'en'], ['text' => "<p>$s</p>", 'language' => 'en-html']]]; };
$raw = ['entity' => [
    ['alert' => ['active_period' => [['start' => $t - 100]], 'informed_entity' => [['route_id' => 'Q']], 'header_text' => $en('Planned work'),
                 'transit_realtime.mercury_alert' => ['alert_type' => 'Planned - Stops Skipped']]],
    ['alert' => ['active_period' => [['start' => $t - 9000, 'end' => $t - 10]], 'informed_entity' => [['route_id' => 'A']], 'header_text' => $en('Old delay'),
                 'transit_realtime.mercury_alert' => ['alert_type' => 'Delays']]],
    ['alert' => ['active_period' => [['start' => $t - 60, 'end' => $t + 600]], 'informed_entity' => [['route_id' => '6X'], ['agency_id' => 'MTASBWY']],
                 'header_text' => $en("Downtown [6] trains   are delayed."), 'transit_realtime.mercury_alert' => ['alert_type' => 'Delays']]],
]];
$al = mta_map_trim_alerts($raw, $t);
check('alerts: ended alerts are left out', count($al) === 2 && !in_array('Old delay', array_column($al, 'header'), true));
check('alerts: delays come before planned work', $al[0]['type'] === 'Delays' && $al[1]['type'] === 'Planned - Stops Skipped');
check('alerts: plain English text, routes folded (6X is the 6), end time kept', $al[0]['header'] === 'Downtown [6] trains are delayed.' && $al[0]['routes'] === ['6'] && $al[0]['until'] === $t + 600);

// ---- Bus stop heat map: stops in route order, averages per day, gaps kept ----
check('stops: a month\'s weekdays, Saturdays and Sundays', mta_busstops_days('2026-09') === ['wk' => 22, 'sa' => 4, 'su' => 4]);
$row = function ($d, $s, $h, $b, $n, $q) { return ['r' => 'B44+', 'd' => $d, 's' => $s, 'h' => $h, 'b' => $b, 'q' => $q] + ($n === null ? [] : ['n' => (string) $n]); };
$hb = mta_busstops_build([
    $row('0', '300', '08', 'wk', 220, '5'), $row('0', '100', '08', 'wk', 44, '1'), $row('0', '100', '09', 'sa', 8, '1'),
    $row('0', '200', '08', 'wk', 66, '7'), $row('0', '200', '09', 'wk', 11, '3'),   // limited and local positions: 3 is its place
    $row('0', '400', '08', 'wk', null, '4'),                                         // no estimate at all: left out
    $row('1', '300', '23', 'su', 2, '1'),
], ['100' => ['First St', 40.6, -73.9], '200' => ['Middle Av'], '300' => ['Last Pl', 40.7, -73.95]], ['wk' => 22, 'sa' => 4, 'su' => 4]);
$d0 = $hb['dirs'][0] ?? null;
check('stops: in route order, lowest position wins', $d0 && array_column($d0['stops'], 0) === ['First St', 'Middle Av', 'Last Pl']);
check('stops: direction named for its last stop', $d0['to'] === 'Last Pl' && $hb['dirs'][1]['to'] === 'Last Pl' && count($hb['dirs']) === 2);
check('stops: monthly sums become an average day', $d0['stops'][0][1]['wk'][8] === 2.0 && $d0['stops'][2][1]['wk'][8] === 10.0 && $d0['stops'][0][1]['sa'][9] === 2.0);
check('stops: an hour without an estimate stays empty, not zero', $d0['stops'][0][1]['wk'][7] === null && $d0['stops'][0][1]['su'][8] === null);
check('stops: each day kind has 24 hours', count($d0['stops'][1][1]['wk']) === 24 && count($d0['stops'][1][1]['su']) === 24);
check('stops: places kept for the map, none for a stop without one', ($d0['stops'][0][2] ?? null) === 40.6 && ($d0['stops'][2][3] ?? null) === -73.95 && count($d0['stops'][1]) === 2);
check('stops: small numbers keep one decimal', $hb['dirs'][1]['stops'][0][1]['su'][23] === 0.5);

echo ($count - $failed) . " passed, $failed failed\n";
exit($failed ? 1 : 0);
