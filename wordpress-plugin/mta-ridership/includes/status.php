<?php
/**
 * Refresh reliability and monitoring for the daily data steps:
 * - one step at a time (a lock with an expiry, so a crashed run can't block forever),
 * - per-step status: last attempt, last success, duration, latest MTA data date, last error,
 * - per-dataset health (current / delayed / unavailable) that tells MTA publishing delays apart from
 *   our own failures, based on how often the MTA actually publishes each dataset,
 * - optional email alerts, at most one per problem per day,
 * - WP-CLI commands: status, run a step, and a resumable full history rebuild.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_RIDERSHIP_STATUS_OPTION = 'mta_ridership_status';
const MTA_RIDERSHIP_LOCK_OPTION   = 'mta_ridership_lock';
const MTA_RIDERSHIP_LOCK_SECONDS  = 1800; // a step taking longer than this is assumed dead

/**
 * The daily steps, in the order they chain. 'through' returns the latest MTA data date a step has
 * (Y-m-d); 'max_age' is how many days old that date may get before it counts as delayed, set from the
 * MTA's real publishing cadence: subway ridership and congestion weekly, about 1-2 weeks behind;
 * line and bus metrics monthly, about 3-4 weeks after the month ends.
 */
function mta_ridership_steps()
{
    $opt = function ($name, $key) {
        return function () use ($name, $key) {
            $o = get_option($name);
            return $o[$key] ?? null;
        };
    };
    $month_end = function ($name) {
        return function () use ($name) {
            $o = get_option($name);
            return empty($o['latest']) ? null : gmdate('Y-m-t', strtotime($o['latest'] . '-01 UTC'));
        };
    };
    $defaults = [
        'refresh'    => ['Subway ridership', 'mta_ridership_refresh', 'mta_ridership_daily_refresh', 'mta_ridership_last_error', $opt('mta_ridership_data', 'latest_day'), 21, true],
        'trends'     => ['Subway trends', 'mta_ridership_trends_step', 'mta_ridership_trends_step', 'mta_ridership_trends_error', $opt('mta_ridership_trends', 'latest_day'), 21, false],
        'extras'     => ['Subway map and fares', 'mta_ridership_extras_step', 'mta_ridership_extras_step', 'mta_ridership_extras_error', $opt('mta_ridership_extras', 'to'), 21, false],
        'congestion' => ['Congestion pricing', 'mta_congestion_step', 'mta_ridership_congestion_step', 'mta_ridership_congestion_error', $opt('mta_ridership_congestion', 'latest'), 21, true],
        'lines'      => ['Subway lines', 'mta_lines_step', 'mta_ridership_lines_step', 'mta_ridership_lines_error', $month_end('mta_ridership_lines'), 60, true],
        'buses'      => ['Bus routes', 'mta_buses_step', 'mta_ridership_buses_step', 'mta_ridership_buses_error', $month_end('mta_ridership_buses'), 60, true],
        'stations'   => ['Station report cards', 'mta_stations_step', 'mta_ridership_stations_step', 'mta_ridership_stations_error', $month_end('mta_ridership_stations'), 60, false],
    ];
    $custom = (array) get_option('mta_ridership_max_age', []); // per-step overrides, in days
    $steps = [];
    foreach ($defaults as $k => [$label, $fn, $hook, $err, $through, $age, $public]) {
        $steps[$k] = compact('label', 'fn', 'hook', 'err', 'through', 'public') + ['max_age' => (int) ($custom[$k] ?? $age)];
    }
    return apply_filters('mta_ridership_steps', $steps);
}

/** Take the shared lock. True if we got it; a lock older than MTA_RIDERSHIP_LOCK_SECONDS is taken over. */
function mta_ridership_lock($owner)
{
    $now = time();
    if (add_option(MTA_RIDERSHIP_LOCK_OPTION, ['owner' => $owner, 'until' => $now + MTA_RIDERSHIP_LOCK_SECONDS], '', 'no')) {
        return true; // add_option inserts only if the row doesn't exist, so two runs can't both get here
    }
    $l = get_option(MTA_RIDERSHIP_LOCK_OPTION);
    if (is_array($l) && ($l['until'] ?? 0) > $now) {
        return false;
    }
    delete_option(MTA_RIDERSHIP_LOCK_OPTION); // expired: the previous run died
    return (bool) add_option(MTA_RIDERSHIP_LOCK_OPTION, ['owner' => $owner, 'until' => $now + MTA_RIDERSHIP_LOCK_SECONDS], '', 'no');
}

function mta_ridership_unlock()
{
    delete_option(MTA_RIDERSHIP_LOCK_OPTION);
}

/**
 * Run one data step under the lock and record how it went. If another step is running, try again in
 * 5 minutes instead of overlapping it. Steps report failure through their own error option.
 */
function mta_ridership_run_step($key)
{
    $s = mta_ridership_steps()[$key];
    if (!mta_ridership_lock($key)) {
        wp_schedule_single_event(time() + 300, $s['hook']);
        error_log("[mta-ridership] $key: another step is running, retrying in 5 minutes");
        return 'postponed';
    }
    $t0 = microtime(true);
    $started = time();
    try {
        call_user_func($s['fn']);
    } catch (Throwable $e) { // steps catch their own errors; this is a last resort
        update_option($s['err'], gmdate('c') . ' ' . $e->getMessage(), false);
    } finally {
        mta_ridership_unlock();
    }
    $err = (string) get_option($s['err'], '');
    $failed = $err !== '' && strtotime(substr($err, 0, 25)) >= $started;
    mta_ridership_record($key, !$failed, $failed ? $err : '', round(microtime(true) - $t0, 1));
    return $failed ? 'failed' : 'ok';
}

/** Store the result of a run and send an alert if the step keeps failing. */
function mta_ridership_record($key, $ok, $error, $seconds)
{
    $all = (array) get_option(MTA_RIDERSHIP_STATUS_OPTION, []);
    $st = $all[$key] ?? ['failures' => 0];
    $s = mta_ridership_steps()[$key];
    $st['last_attempt'] = gmdate('c');
    $st['seconds'] = $seconds;
    $st['through'] = call_user_func($s['through']);
    if ($ok) {
        $st['last_success'] = $st['last_attempt'];
        $st['failures'] = 0;
        $st['error'] = '';
        if ($key === 'trends') {
            $st['counts'] = get_option('mta_ridership_history_counts');
        }
    } else {
        $st['failures'] = ($st['failures'] ?? 0) + 1;
        $st['error'] = mb_substr(preg_replace('/^\S+ /', '', $error), 0, 300);
    }
    $all[$key] = $st;
    update_option(MTA_RIDERSHIP_STATUS_OPTION, $all, false);
    error_log(sprintf('[mta-ridership] %s %s in %ss, data through %s%s', $key, $ok ? 'ok' : 'FAILED', $seconds,
        $st['through'] ?: '-', $ok ? '' : ' (' . $st['failures'] . ' in a row): ' . $st['error']));
    if (!$ok && $st['failures'] >= 2) {
        mta_ridership_alert("step-$key", "MTA data: the {$s['label']} refresh is failing",
            "The {$s['label']} refresh has failed {$st['failures']} times in a row. It retries automatically.\n\nLast error: {$st['error']}");
    }
    if ($key === 'refresh') { // once a day: check every dataset for staleness
        foreach (mta_ridership_health() as $k => $h) {
            if ($h['state'] === 'delayed' && $h['reason'] === 'source') {
                mta_ridership_alert("stale-$k", "MTA data: {$h['label']} is " . $h['age'] . ' days old',
                    "The latest {$h['label']} data is from {$h['through']}, older than the usual {$h['max_age']} days. " .
                    'Our refreshes are working, so the MTA has most likely not published newer data yet.');
            }
        }
    }
}

/**
 * Health of each step's data: 'current', 'delayed' (data older than its usual cadence) or 'unavailable'
 * (no data yet). 'reason' says whether a delay comes from the MTA ('source') or our refresh ('refresh').
 */
function mta_ridership_health($now = null)
{
    $now = $now ?? time();
    $status = (array) get_option(MTA_RIDERSHIP_STATUS_OPTION, []);
    $out = [];
    foreach (mta_ridership_steps() as $k => $s) {
        $st = $status[$k] ?? [];
        $through = call_user_func($s['through']);
        $age = $through ? (int) floor(($now - strtotime("$through UTC")) / DAY_IN_SECONDS) : null;
        $failing = ($st['failures'] ?? 0) >= 2;
        if (!$through) {
            $state = 'unavailable';
        } elseif ($age > $s['max_age'] || $failing) {
            $state = 'delayed';
        } else {
            $state = 'current';
        }
        $out[$k] = ['label' => $s['label'], 'public' => $s['public'], 'state' => $state, 'reason' => $failing ? 'refresh' : 'source',
                    'through' => $through, 'age' => $age, 'max_age' => $s['max_age'],
                    'last_success' => $st['last_success'] ?? null, 'last_attempt' => $st['last_attempt'] ?? null,
                    'seconds' => $st['seconds'] ?? null, 'failures' => $st['failures'] ?? 0, 'error' => $st['error'] ?? ''];
    }
    return $out;
}

/** Email the configured address about a problem, at most once a day per problem. Off when no address is set. */
function mta_ridership_alert($issue, $subject, $body)
{
    $to = get_option('mta_ridership_alert_email', '');
    if (!$to || !is_email($to)) {
        return false;
    }
    $sent = (array) get_option('mta_ridership_alerts_sent', []);
    if (($sent[$issue] ?? 0) > time() - DAY_IN_SECONDS) {
        return false;
    }
    $sent[$issue] = time();
    update_option('mta_ridership_alerts_sent', $sent, false);
    return wp_mail($to, $subject, $body . "\n\nStatus: " . admin_url('options-general.php?page=mta-ridership'));
}

/* ---------- WP-CLI: wp mta-ridership status | run <step> | rebuild-history ---------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('mta-ridership', new class {
        /**
         * Show each data step's health and last run.
         */
        public function status()
        {
            $rows = [];
            foreach (mta_ridership_health() as $k => $h) {
                $rows[] = ['step' => $k, 'state' => $h['state'] . ($h['state'] === 'delayed' ? " ({$h['reason']})" : ''),
                           'data through' => $h['through'] ?: '-', 'last success' => $h['last_success'] ?: '-',
                           'seconds' => $h['seconds'] ?? '-', 'failures' => $h['failures'], 'error' => $h['error']];
            }
            WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0]));
            $c = get_option('mta_ridership_history_counts');
            if ($c) {
                WP_CLI::log(sprintf('History check %s (last %d days): %d new, %d changed, %d unchanged, %d removed station-days.',
                    $c['at'], $c['reconcile_days'], $c['new'], $c['changed'], $c['unchanged'], $c['removed']));
            }
        }

        /**
         * Run one step now, under the lock, and record the result.
         *
         * <step>
         * : refresh, trends, extras, congestion, lines or buses.
         */
        public function run($args)
        {
            if (!isset(mta_ridership_steps()[$args[0]])) {
                WP_CLI::error('Unknown step. Use: ' . implode(', ', array_keys(mta_ridership_steps())));
            }
            if (mta_ridership_run_step($args[0]) === 'postponed') {
                WP_CLI::warning('Another data step is running; this one is scheduled to try again in 5 minutes.');
                return;
            }
            $h = mta_ridership_health()[$args[0]];
            $h['error'] ? WP_CLI::warning($h['error']) : WP_CLI::success("{$args[0]} done in {$h['seconds']}s, data through {$h['through']}");
        }

        /**
         * Download the subway history again in batches and replace what's stored, for example after
         * the MTA revises older data. Safe to stop and run again: it continues where it left off.
         *
         * [--from=<date>]
         * : First day to rebuild (default: the start of the history).
         *
         * [--batch-days=<n>]
         * : Days per API call (default 45).
         *
         * [--restart]
         * : Ignore saved progress and start again from --from.
         */
        public function rebuild_history($args, $assoc)
        {
            $data = get_option(MTA_RIDERSHIP_OPTION);
            $h = mta_ridership_history_load();
            if (!$data || !$h) {
                WP_CLI::error('No history yet: let the daily refresh build it first.');
            }
            if (!mta_ridership_lock('rebuild-history')) {
                WP_CLI::error('A data step is running; try again in a few minutes.');
            }
            try {
                $batch = max(1, (int) ($assoc['batch-days'] ?? 45));
                $progress = get_option('mta_ridership_rebuild_progress');
                $from = !empty($assoc['restart']) || !$progress ? ($assoc['from'] ?? $h['start']) : $progress;
                $latest = $data['latest_day'];
                $total = ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'removed' => 0];
                for ($lo = $from; $lo <= $latest; $lo = mta_ridership_next_day($hi)) {
                    $hi = min(mta_ridership_next_day($lo, $batch - 1), $latest);
                    [$h, $c] = mta_ridership_history_apply($h, mta_ridership_history_fetch($lo, $hi), $lo, $hi);
                    mta_ridership_history_save($h);
                    update_option('mta_ridership_rebuild_progress', mta_ridership_next_day($hi), false); // resume point
                    foreach ($c as $k => $v) {
                        $total[$k] += $v;
                    }
                    WP_CLI::log("$lo .. $hi: {$c['new']} new, {$c['changed']} changed, {$c['unchanged']} unchanged, {$c['removed']} removed");
                }
                delete_option('mta_ridership_rebuild_progress');
                update_option('mta_ridership_trends_force', 1, false); // the next trends run rebuilds even if nothing looks new
                WP_CLI::success("History rebuilt: {$total['changed']} changed, {$total['new']} new, {$total['removed']} removed. " .
                    'Run "wp mta-ridership run trends" to update the trends.');
            } finally {
                mta_ridership_unlock();
            }
        }
    });
}
