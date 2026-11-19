#!/usr/bin/env python3
"""Offline, non-vacuous tests for the B0-PR1 write_calls() boundary.

`bin/pilot-reception.py`'s read-only Reception proof exempts the patient-search
POST from its "every non-GET call is a write" assertion. The exemption must be
as narrow as the contract: ONLY a POST to the exact reception-search route
(`/clinic/v1/staff/portal/reception/patients/search`) is exempt. Any other verb
to that route, and any other route, must still count as a write — otherwise a
regression (e.g. the search route accepting PUT/DELETE, or the exemption being
widened to a prefix match) would silently pass the read-only proof.

Each test names the mutation it catches:

- `write_calls_exempts_post_to_the_exact_search_route` — an over-blocking
  exact-match implementation (e.g. wrong namespace constant) would turn the
  legitimate search POST back into a "write" and make real runs fail.
- `write_calls_counts_other_verbs_on_the_search_route_as_writes` — removing the
  `method == "POST"` half of the exemption makes PUT/PATCH/DELETE escape the
  write proof.
- `write_calls_counts_prefix_and_suffix_neighbours_as_writes` — a loosened
  route comparison (`startswith`/`endswith`/substring) would exempt neighbouring
  routes such as `.../patients/search/anything` or
  `.../patients/searchable`.
- `write_calls_counts_unrelated_post_routes_as_writes` — deleting the
  exemption entirely (or mis-scoping it to another route) must not hide writes.
- `write_calls_ignores_get_calls` — GETs are never writes, exempt or not.

Pure Python: no browser, no network, Playwright stubbed at import time.
Runnable directly (`python3 tests/bin/test_pilot_reception_write_calls.py`)
and via `python3 -m unittest discover -s tests/bin -p 'test_pilot_reception_write_calls.py'`.
"""

from __future__ import annotations

import importlib.util
import sys
import types
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PILOT = ROOT / "bin/pilot-reception.py"


def _install_playwright_stub() -> None:
    """Import-time stub: the harness imports Playwright but never calls it here."""
    if "playwright.sync_api" in sys.modules:
        return
    package = types.ModuleType("playwright")
    sync_api = types.ModuleType("playwright.sync_api")

    class TimeoutError(Exception):  # noqa: A001 - mirrors playwright's own name
        pass

    sync_api.TimeoutError = TimeoutError
    sync_api.sync_playwright = lambda: None
    package.sync_api = sync_api
    sys.modules["playwright"] = package
    sys.modules["playwright.sync_api"] = sync_api


_install_playwright_stub()

# The harness supports a built-in `--test` self-test mode in which the
# acceptance fixtures are not required at import time. unittest's own argv
# handling must not see the flag, so argv is swapped only for the import.
_saved_argv = sys.argv
sys.argv = [sys.argv[0], "--test"]
try:
    _spec = importlib.util.spec_from_file_location("pilot_reception", PILOT)
    pilot = importlib.util.module_from_spec(_spec)
    _spec.loader.exec_module(pilot)
finally:
    sys.argv = _saved_argv

SEARCH = "/clinic/v1/staff/portal/reception/patients/search"


def rest_state(calls):
    return {"rest": [{"method": method, "route": route, "status": 200} for method, route in calls]}


def writes(calls):
    return pilot.write_calls(rest_state(calls))


class WriteCallsBoundaryTest(unittest.TestCase):
    def test_write_calls_exempts_post_to_the_exact_search_route(self) -> None:
        # Mutation caught: exact-route comparison broken (wrong namespace or
        # route constant) — the legitimate search POST would become a "write".
        self.assertEqual([], writes([("POST", SEARCH)]))
        # Trailing-slash tolerance is part of route_of()'s contract.
        self.assertEqual([], writes([("POST", SEARCH + "/")]))

    def test_write_calls_counts_other_verbs_on_the_search_route_as_writes(self) -> None:
        # Mutation caught: dropping the method == "POST" condition — PUT,
        # PATCH and DELETE on the search route would escape the write proof.
        for verb in ("PUT", "PATCH", "DELETE"):
            with self.subTest(verb=verb):
                self.assertEqual(1, len(writes([(verb, SEARCH)])))

    def test_write_calls_counts_prefix_and_suffix_neighbours_as_writes(self) -> None:
        # Mutation caught: startswith/endswith/substring route comparison —
        # neighbouring paths would be exempted along with the search route.
        neighbours = [
            SEARCH + "/anything",
            SEARCH + "able",
            "/clinic/v1/staff/portal/reception/patients/searchx",
            "/clinic/v1/staff/portal/reception/patients",
            "/clinic/v1/staff/portal/reception/patients/search/extra",
            "/clinic/v1/other/staff/portal/reception/patients/search",
        ]
        for route in neighbours:
            with self.subTest(route=route):
                self.assertEqual(1, len(writes([("POST", route)])))

    def test_write_calls_counts_unrelated_post_routes_as_writes(self) -> None:
        # Mutation caught: the exemption widened (or applied to the wrong route)
        # — unrelated writes would no longer be flagged.
        self.assertEqual(1, len(writes([("POST", "/clinic/v1/staff/portal/reception/patients")])))
        self.assertEqual(1, len(writes([("POST", "/clinic/v1/staff/portal/reception/appointments")])))

    def test_write_calls_ignores_get_calls(self) -> None:
        # Mutation caught: a regression making GETs count as writes.
        self.assertEqual([], writes([("GET", SEARCH), ("GET", "/clinic/v1/staff/portal/reception/board")]))

    def test_write_calls_only_exempts_the_search_post(self) -> None:
        # Mixed traffic: exactly the search POSTs are exempt, everything else stays.
        calls = [
            ("GET", SEARCH),
            ("POST", SEARCH),
            ("POST", SEARCH),
            ("POST", "/clinic/v1/staff/portal/reception/patients"),
            ("DELETE", SEARCH),
            ("PUT", "/clinic/v1/staff/portal/reception/board"),
        ]
        flagged = writes(calls)
        self.assertEqual(
            [
                ("POST", "/clinic/v1/staff/portal/reception/patients"),
                ("DELETE", SEARCH),
                ("PUT", "/clinic/v1/staff/portal/reception/board"),
            ],
            [(r["method"], r["route"]) for r in flagged],
        )


if __name__ == "__main__":
    unittest.main()
