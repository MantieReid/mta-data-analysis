"""Writes tests/fixtures/trends.json: small, made-up station histories that exercise every trend rule.
Both the Python tests and the PHP tests read it, so the two implementations are checked on the same input.
Run again only if you change the scenario: python tests/make_fixture.py"""
import json
import os

N = 140  # days 0..139; events can only be found from day 70 on (10 weeks of baseline needed)
HOLIDAY = 112  # every station (and the system) at 40%: must NOT be flagged


def series(level, changes):
    return {i: changes.get(i, round(level * (0.4 if i == HOLIDAY else 1.0))) for i in range(N)}


fixture = {
    "ndays": N,
    "st": {
        "A": series(5000, {100: 20000, 101: 16000, 130: 21000}),  # spike run of 2 days (100-101), another at 130
        "B": series(4000, {90: 800}),                             # quiet day (20% of normal)
        "C": series(3000, {}),                                    # only the holiday dip
        "D": series(1000, {100: 6000}),                           # spike, but too small a station to count
        "E": series(3500, {95: 0}),                               # zero riders: closed, not "quiet"
        "F": series(5000, {110: 12500}),                          # 2.5x normal: below the 3x spike threshold
    },
    "sys_ratio": {i: (0.4 if i == HOLIDAY else 1.0) for i in range(N)},
    "round_cases": [[2.5, 0], [-2.5, 0], [0.125, 2], [40.755745, 5], [1.005, 2], [72.15, 1], [3.14159, 3]],
    "holiday_years": [2025, 2026, 2027, 2028],
}
path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "fixtures", "trends.json")
with open(path, "w", encoding="utf-8") as f:
    json.dump(fixture, f, indent=1)
print("wrote", path)
