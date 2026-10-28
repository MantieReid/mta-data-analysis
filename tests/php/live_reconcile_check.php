<?php
/**
 * Optional live check (not run in CI, needs internet): runs the 30-day re-check against the real MTA API
 * on a COPY of a site's stored history and reports how many station-days are new, revised or removed.
 * It changes nothing on the site.
 *
 *   wp option get mta_ridership_history > /tmp/history.txt     # on the WordPress host
 *   php tests/php/live_reconcile_check.php /tmp/history.txt [days]
 */
require __DIR__ . '/bootstrap.php';

// Real HTTP for this script only.
function wp_remote_get($url, $args = [])
{
    $ctx = stream_context_create(['http' => ['timeout' => $args['timeout'] ?? 180, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return new WP_Error('http', 'request failed or timed out');
    }
    preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $m);
    return ['code' => (int) ($m[1] ?? 0), 'body' => $body];
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function add_query_arg($params, $url) { return $url . '?' . implode('&', array_map(function ($k, $v) { return "$k=$v"; }, array_keys($params), $params)); }
class WP_Error { private $m; public function __construct($c, $m) { $this->m = $m; } public function get_error_message() { return $this->m; } }

update_option(MTA_RIDERSHIP_HISTORY, trim(file_get_contents($argv[1])));
$h = mta_ridership_history_load();
$days = (int) ($argv[2] ?? 30);
$latest = mta_ridership_history_last_day($h);
$from = mta_ridership_next_day($latest, 1 - $days);
$t = microtime(true);
$total = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0];
$changed = [];
for ($lo = $from; $lo <= $latest; $lo = mta_ridership_next_day($hi)) {
    $hi = min(mta_ridership_next_day($lo, MTA_RIDERSHIP_CHUNK_DAYS - 1), $latest);
    $before = $h;
    [$h, $c] = mta_ridership_history_apply($h, mta_ridership_history_fetch($lo, $hi), $lo, $hi);
    foreach ($c as $k => $v) {
        $total[$k] += $v;
    }
    foreach ($h['st'] as $sid => $s) {
        foreach ($s as $i => $v) {
            if (isset($before['st'][$sid][$i]) && $before['st'][$sid][$i] !== $v && count($changed) < 5) {
                $changed[] = "$sid " . mta_ridership_next_day($h['start'], $i) . ": {$before['st'][$sid][$i]} -> $v";
            }
        }
    }
}
printf("History %s .. %s, re-checked %d days from %s in %.1fs\n", $h['start'], $latest, $days, $from, microtime(true) - $t);
printf("%d new, %d changed, %d unchanged, %d removed station-days\n", $total['new'], $total['changed'], $total['unchanged'], $total['removed']);
foreach ($changed as $line) {
    echo "  revised: $line\n";
}
