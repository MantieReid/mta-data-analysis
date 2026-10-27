<?php
/**
 * Calendar of daily ridership vs normal (holidays, storms, big events).
 * Same method as compute_calendar() in mta_ridership.py:
 * system riders per day (sum of each station's rounded daily total) / median of the
 * same weekday over the previous 26 weeks (at least 4). Built from the saved history.
 */

if (!defined('ABSPATH')) {
    exit;
}

const MTA_RIDERSHIP_CALENDAR_HOOK   = 'mta_ridership_calendar_step';
const MTA_RIDERSHIP_CALENDAR_OPTION = 'mta_ridership_calendar';

/** Storms and events can't be worked out from dates, so they're listed by hand. */
const MTA_RIDERSHIP_EVENTS = [
    '2026-01-25' => 'Snowstorm',
    '2026-01-26' => 'Snowstorm (cleanup day)',
    '2026-02-22' => 'Winter storm',
    '2026-02-23' => 'Winter storm',
    '2026-09-22' => 'UN General Assembly week',
    '2026-09-23' => 'UN General Assembly week',
    '2026-09-24' => 'UN General Assembly week',
];

add_action(MTA_RIDERSHIP_CALENDAR_HOOK, 'mta_ridership_calendar_step');

add_action('init', function () {
    if (!get_option(MTA_RIDERSHIP_CALENDAR_OPTION) && get_option(MTA_RIDERSHIP_HISTORY)) {
        if (!wp_next_scheduled(MTA_RIDERSHIP_CALENDAR_HOOK)) {
            wp_schedule_single_event(time() + 10, MTA_RIDERSHIP_CALENDAR_HOOK);
        }
    }
});

/** n-th (1-based) weekday of a month; $n = -1 for the last one. $wd: 1 = Mon ... 7 = Sun. */
function mta_ridership_nth_weekday($y, $m, $wd, $n)
{
    if ($n > 0) {
        $first = (int) gmdate('N', gmmktime(0, 0, 0, $m, 1, $y));
        $day = 1 + (($wd - $first + 7) % 7) + 7 * ($n - 1);
    } else {
        $last = (int) gmdate('t', gmmktime(0, 0, 0, $m, 1, $y));
        $lastwd = (int) gmdate('N', gmmktime(0, 0, 0, $m, $last, $y));
        $day = $last - (($lastwd - $wd + 7) % 7);
    }
    return sprintf('%04d-%02d-%02d', $y, $m, $day);
}

/** US holidays and recurring NYC events for a year, as date => label. */
function mta_ridership_holidays($y)
{
    $h = [
        "$y-01-01" => "New Year's Day",
        mta_ridership_nth_weekday($y, 1, 1, 3) => 'MLK Day',
        mta_ridership_nth_weekday($y, 2, 1, 3) => "Presidents' Day",
        mta_ridership_nth_weekday($y, 5, 1, -1) => 'Memorial Day',
        "$y-06-19" => 'Juneteenth',
        "$y-07-04" => 'July 4th',
        mta_ridership_nth_weekday($y, 9, 1, 1) => 'Labor Day',
        mta_ridership_nth_weekday($y, 10, 1, 2) => "Columbus / Indigenous Peoples' Day",
        mta_ridership_nth_weekday($y, 11, 7, 1) => 'NYC Marathon',
        "$y-11-11" => 'Veterans Day',
        "$y-12-24" => 'Christmas Eve',
        "$y-12-25" => 'Christmas',
        "$y-12-31" => "New Year's Eve",
    ];
    $thanks = mta_ridership_nth_weekday($y, 11, 4, 4);
    $h[$thanks] = 'Thanksgiving';
    $h[mta_ridership_next_day($thanks)] = 'Day after Thanksgiving';
    $july4 = (int) gmdate('N', gmmktime(0, 0, 0, 7, 4, $y));
    if ($july4 === 6) {
        $h["$y-07-03"] = 'July 4th (observed)';
    } elseif ($july4 === 7) {
        $h["$y-07-05"] = 'July 4th (observed)';
    }
    return $h;
}

function mta_ridership_calendar_step()
{
    try {
        $h = mta_ridership_history_load();
        if (!$h) {
            return;
        }
        $sys = array_fill(0, $h['ndays'], 0);
        $seen = array_fill(0, $h['ndays'], false);
        foreach ($h['st'] as $series) {
            foreach ($series as $i => $v) {
                $sys[$i] += $v;
                $seen[$i] = true;
            }
        }
        $labels = MTA_RIDERSHIP_EVENTS;
        $first_y = (int) substr($h['start'], 0, 4);
        $last_y = (int) substr(mta_ridership_history_last_day($h), 0, 4);
        for ($y = $first_y; $y <= $last_y; $y++) {
            $labels += mta_ridership_holidays($y); // hand-listed events win over holidays
        }
        $days = [];
        for ($i = 0; $i < $h['ndays']; $i++) {
            if (!$seen[$i]) {
                continue;
            }
            $b = [];
            for ($k = 1; $k <= 26; $k++) {
                if ($i - 7 * $k >= 0 && $seen[$i - 7 * $k]) {
                    $b[] = $sys[$i - 7 * $k];
                }
            }
            $d = mta_ridership_next_day($h['start'], $i);
            $days[] = ['d' => $d, 'r' => $sys[$i],
                       'x' => count($b) >= 4 ? round($sys[$i] / mta_ridership_median($b), 3) : null,
                       'l' => $labels[$d] ?? null];
        }
        update_option(MTA_RIDERSHIP_CALENDAR_OPTION, ['updated' => gmdate('c'), 'days' => $days], false);
        mta_ridership_clear_page_cache();
    } catch (Throwable $e) {
        update_option('mta_ridership_calendar_error', gmdate('c') . ' ' . $e->getMessage(), false);
    }
}
