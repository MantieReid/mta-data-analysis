"""Unit tests for the calculations in mta_ridership.py. No network: everything runs on small fixed data.
Run: python -m pytest tests"""
import json
import os
import sys
from datetime import date

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import mta_ridership as m  # noqa: E402

FIXTURE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures", "trends.json")


@pytest.fixture(scope="module")
def fx():
    with open(FIXTURE, encoding="utf-8") as f:
        d = json.load(f)
    d["st"] = {sid: {int(i): v for i, v in s.items()} for sid, s in d["st"].items()}
    d["sys_ratio"] = {int(i): v for i, v in d["sys_ratio"].items()}
    return d


# --- rounding, dates, seasons ---

@pytest.mark.parametrize("x, nd, want", [(2.5, 0, 3), (-2.5, 0, -3), (0.125, 2, 0.13), (40.755745, 5, 40.75575), (1.005, 2, 1.01)])
def test_php_round_rounds_half_away_from_zero(x, nd, want):
    assert m.php_round(x, nd) == want


def test_week_starts_on_monday():
    assert m.week_start(date(2026, 10, 28)) == date(2026, 10, 26)  # Wednesday -> Monday
    assert m.week_start(date(2026, 10, 26)) == date(2026, 10, 26)


def test_december_belongs_to_next_winter():
    assert m.season_label(date(2025, 12, 1)) == "winter 2025-26"
    assert m.season_label(date(2026, 2, 28)) == "winter 2025-26"
    assert m.season_label(date(2026, 3, 1)) == "spring 2026"


def test_week_season_is_decided_by_its_thursday():
    # Week of Mon Feb 26, 2024: Thursday is Feb 29 (winter); week of Mon May 27, 2024: Thursday May 30 (spring).
    assert m.season_of(date(2024, 2, 26)) == "winter 2023-24"
    assert m.season_of(date(2024, 5, 27)) == "spring 2024"


def test_days_in_month_handles_leap_years():
    assert m.days_in_month(2024, 2) == 29 and m.days_in_month(2026, 2) == 28 and m.days_in_month(2026, 12) == 31


# --- baseline and unusual days ---

def test_baseline_needs_ten_weeks_and_takes_the_median():
    s = {i: 100 for i in range(0, 70)}
    assert m.baseline(s, 70) == 100           # 10 earlier same-weekday days available
    assert m.baseline(s, 63) is None          # only 9
    s[63] = 1000                              # one odd week doesn't move the median
    assert m.baseline(s, 70) == 100


def test_busy_events(fx):
    ev = m.find_events(fx["st"], fx["ndays"], fx["sys_ratio"], busy=True)
    got = sorted((e["id"], e["first"], e["last"], e["days"]) for e in ev)
    # A: days 100-101 grouped into one event, plus day 130; D is too small; the holiday is not an event.
    assert got == [("A", 100, 101, 2), ("A", 130, 130, 1)]
    a = [e for e in ev if e["first"] == 100][0]
    assert a["peak"] == 100 and a["ratio"] == 4.0 and a["baseline"] == 5000


def test_quiet_events_skip_holidays_and_closures(fx):
    ev = m.find_events(fx["st"], fx["ndays"], fx["sys_ratio"], busy=False)
    # B's 20% day counts; the system-wide holiday (day 112) doesn't; E's 0-rider day is a closure, not "quiet".
    assert sorted((e["id"], e["first"]) for e in ev) == [("B", 90)]


# --- holidays ---

def test_holidays_known_dates():
    h = m.holidays(2025)
    assert h["2025-11-27"] == "Thanksgiving" and h["2025-11-28"] == "Day after Thanksgiving"
    assert h["2025-11-02"] == "NYC Marathon"          # first Sunday of November
    assert h["2025-05-26"] == "Memorial Day"          # last Monday of May
    assert m.holidays(2026)["2026-07-03"] == "July 4th (observed)"  # July 4, 2026 is a Saturday
    assert m.holidays(2027)["2027-07-05"] == "July 4th (observed)"  # July 4, 2027 is a Sunday


# --- re-checking recent days (merge_daily) ---

def row(sid, day, riders):
    return {"station_complex_id": sid, "station_complex": sid, "borough": "M", "day": day, "riders": float(riders)}


OLD = [row("1", "2026-10-01", 100), row("1", "2026-10-02", 110), row("2", "2026-10-02", 50), row("1", "2026-10-03", 120)]


def test_merge_is_idempotent():
    window = [r for r in OLD if r["day"] >= "2026-10-02"]
    rows, counts = m.merge_daily(OLD, window, "2026-10-02")
    assert sorted(map(str, rows)) == sorted(map(str, OLD))
    assert counts == {"new": 0, "changed": 0, "unchanged": 3, "removed": 0}
    rows2, counts2 = m.merge_daily(rows, window, "2026-10-02")  # running again changes nothing
    assert rows2 == rows and counts2 == counts


def test_merge_applies_revisions_new_days_and_removals():
    new = [row("1", "2026-10-02", 115),   # revised
           row("1", "2026-10-03", 120),   # unchanged
           row("1", "2026-10-04", 130)]   # new day; station 2's Oct 2 row was removed by the MTA
    rows, counts = m.merge_daily(OLD, new, "2026-10-02")
    assert counts == {"new": 1, "changed": 1, "unchanged": 1, "removed": 1}
    by = {(r["station_complex_id"], r["day"]): r["riders"] for r in rows}
    assert by[("1", "2026-10-01")] == 100      # outside the window: untouched
    assert by[("1", "2026-10-02")] == 115 and ("2", "2026-10-02") not in by
    assert sum(r["riders"] for r in rows) == 100 + 115 + 120 + 130  # no double counting


def test_a_revised_day_changes_the_trend(fx):
    """A historical revision must flow through to the unusual-days result."""
    st = {sid: dict(s) for sid, s in fx["st"].items()}
    assert ("C", 120) not in {(e["id"], e["first"]) for e in m.find_events(st, fx["ndays"], fx["sys_ratio"], True)}
    st["C"][120] = 12000  # the MTA revises day 120 for station C
    assert ("C", 120) in {(e["id"], e["first"]) for e in m.find_events(st, fx["ndays"], fx["sys_ratio"], True)}
