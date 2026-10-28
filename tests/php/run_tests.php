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
mta_ridership_run_step('lines');
check('an overlapping run is postponed, not run', wp_next_scheduled('mta_ridership_lines_step') > time() && get_option(MTA_RIDERSHIP_STATUS_OPTION)['lines']['last_attempt'] === $st['last_attempt']);
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

echo ($count - $failed) . " passed, $failed failed\n";
exit($failed ? 1 : 0);
