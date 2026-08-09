---
path: /feature-plans/
status: living
---

# Genre management

Tracks follow-up work and rough edges for the genre CRUD + merge surface at `/admin/genres`. Descriptive content lives in `/documentation/genres.md` (the API, `GenreService`, the store conventions) and `/documentation/admin.md` (the shell it plugs into).

All three phases have shipped: CRUD backend, N→1 merge and the genre admin UI (phases 1–2), then the ingest consolidation and the unique index on `genres.name` (phase 3).

**The unique-index migration has not been run against the live database**, and cannot be until the merge tool has cleaned the duplicates already there. `up()` refuses and lists every offender rather than letting MySQL fail on one. That is an operator step, not a code step — see "Known limitations" below.

## Known limitations

### Data integrity

- **The unique index is written but unrun in production.** `2026_08_09_000000_add_unique_index_to_genres_name` throws a readable report if any name is held by more than one row. Until someone merges those at `/admin/genres` and migrates, the live database still has only `GenreService`'s select-then-insert standing between two concurrent requests and a duplicate. The test suite runs with the index in place, so nothing in CI will remind anyone this is outstanding.
- **The conflict check is case-insensitive by inheritance, not by implementation.** It works because `config/database.php` sets `utf8mb4_unicode_ci`, so `Essays` matches `essays` at the collation level. Nothing in PHP enforces it. A collation change or a non-MySQL connection would silently turn the guard off — *and would silently change what the unique index means*, since the index inherits the same collation. `GenresCrudTest::test_rename_conflict_is_case_insensitive`, `::test_rename_conflict_ignores_trailing_whitespace` and `AddUniqueIndexToGenresNameTest::test_the_index_is_case_insensitive` are what would catch that.

### Destructive operations

- **Merge is unrecoverable and unattributed.** The losing rows are gone and the pivot rows they held are gone with them; there is no undo, no soft delete, and no record of who merged what into what. This is the first genuinely irreversible operation in the app, and it is the concrete first customer for the `admin_audit_log` table in `/feature-plans/admin.md`. Decided out of scope for the initial ship; the exposure is real and grows with use.
- **`DELETE` with `force=true` is equally unrecoverable.** It strips the genre off every book that carried it. The unforced-first request means the SPA can't do this without the server having reported a count, but nothing preserves what the tagging *was*.
- **No admin authorization gate.** Every `GenrePolicy` ability returns `true`, so any authenticated user can rename, delete, and merge genres globally. This rests on the shared-catalog decision in CHANGELOG 0.1.7. If a second account ever belongs to someone who shouldn't have that power, `/feature-plans/admin.md` items 1–2 become prerequisites retroactively, and `GenrePolicy` is the one file that changes.
- **`GenrePolicy` has no test.** With no ability that can return `false` there is no negative case to write. A `GenrePolicyTest` lands with the gate, not before.

### Admin UI

- **The admin genre table has no sort and no pagination.** It renders every genre with a client-side substring filter. `GenresView` (the user-facing index) has name/popularity sort and 25-per-page pagination against the same payload, but extracting that would have meant refactoring a working view to serve an admin screen. Fine at the current genre count; revisit when it stops being.
- **Merge impact is an upper bound, not the real number.** `MergeGenresBar` sums the losers' `books_count` and says "up to N books", because a book tagged with two of the selected genres is one book and the client can't tell which overlap without asking. The response's `books_count` is the true figure, after the fact.
- **Deleting a used genre takes two confirmations.** By design — the first `DELETE` goes unforced so the server reports the authoritative count — but it does mean the common case is two round-trips and two clicks.
- **No SPA component tests.** `GenreStore` is covered (`resources/js/tests/stores/GenreStore.test.js`); the rename → merge handoff in `GenreRow`, the two-step delete escalation, and `AdminActionView`'s dispatch are all manual-QA-only. The dispatch one is shared with `/feature-plans/admin.md`.

## Future improvements

1. **Run the unique-index migration against live data.** Merge the existing duplicates at `/admin/genres`, then migrate. The migration names every offender when it refuses, so the merge list comes out of the failed run itself.
2. **Audit-log rows for merge and forced delete.** Depends on the `admin_audit_log` table in `/feature-plans/admin.md` ("Audit log table"). Even write-only, with no UI to read it, this is cheap insurance against a regretted merge. Worth doing before the merge tool sees heavy use rather than after.
3. **Soft-delete genres**, so a mistaken delete is recoverable without a restore from backup. Pairs with the auto-prune idea in `/feature-plans/genres.md` ("Auto-prune empty genres") and with the same trait strategy used elsewhere. Merge would still be destructive for the losing rows unless it soft-deletes them too.
4. **A dry-run mode for merge.** `?dry_run=true` returning the resulting `books_count` and the list of affected books without writing, so the confirm dialog can state the real number instead of an upper bound. Mirrors the bulk-upload dry-run that already exists.
5. **Component tests for `GenreRow`.** The rename → merge handoff and the two-step delete escalation are the two flows where a wrong branch is invisible until someone hits it in production. Blocked on the component-testing tooling in `/feature-plans/frontend-tests.md`, not on anything here.
6. **Extract the admin table's search/sort.** Only worth doing if `GenresView` and the admin table both need it *and* the genre count makes the plain filtered list unusable. Two implementations that drift is the failure mode to watch for; one shared component with a slot API awkward enough to serve both is the other.
