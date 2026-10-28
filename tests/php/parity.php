<?php
/**
 * Prints the plugin's results for tests/fixtures/trends.json as JSON, so tests/test_parity.py can check
 * that the PHP plugin and the Python script agree: unusual days, holidays, rounding, and the history merge.
 */
require __DIR__ . '/bootstrap.php';

$fx = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/trends.json'), true);
$h = ['ndays' => $fx['ndays'], 'st' => array_map(function ($s) { return array_combine(array_map('intval', array_keys($s)), $s); }, $fx['st'])];
$sys = array_combine(array_map('intval', array_keys($fx['sys_ratio'])), $fx['sys_ratio']);
$events = function ($busy) use ($h, $sys) {
    return array_map(function ($e) {
        return ['id' => (string) $e['id'], 'first' => $e['first'], 'last' => $e['last'], 'days' => $e['days'], 'peak' => $e['peak'],
                'ratio' => round($e['ratio'], 6), 'riders' => $e['riders'], 'baseline' => $e['baseline']];
    }, mta_ridership_find_events($h, [], $sys, $busy));
};
$holidays = [];
foreach ($fx['holiday_years'] as $y) {
    $holidays[$y] = mta_ridership_holidays($y);
}
$merge_old = ['start' => '2026-10-01', 'ndays' => 3, 'names' => [], 'st' => ['1' => [0 => 100, 1 => 110, 2 => 120], '2' => [1 => 50]]];
$merge_new = [
    ['station_complex_id' => '1', 'station_complex' => '1', 'borough' => 'M', 'day' => '2026-10-02', 'riders' => '115'],
    ['station_complex_id' => '1', 'station_complex' => '1', 'borough' => 'M', 'day' => '2026-10-03', 'riders' => '120'],
    ['station_complex_id' => '1', 'station_complex' => '1', 'borough' => 'M', 'day' => '2026-10-04', 'riders' => '130'],
];
echo json_encode([
    'busy' => $events(true),
    'quiet' => $events(false),
    'holidays' => $holidays,
    'round' => array_map(function ($c) { return round($c[0], $c[1]); }, $fx['round_cases']),
    'merge_counts' => mta_ridership_history_apply($merge_old, $merge_new, '2026-10-02', '2026-10-04')[1],
]);
