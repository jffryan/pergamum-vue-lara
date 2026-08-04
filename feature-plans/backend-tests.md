---
path: /feature-plans/
status: living
---

# Backend test coverage

Tracks rough edges and follow-up work for the backend test suite. Descriptive content and conventions live in `/documentation/backend-tests.md`.

## Future improvements

- **Depth per domain.** Most controllers have a happy path and a failure path; few have boundary sweeps. The known thin spot is the year-browse aggregation — see `/feature-plans/read-history.md`.
- **Wire CI.** The suite runs in one command but nothing runs it on push.

## Known limitations

- **Coverage is breadth-first, not exhaustive.** The build order in the original plan prioritized one feature-test entry point per domain over deep coverage of any single domain. Most controllers have a happy path and a failure path; few have boundary-case sweeps. Future work should fill in per-domain depth as bugs are surfaced rather than upfront.
- **No CI integration.** The suite is runnable in a single command but nothing currently runs it automatically on push or PR. Wiring CI is out of scope for the initial test build-out and tracked elsewhere.
- **MySQL-only.** Tests run against the dev `db` service via `RefreshDatabase`. Sqlite-in-memory was considered for speed but not adopted; if test runtime becomes painful, revisiting this is the lever to pull. Any migration that uses MySQL-specific column types would need auditing first.
- **No snapshot testing.** Large JSON responses are asserted via `assertJsonStructure` / `assertJsonPath`, which requires writing each path explicitly. Trade-off accepted; snapshots discourage precise assertions and break on cosmetic changes.