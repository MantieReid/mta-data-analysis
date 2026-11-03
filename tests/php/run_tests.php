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

// ---- Citywide bus stop map: routes sharing a stop are added together ----
$h24 = function ($at, $v) { $a = array_fill(0, 24, null); $a[$at] = $v; return $a; };
$st = function ($name, $lat, $lon, $wk) use ($h24) { return [$name, ['wk' => $wk, 'sa' => array_fill(0, 24, null), 'su' => array_fill(0, 24, null)], $lat, $lon]; };
$cb = mta_busstops_combine([
    ['B1', ['dirs' => [['to' => 'X', 'stops' => [$st('Shared St', 40.6, -73.9, $h24(8, 10.4)), $st('Quiet Av', 40.61, -73.91, $h24(8, 0.3)), ['No Place Rd', []]]]]]],
    ['B2', ['dirs' => [['to' => 'Y', 'stops' => [$st('Shared St', 40.6, -73.9, $h24(8, 5.2))]]]]],
]);
check('citywide: one entry per place, quietest first', array_column($cb['stops'], 2) === ['Quiet Av', 'Shared St']);
check('citywide: a shared stop adds its routes together', $cb['wk'][1][8] === 16 && $cb['stops'][1][3] === [0, 1] && $cb['routes'] === ['B1', 'B2']);
check('citywide: gaps stay empty, stops without a place are left out', $cb['wk'][1][7] === null && $cb['sa'][0][8] === null && count($cb['stops']) === 2);

// ---- Station trip flows: weekday rows ÷ 5, the station itself and unknown places left out, busiest last ----
$fs = ['1' => ['Home St', 'home-st', 40.7, -73.9], '2' => ['Big Av', 'big-av', 40.75, -73.98], '3' => ['Small Rd', 'small-rd', 40.6, -73.95],
       '4' => ['Nowhere', 'nowhere', null, null], '5' => ['Tiny Pl', 'tiny-pl', 40.65, -73.8]];
$fb = mta_flows_build('1', [
    ['d' => '2', 'h' => '8', 'b' => 'wk', 'n' => '500'], ['d' => '2', 'h' => '17', 'b' => 'wk', 'n' => '100'], ['d' => '2', 'h' => '8', 'b' => 'sa', 'n' => '30'],
    ['d' => '3', 'h' => '8', 'b' => 'wk', 'n' => '200'], ['d' => '3', 'h' => '23', 'b' => 'su', 'n' => '2.25'],
    ['d' => '1', 'h' => '8', 'b' => 'wk', 'n' => '50'], // same station in and out: counted in the total only
    ['d' => '4', 'h' => '8', 'b' => 'wk', 'n' => '50'], // no place on the map
    ['d' => '5', 'h' => '9', 'b' => 'wk', 'n' => '5'],
], $fs, 2);
check('flows: top destinations kept, busiest last', array_column($fb['to'], 0) === ['Small Rd', 'Big Av']);
check('flows: weekday rows are divided by 5', $fb['to'][1][4]['wk'][8] === 100.0 && $fb['to'][1][4]['wk'][17] === 20.0 && $fb['to'][1][4]['sa'][8] === 30.0);
check('flows: small numbers keep one decimal', $fb['to'][0][4]['su'][23] === 2.3);
check('flows: the total counts every trip from here', $fb['total']['wk'][8] === 160 && $fb['total']['wk'][9] === 1);
check('flows: share of weekday trips the kept stations take', $fb['share'] == 88.0 && $fb['from'] === ['Home St', 40.7, -73.9]);
check('flows: unknown station gives no file', mta_flows_build('9', [], $fs) === null && mta_flows_build('4', [], $fs) === null);

// ---- Bus speeds: speed = distance ÷ time over all trips, routes sharing a stretch added together ----
$sr = function ($h, $b, $d, $t, $n, $alat = '40.6', $zlat = '40.61') {
    return ['a' => 'NOSTRAND AV/AVENUE H', 'z' => 'FLATBUSH AV', 'alat' => $alat, 'alon' => '-73.95', 'zlat' => $zlat, 'zlon' => '-73.95', 'h' => $h, 'b' => $b, 'd' => $d, 't' => $t, 'n' => $n];
};
$s1 = mta_busspeeds_route([$sr('8', 'wk', '10', '120', '10'), $sr('8', 'wk', '5', '30', '5'), $sr('9', 'sa', '1', '6', '2'),
                           $sr('8', 'wk', '9', '9', '9', '40.6', '40.9')]); // 33 km straight line: left out
check('speeds: one stretch per place, long jumps left out', count($s1) === 1 && reset($s1)[0] === 'Nostrand Av/Avenue H' && reset($s1)[1] === 'Flatbush Av');
check('speeds: trips in the same hour are added up', reset($s1)[6]['wk'][8] === [15.0, 150.0, 15]);
$s2 = mta_busspeeds_route([$sr('8', 'wk', '6', '30', '6'), $sr('8', 'wk', '4', '20', '4', '40.62', '40.63')]);
$sc = mta_busspeeds_combine([['B44', $s1], ['B41', $s2]]);
check('speeds: shared stretch adds routes together', count($sc['segs']) === 2 && $sc['routes'] === ['B44', 'B41'] && in_array([0, 1], array_column($sc['segs'], 6), true));
$shared = array_search([0, 1], array_column($sc['segs'], 6), true);
check('speeds: mph from total distance ÷ total time', $sc['wk'][$shared][8] === 7.0); // 21 miles in 180 minutes
check('speeds: fewer than 3 trips gives no speed; fastest first', $sc['sa'][$shared][9] === null && $sc['wk'][0][8] >= $sc['wk'][1][8]);

// ---- Station map: busiest first, elevators only where the report card has them, no place = left out ----
$sm = mta_stationmap_data([
    '1' => ['name' => 'Small St (G)', 'slug' => 'small-st', 'avg' => 900, 'yoy' => -4.5, 'lat' => 40.7, 'lon' => -73.9],
    '2' => ['name' => 'Big Sq (1,2,3)', 'slug' => 'big-sq', 'avg' => 90000, 'yoy' => null, 'lat' => 40.75, 'lon' => -73.98],
    '3' => ['name' => 'Lost Pl (A)', 'slug' => 'lost-pl', 'avg' => 5000, 'yoy' => 2, 'lat' => null, 'lon' => null],
], ['stations' => ['2' => ['equip' => ['elevator' => ['units' => 4, 'avail' => 96.2]]]]]);
check('station map: busiest first, stations without a place left out', array_column($sm, 1) === ['big-sq', 'small-st']);
check('station map: growth and elevators', $sm[0][6] === null && $sm[0][7] === 96.2 && $sm[0][8] === 4 && $sm[1][6] === -4.5 && $sm[1][7] === null && $sm[1][8] === 0);

// ---- Bus lane tickets: by stop, quiet stops left out, fewest first ----
$lb = mta_buslanes_build([
    ['s' => '1', 'r' => 'M100', 'name' => 'BROADWAY/ISHAM ST', 'lat' => '40.868', 'lon' => '-73.92', 'k' => 'lane', 'n' => '20'],
    ['s' => '1', 'r' => 'Bx12+', 'name' => 'BROADWAY/ISHAM ST', 'lat' => '40.868', 'lon' => '-73.92', 'k' => 'lane', 'n' => '10'],
    ['s' => '1', 'r' => 'M100', 'name' => 'BROADWAY/ISHAM ST', 'lat' => '40.868', 'lon' => '-73.92', 'k' => 'dbl', 'n' => '5'],
    ['s' => '2', 'r' => 'M100', 'name' => 'W 207 ST/10 AV', 'lat' => '40.864', 'lon' => '-73.91', 'k' => 'stop', 'n' => '12'],
    ['s' => '3', 'r' => 'Q1', 'name' => 'QUIET RD', 'lat' => '40.7', 'lon' => '-73.9', 'k' => 'stop', 'n' => '2'],
    ['s' => '4', 'r' => 'Q10/Q80', 'name' => 'LEFFERTS BLVD', 'lat' => '40.69', 'lon' => '-73.82', 'k' => 'stop', 'n' => '40'],
], [['s' => '1', 'r' => 'M100', 'h' => '8', 'b' => 'wk', 'n' => '20'], ['s' => '1', 'r' => 'Bx12+', 'h' => '8', 'b' => 'wk', 'n' => '3'],
    ['s' => '2', 'r' => 'M100', 'h' => '23', 'b' => 'we', 'n' => '12'], ['s' => '3', 'r' => 'Q1', 'h' => '1', 'b' => 'wk', 'n' => '2']]);
check('bus lanes: quiet stops left out, fewest tickets first', array_column($lb['stops'], 2) === ['W 207 St/10 Av', 'Broadway/Isham St', 'Lefferts Blvd']);
check('bus lanes: kinds and hours add up', $lb['stops'][1][4] === 30 && $lb['stops'][1][6] === 5 && $lb['wk'][1][8] === 23 && $lb['we'][0][23] === 12);
check('bus lanes: routes per stop', $lb['routes'] === ['Bx12-SBS', 'M100', 'Q10', 'Q80'] && $lb['stops'][1][3] === [0, 1] && $lb['stops'][0][3] === [1]);
check('bus lanes: each route counts only its own cameras', array_keys($lb['by']) === ['Bx12-SBS', 'M100', 'Q10', 'Q80'] && $lb['by']['Q80']['stops'][0][5] === 40 && count($lb['by']['Bx12-SBS']['stops']) === 1 &&
    $lb['by']['Bx12-SBS']['stops'][0][4] === 10 && $lb['by']['Bx12-SBS']['wk'][0][8] === 3 &&
    array_column($lb['by']['M100']['stops'], 4) === [0, 20] && $lb['by']['M100']['wk'][1][8] === 20);

// ---- Bus camera sightings: the newest month waits for its tickets; outcomes and repeat cars per route ----
check('bus lanes: a month without its tickets yet is left out', mta_buslanes_ready_to('2026-10-01', [['m' => '2026-07-01T00:00:00.000', 'n' => '165000'],
    ['m' => '2026-08-01T00:00:00.000', 'n' => '112000'], ['m' => '2026-09-01T00:00:00.000', 'n' => '6']]) === '2026-09-01');
check('bus lanes: a normal month stays', mta_buslanes_ready_to('2026-10-01', [['m' => '2026-08-01', 'n' => '112000'], ['m' => '2026-09-01', 'n' => '90000']]) === '2026-10-01');
$mo = mta_buslanes_more(
    [['r' => 'M15+', 'v' => 'VIOLATION ISSUED', 'n' => '30'], ['r' => 'M15+', 'v' => 'EXEMPT - EMERGENCY VEHICLE', 'n' => '5'],
     ['r' => 'Q10/Q80', 'v' => 'TECHNICAL ISSUE/OTHER', 'n' => '7'], ['r' => 'M15+', 'n' => '2']],
    [['r' => 'M15+', 'n' => '1', 'cars' => '10'], ['r' => 'M15+', 'n' => '12', 'cars' => '1'], ['r' => 'Q10/Q80', 'n' => '3', 'cars' => '2']],
    [['n' => '1', 'cars' => '8'], ['n' => '3', 'cars' => '3'], ['n' => '13', 'cars' => '1']]);
check('bus lanes: outcomes by route and citywide', $mo['by']['M15-SBS']['out']['issued'] === 30 && $mo['by']['M15-SBS']['out']['emerg'] === 5 &&
    $mo['by']['Q80']['out']['tech'] === 7 && $mo['by']['Q10']['out']['tech'] === 7 && array_sum($mo['all']['out']) === 42);
check('bus lanes: repeat cars per route', $mo['by']['M15-SBS']['cars'] === 11 && $mo['by']['M15-SBS']['tickets'] === 22 && $mo['by']['M15-SBS']['b'][3] === [1, 12] &&
    $mo['by']['M15-SBS']['top'] === 12 && $mo['by']['Q80']['b'][1] === [2, 6]);
check('bus lanes: repeat cars citywide use the citywide counts', $mo['all']['cars'] === 12 && $mo['all']['tickets'] === 30 && $mo['all']['b'] === [[8, 8], [3, 9], [0, 0], [1, 13]] && $mo['all']['top'] === 13);

/* ---------- subway crime and line extras ---------- */

$cr = [];
for ($t = strtotime('2024-10-01 UTC'); $t <= strtotime('2026-09-01 UTC'); $t = strtotime('+1 month', $t)) {
    $m = gmdate('Y-m-d', $t) . 'T00:00:00.000';
    $last = $m >= '2025-10';
    $cr[] = ['month' => $m, 'agency' => 'NYCT', 'felony_type' => 'Robbery', 'felony_count' => '10', 'crimes_per_million_riders' => '0.1'];
    $cr[] = ['month' => $m, 'agency' => 'NYCT', 'felony_type' => 'Grand Larceny', 'felony_count' => $last ? '20' : '10', 'crimes_per_million_riders' => $last ? '0.2' : '0.1'];
}
$cr[] = ['month' => '2026-05-01T00:00:00.000', 'agency' => 'LIRR', 'felony_type' => 'Robbery', 'felony_count' => '2', 'crimes_per_million_riders' => '0.5'];
$cs = mta_crime_summary($cr);
check('crime: last 12 months and the 12 before', $cs['latest'] === '2026-09' && $cs['from'] === '2025-10' &&
    $cs['agencies']['NYCT']['year']['n'] === 360 && $cs['agencies']['NYCT']['before']['n'] === 240);
check('crime: rate is felonies over riders', $cs['agencies']['NYCT']['year']['rate'] == 0.3 && $cs['agencies']['NYCT']['before']['rate'] == 0.2);
check('crime: by type, biggest first', array_keys($cs['agencies']['NYCT']['year']['t']) === ['Grand Larceny', 'Robbery'] && $cs['agencies']['NYCT']['year']['t']['Grand Larceny'] === 240);
check('crime: full calendar years only', array_keys($cs['years']) === [2024, 2025] && $cs['years'][2025]['n'] === 9 * 20 + 3 * 30);
check('crime: railroads kept apart', $cs['agencies']['LIRR']['year']['n'] === 2 && $cs['agencies']['LIRR']['before']['n'] === 0);
check('crime: no rows, no summary', mta_crime_summary([]) === null);
check('crime: change wording', mta_crime_change_text(7.9) === '7.9% more than the 12 months before' && mta_crime_change_text(-3.1) === '3.1% fewer than the 12 months before');
update_option(MTA_CRIME_OPTION, ['updated' => gmdate('c')] + $cs);
$cf = mta_crime_faq(mta_crime_facts());
check('crime: FAQ answers use the numbers', count($cf) === 4 && strpos($cf[0][1], '360 major felonies') !== false && strpos($cf[1][1], 'in 2024') !== false &&
    strpos($cf[2][1], 'Grand larceny (theft over $1,000): 240') === 0);
check('lines: weekdays and weekend days', mta_lines_day_counts('2026-02', '2026-02') === [20, 8] && array_sum(mta_lines_day_counts('2025-10', '2026-09')) === 365);
check('lines: seconds wording', mta_lines_secs(0.75) === '45 sec' && mta_lines_secs(65 / 60) === '1 min 5 sec' && mta_lines_secs(2) === '2 min' && mta_lines_mmss(125 / 60) === '2:05' && mta_lines_mmss(-2 / 60) === '-0:02');

$ns = mta_crime_nypd_summary(
    [['station_name' => 'A', 'latitude' => '40.7500', 'longitude' => '-73.9900', 'n' => '5'],
     ['station_name' => 'A2', 'latitude' => '40.7510', 'longitude' => '-73.9900', 'n' => '2'],   // about 110 m away: same station
     ['station_name' => 'FAR', 'latitude' => '40.8000', 'longitude' => '-73.9900', 'n' => '3'],  // 5.5 km away: unmatched
     ['station_name' => 'B', 'latitude' => '40.6500', 'longitude' => '-73.9500', 'n' => '9']],
    [['t' => '02:15:00', 'n' => '4'], ['t' => '02:40:00', 'n' => '1'], ['t' => '17:05:00', 'n' => '6']],
    [['id' => '1', 'lat' => 40.7501, 'lon' => -73.9901, 'hours' => array_fill(0, 24, 10)], ['id' => '2', 'lat' => 40.6501, 'lon' => -73.9501, 'hours' => array_fill(0, 24, 1)]]);
check('nypd: stations matched within 400 m', $ns['stations'] === ['2' => 9, '1' => 7] && $ns['unmatched'] === 3 && $ns['total'] === 19);
check('nypd: felonies and riders by hour', $ns['hours'][2] === 5 && $ns['hours'][17] === 6 && array_sum($ns['hours']) === 11 && $ns['riders'][5] === 11);
check('crime: felonies a day', mta_crime_per_day(2143, 12) === 6 && mta_crime_per_day(1, 12) === 1);

$nm = mta_crime_nypd_more([['d' => '0', 'n' => '3'], ['d' => '5', 'n' => '7'], ['d' => '5', 'n' => '1']], [['b' => 'BROOKLYN', 'n' => '4'], ['b' => 'MANHATTAN', 'n' => '9'], ['b' => '(null)', 'n' => '2']],
    [['s' => 'M', 'a' => '25-44', 'n' => '5'], ['s' => 'F', 'a' => '65+', 'n' => '2'], ['s' => 'D', 'a' => 'UNKNOWN', 'n' => '6']], [['o' => 'FRAUDS', 'n' => '2'], ['o' => 'DANGEROUS DRUGS', 'n' => '8']]);
check('nypd more: days, boroughs, victims, misdemeanors', $nm['dow'] === [3, 0, 0, 0, 0, 8, 0] && array_keys($nm['boro']) === ['Manhattan', 'Brooklyn'] &&
    $nm['victims']['sex'] === ['men' => 5, 'women' => 2, 'other' => 6] && $nm['victims']['age']['25 – 44'] === 5 && $nm['victims']['age_unknown'] === 6 && array_key_first($nm['misd']) === 'DANGEROUS DRUGS');
$ms = mta_crime_monthly_summary([['month' => '2026-09-01T00:00:00.000', 'measure' => 'Assault', 'total' => '4.0'], ['month' => '2025-09-01T00:00:00.000', 'measure' => 'Assault', 'total' => '2'],
    ['month' => '2025-10-01T00:00:00.000', 'measure' => 'Harassment', 'total' => '5'], ['month' => '2024-03-01T00:00:00.000', 'measure' => 'Assault', 'total' => '1']], 'measure');
check('crime: monthly summary for staff attacks and policing', $ms['latest'] === '2026-09' && $ms['year'] === ['Harassment' => 5, 'Assault' => 4] && $ms['before'] === ['Assault' => 2] &&
    $ms['years'] === [2024 => 1, 2025 => 7]);
check('crime: kinds of felony in words', mta_crime_type_words(['109' => 20, '106' => 1, '105' => 4]) === '20 thefts, 1 assault, 4 robberies');
check('crime: misdemeanor names', mta_crime_misd_name('OTHER OFFENSES RELATED TO THEFT') === 'Fare evasion and selling swipes' && mta_crime_misd_name('NEW THING') === 'New thing');
check('crime: monthly felonies kept', count($cs['monthly']) === 24 && end($cs['monthly']) === 30);

/* ---------- more data: amenities, art, line extras, railroads ---------- */

check('more: line codes', mta_more_line_code('J') === 'JZ' && mta_more_line_code('GS') === 'S 42nd' && mta_more_line_code('H') === 'S Rock' && mta_more_line_code('A') === 'A' && mta_more_line_code('SI') === null);
check('more: station names', mta_more_station_name('207TH STREET-8AV') === '207th Street-8 Av' && mta_more_station_name('LEFFERTS BOULEVARD-OZONE PARK') === 'Lefferts Boulevard-Ozone Park');
$art = mta_more_art([['station_name' => 'Canal St', 'line' => 'A,C,E', 'artist' => 'X', 'art_title' => 'T1', 'art_image_link' => ['url' => 'https://mta.info/a']],
                     ['station_name' => 'Canal St', 'line' => '6', 'artist' => 'Y', 'art_title' => 'T2'],
                     ['station_name' => 'Nowhere', 'line' => '1', 'artist' => 'Z', 'art_title' => 'T3'],
                     ['station_name' => 'Solo Stop', 'line' => 'Q', 'artist' => 'W', 'art_title' => 'T4', 'art_image_link' => ['url' => 'javascript:alert(1)']]],
    ['canalst' => [['10', ['A', 'C', 'E']], ['20', ['4', '6']]], 'solostop' => [['30', ['B']]]]);
check('more: art matched by name and line', $art['art']['10'][0]['title'] === 'T1' && $art['art']['10'][0]['url'] === 'https://mta.info/a' && $art['art']['20'][0]['title'] === 'T2' &&
    $art['art_unmatched'] === 1 && $art['art']['30'][0]['url'] === '');
$rt = mta_more_runtimes(
    [['line' => 'A', 'direction' => 'N', 'o' => 'FAR', 'd' => 'END', 's' => '30', 'distance' => '20', 'a' => '80', 'sch' => '78', 'n' => '10'],
     ['line' => 'A', 'direction' => 'N', 'o' => 'FAR', 'd' => 'END', 's' => '30', 'distance' => '20', 'a' => '90', 'sch' => '78', 'n' => '30'],
     ['line' => 'A', 'direction' => 'N', 'o' => 'OTHER', 'd' => 'END', 's' => '25', 'distance' => '15', 'a' => '60', 'sch' => '58', 'n' => '5'],
     ['line' => 'GS', 'direction' => 'S', 'o' => 'GC', 'd' => 'TS', 's' => '2', 'distance' => '0.4', 'a' => '2', 'n' => '100']],
    [['line' => 'A', 'direction' => 'N', 'o' => 'FAR', 'd' => 'END', 's' => '30', 'distance' => '20', 'a' => '85', 'sch' => '80', 'n' => '40']]);
check('more: running times use the busiest pattern, weighted by trains', $rt['A']['N']['actual'] === 87.5 && $rt['A']['N']['trains'] === 40 && $rt['A']['N']['then'] === 85.0 &&
    $rt['A']['N']['from'] === 'Far' && $rt['S 42nd']['S']['scheduled'] === null && $rt['S 42nd']['S']['then'] === null);
$ro = mta_more_rail_otp(['Babylon' => ['2026-09' => ['otp' => 90, 'am' => 85, 'pm' => null], '2025-01' => ['otp' => 80, 'am' => null, 'pm' => null]],
                         'Hudson' => ['2026-09' => ['otp' => 96, 'am' => null, 'pm' => null]],
                         'System Total' => ['2026-09' => ['otp' => 93, 'am' => 91, 'pm' => 92], '2026-08' => ['otp' => 94, 'am' => 91, 'pm' => 92]]], '2025-10', 'System Total');
check('more: railroad on-time by branch and system', array_keys($ro['branches']) === ['Hudson', 'Babylon'] && $ro['branches']['Babylon']['before'] === 80.0 &&
    $ro['system']['otp'] === 93.5 && $ro['series'] === ['2026-08' => 94.0, '2026-09' => 93.0]);
$ro2 = mta_more_rail_otp(['Harlem' => ['2026-09' => ['otp' => 98, 'am' => null, 'pm' => null]], 'Hudson' => ['2026-09' => ['otp' => 96, 'am' => null, 'pm' => null]]], '2025-10', null);
check('more: railroad without a system row averages the branches', $ro2['system']['otp'] === 97.0 && $ro2['series'] === ['2026-09' => 97.0]);
check('more: money wording', mta_more_money(8607697371) === '$8.6 billion' && mta_more_money(88165437) === '$88 million' && mta_more_money(98228) === '$98,228');

echo ($count - $failed) . " passed, $failed failed\n";
exit($failed ? 1 : 0);
