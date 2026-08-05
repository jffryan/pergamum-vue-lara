---
path: /feature-plans/
status: living
---

# Genre management

Tracks follow-up work and rough edges for the genre CRUD + merge surface at `/admin/genres`. Descriptive content lives in `/documentation/genres.md` (the API, `GenreService`, the store conventions) and `/documentation/admin.md` (the shell it plugs into).

Phases 1 and 2 shipped together: CRUD backend, N→1 merge, the admin shell cleanups, and the genre admin UI. **Phase 3 — the unique index on `genres.name` — has not shipped**, and is item 1 below. It is sequenced, not forgotten.

## Known limitations

### Data integrity

- **`genres.name` still has no unique index.** `GenreService::findConflict` is the only guard against two rows sharing a name, and it only covers the admin CRUD path. The three book-ingest paths (`BookController::handleGenres`, `::updateGenres`, `NewBookController::handleGenres`) still call `Genre::firstOrCreate` directly, so a concurrent insert can produce a duplicate the admin surface would then have to merge away. Future improvements item 1 closes this; it is blocked on the live database actually being cleaned with the merge tool first.
- **The conflict check is case-insensitive by inheritance, not by implementation.** It works because `config/database.php` sets `utf8mb4_unicode_ci`, so `Essays` matches `essays` at the collation level. Nothing in PHP enforces it. A collation change or a non-MySQL connection would silently turn the guard off; `GenresCrudTest::test_rename_conflict_is_case_insensitive` and `::test_rename_conflict_ignores_trailing_whitespace` are what would catch that.
- **`GenreService` owns the name rules but only the admin path uses them.** `normalize()` (trim + collapse internal whitespace) runs on create and rename only. Genres arriving through book create/edit are still whatever the caller typed — the ingest paths do their own thing. Until Future improvements item 2 lands, the two doors have different rules.

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

1. **Phase 3 — add a unique index on `genres.name`.** A migration adding `$table->unique('name')`. It **cannot** run against a database that still holds duplicate names, so it lands only after the merge tool has actually been used against the live data — that ordering is forced, not stylistic. Under `utf8mb4_unicode_ci` the index is case-insensitive, which is the intent: it makes `firstOrCreate` on the three book-ingest paths structurally safe rather than incidentally safe. Put a guard in the migration's `up()` that counts duplicate names first and throws with a readable message, rather than letting MySQL fail on index creation. Coordinate with `/feature-plans/books.md`: work that changes the ingest paths should land before or well after this, not concurrently.
2. **`GenreService::attachByName($book, array $names)`**, consolidating `BookController::handleGenres`, `BookController::updateGenres`, and `NewBookController::handleGenres` (`/feature-plans/genres.md`). Deliberately left out of the CRUD work — it touches the book create/edit pipeline, a much larger blast radius than an admin screen warrants — but `GenreService` was built so it drops in without moving the name rules again. Do this after item 1, so the index is there to catch anything the consolidation misses.
3. **Audit-log rows for merge and forced delete.** Depends on the `admin_audit_log` table in `/feature-plans/admin.md`. Even write-only, with no UI to read it, this is cheap insurance against a regretted merge. Worth doing before the merge tool sees heavy use rather than after.
4. **Soft-delete genres**, so a mistaken delete is recoverable without a restore from backup. Pairs with the auto-prune idea in `/feature-plans/genres.md` and with the same trait strategy used elsewhere. Merge would still be destructive for the losing rows unless it soft-deletes them too.
5. **A dry-run mode for merge.** `?dry_run=true` returning the resulting `books_count` and the list of affected books without writing, so the confirm dialog can state the real number instead of an upper bound. Mirrors the bulk-upload dry-run that already exists.
6. **Component tests for `GenreRow`.** The rename → merge handoff and the two-step delete escalation are the two flows where a wrong branch is invisible until someone hits it in production. Needs a Vue component-testing setup that doesn't exist yet — raise it as an open question in `/feature-plans/backend-tests.md`'s frontend sibling before adding tooling.
7. **Extract the admin table's search/sort.** Only worth doing if `GenresView` and the admin table both need it *and* the genre count makes the plain filtered list unusable. Two implementations that drift is the failure mode to watch for; one shared component with a slot API awkward enough to serve both is the other.
