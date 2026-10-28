"""The Python script and the PHP plugin compute the same trends; this checks they agree on the same input.
Needs PHP on the PATH (or PHP_BIN set); skipped otherwise. CI installs PHP so it always runs there."""
import json
import os
import shutil
import subprocess
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
import mta_ridership as m  # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
PHP = os.environ.get("PHP_BIN") or shutil.which("php")


@pytest.fixture(scope="module")
def both():
    if not PHP:
        pytest.skip("PHP not installed (set PHP_BIN to run the parity test)")
    php = json.loads(subprocess.run([PHP, "-d", "display_errors=stderr", os.path.join(HERE, "php", "parity.php")],
                                    capture_output=True, text=True, check=True).stdout)
    with open(os.path.join(HERE, "fixtures", "trends.json"), encoding="utf-8") as f:
        fx = json.load(f)
    fx["st"] = {sid: {int(i): v for i, v in s.items()} for sid, s in fx["st"].items()}
    fx["sys_ratio"] = {int(i): v for i, v in fx["sys_ratio"].items()}
    return php, fx


def norm(events):
    return sorted(({"id": str(e["id"]), "first": e["first"], "last": e["last"], "days": e["days"], "peak": e["peak"],
                    "ratio": round(e["ratio"], 6), "riders": e["riders"], "baseline": e["baseline"]} for e in events),
                  key=lambda e: (e["id"], e["first"]))


@pytest.mark.parametrize("busy", [True, False])
def test_unusual_days_match(both, busy):
    php, fx = both
    py = m.find_events(fx["st"], fx["ndays"], fx["sys_ratio"], busy)
    assert norm(php["busy" if busy else "quiet"]) == norm(py)


def test_holidays_match(both):
    php, fx = both
    for y in fx["holiday_years"]:
        assert php["holidays"][str(y)] == m.holidays(y), y


def test_rounding_matches(both):
    php, fx = both
    assert php["round"] == [m.php_round(x, nd) for x, nd in fx["round_cases"]]


def test_history_merge_counts_match(both):
    php, _ = both
    old = [{"station_complex_id": "1", "day": "2026-10-01", "riders": 100.0}, {"station_complex_id": "1", "day": "2026-10-02", "riders": 110.0},
           {"station_complex_id": "2", "day": "2026-10-02", "riders": 50.0}, {"station_complex_id": "1", "day": "2026-10-03", "riders": 120.0}]
    new = [{"station_complex_id": "1", "day": d, "riders": float(v)} for d, v in (("2026-10-02", 115), ("2026-10-03", 120), ("2026-10-04", 130))]
    assert php["merge_counts"] == m.merge_daily(old, new, "2026-10-02")[1]
