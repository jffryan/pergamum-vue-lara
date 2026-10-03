---
path: /feature-plans/
status: draft
---

# Genre rework: implication graph + tagging worklists

## Goal

Two problems with the genre data, one model change and one set of tools.

**The data is inconsistent, and will stay that way without help.** Books have been entered over a long stretch with varying enthusiasm for tagging. Some are tagged precisely, some loosely; early books miss genres that were only coined later; 55 of 195 genres sit on exactly one book. Another ~800 books are still to be entered. The stance is that a book should carry *every* genre it can support — more tags feed recommendations and relationships better — so the fix is not "tag less carefully" but "make the app do the tedious part and surface the rest as a worklist."

**Broad tags are implied by narrow ones, but have to be typed anyway.** `united states history` implies `history` implies `nonfiction`. Today all three must be entered by hand, and they aren't always: of 241 books carrying some `* history` tag, 99% also carry `nonfiction` but only 89% carry `history`. The taxonomy already has a strong trunk-and-branch shape in its *names* — `{qualifier} history` ×18, `{qualifier} fiction` ×10, `{qualifier} literature` ×5, `political {history, science, philosophy, fiction, thriller}`, `military {history, theory, fiction}` — the app just doesn't know about it.

A third complaint falls out of the second: **broad tags drown out narrow ones** on every surface that lists genres. `nonfiction` is on 75% of books and `history` on 42%; a `BookTableRow` that shows the first two tags alphabetically shows those. Once the app knows which tags are implied, every surface can order by specificity and de-emphasize the trunk.

## Approach

### Model: an implication graph over flat tags

Genres stay what they are — flat, lowercase names, what gets typed into `GenreTagInput`. What's added is a directed graph *between* genres:

```
genre_implications
  genre_id          → genres.genre_id     (the narrower tag)
  implies_genre_id  → genres.genre_id     (the broader tag it entails)
  unique (genre_id, implies_genre_id)
```

`united states history → history`, `united states history → united states`, `history → nonfiction`. The graph is a DAG, not a tree — a genre can imply several parents, which is what the branching pattern needs (`military fiction` is under both `military` and `fiction`; a tree would force a choice). Cycles are rejected at write time.

Transitive closure gives the cascade: tag a book `united states history` and it acquires `history`, `united states`, and `nonfiction`.

Two options were considered and set aside:

- **Tree (`parent_genre_id`)** — single parent is wrong for this data; see above.
- **Facets** — separate axes (form / subject / place / period / theme), with `united states history` decomposed into `form=nonfiction, subject=history, place=united states` and no longer stored as a tag. Structurally the cleanest and the best input for similarity, but it changes how tagging *feels* (multi-axis input, composite labels become computed) and requires decomposing all 195 genres by hand. **Deferred, not rejected** — see "Facets as the eventual upgrade" below. The `kind` column is the hedge.

#### `genres.kind` — a soft facet

A nullable enum on `genres`: `form | subject | place | period | theme`. Nothing enforces it at input; it exists so that surfaces and metrics can ask questions like "does this book have a `form` tag?" (31 books currently have neither `fiction` nor `nonfiction`) or "show me only `place` tags in this stats breakdown." Set from `/admin/genres`, seeded heuristically (anything ending in ` history` → `subject`, `fiction`/`nonfiction`/`poetry`/`essay`/`reference` → `form`, the regional qualifiers → `place`), corrected by hand over time.

### Materialized implied rows

Implied tags are **written to `book_genre`**, not computed at read time, with a flag distinguishing them:

```
book_genre
  + source   enum('explicit','implied')  default 'explicit'
  + unique (book_id, genre_id)            -- missing today; GenreService works around it
```

Why materialize: every existing consumer — `BookListing`, `GenreController::show`, `GenreBreakdown`, the IDF table in `similarity-scoring.md`, `withCount('books')` — keeps working with no join through a closure table. Implied tags are visible on the book, distinguishable, and queryable. The cost is a derivation step on every write path and a re-derivation job when the graph changes.

Rules:

- **On tag write** (`GenreService::attachGenres` — the additive write behind `attachByName` and `attachToBooks` — and `::syncFromInput`, and by extension every ingest door including `BulkImportService::attachGenres` and `POST /books/bulk-tag`): after resolving the explicit set, compute its closure over `genre_implications` and upsert `implied` rows for anything not already present. An implied row never overwrites an explicit one.
- **On explicit removal** (`syncFromInput` drops a tag): recompute the closure from the remaining explicit set; delete implied rows no longer reachable. A tag that was both explicit and implied-by-something-else stays as implied.
- **On graph edit** (edge added / removed / genre merged): re-derive for every book carrying the affected genre or anything downstream of it. At current scale this is a synchronous loop; at 1400 books × 4 tags it still is. Queue it only if it stops being.
- **`genres.books_count`** and every "how many books" surface reports the total (explicit + implied). A `explicit_books_count` is available where the distinction matters (admin table, worklists).

This lands in one place: a `GenreImplicationService` (or a `Support/GenreClosure`) that `GenreService` calls, so the four doors stay consolidated the way they already are.

### Explicit tags are left alone

A book already tagged `nonfiction, history, united states history` keeps all three as `explicit`. The graph fills *gaps*; it does not demote what was typed. Data entry continues exactly as it does today — typing the trunk tags remains harmless, just unnecessary.

**Compact is an optional admin action**, not an automatic behaviour: "demote every explicit tag that is implied by another explicit tag on the same book to `implied`." Available from `/admin/genres`, dry-run first (reports the count), runnable once the bulk entry is finished or whenever the redundancy starts to matter. Reversible in the sense that nothing is deleted — only the flag changes.

### Bootstrapping the graph

The naming convention seeds most of it. A one-off artisan command (`genres:suggest-implications`) proposes edges from name patterns — `X history → history`, `X fiction → fiction`, `X literature → literature`, `X theory → theory`, plus a hand-maintained short list (`history → nonfiction`, `biography → nonfiction`, `memoir → nonfiction`, `literature → fiction`?) — and writes them to the admin surface as *proposals*, not edges. Accepting is one click per edge; the same screen lets edges be added and removed by hand afterwards.

The seed is a bootstrap, not a rule. `oral history` → `history` is right; `literary history` → `history` is arguable; `alternate history` (if it appears) → `history` is wrong. Human review of ~40 proposed edges is cheap; a permanent name-pattern rule is not.

### Tagging worklists (the data-integrity half)

A new admin action, `/admin/tagging` (or a tab inside `/admin/genres`), driven by a small server-side registry of **worklist queries**, each returning rows with a one-click action. Follows the `app/Statistics/` registry pattern — each worklist is a class declaring a key, a description, a query, and an action shape — because the list will grow:

| Worklist | Query | Action |
|---|---|---|
| Under-tagged | books with fewer than N explicit tags | open edit form |
| No form tag | books with no `kind = form` genre (explicit or implied) | add `fiction` / `nonfiction` |
| Singletons | genres with `books_count = 1` | merge into / rename / leave |
| Near-duplicates | genre pairs within edit distance 2 or sharing a stem (`metaphysical`/`metaphysics`, `ficton`/`fiction`, `american literature`/`united states literature`) | merge |
| Co-occurrence suggestions | "books tagged A are also tagged B ≥ p% of the time; these books have A and not B" | accept B on each book |
| Orphan implications | genres with no in- or out-edges that match a name pattern | propose edge |
| Candidates for X | books not tagged X, ranked by similarity to the books that are | accept X on each book |
| Shelf disagreement *(after similarity ships)* | books whose shelf neighbours' tags disagree sharply with their own | open edit form |

The co-occurrence worklist is the high-value one and shares its maths with `GenreAffinity` in `similarity-scoring.md` — the same `df`/co-occurrence table serves both. It should be built once, in whichever plan lands first, and consumed by the other.

**Review progress.** Add `books.genres_reviewed_at` (nullable timestamp). Worklist actions and the book edit form set it; a "next unreviewed book" button and a count on the admin landing give the ongoing project a progress bar. Reset to null when a new genre is created that might apply (or don't — see open questions).

### Coining a genre: the tag that should exist but doesn't

Everything above propagates tags that already have books behind them. None of it can *invent* one. The common case looks like this: browsing a run of German history and political philosophy books, noticing that a dozen of them are really about fascism, and then not acting because acting means opening a dozen edit forms. The observation is made on a listing page, and listing pages offer no action, so the observation evaporates.

The workflow has three moments — recognize, capture, propagate — and needs a tool at each.

**Recognize → act: selection mode on listing surfaces.** `LibraryView`, `GenreView`, `AuthorView`, `LocationView`, and `ListView` gain a per-row checkbox and a floating action bar (the `MergeGenresBar` shape, generalized to books) with two actions: *Add tag…* (an existing name or a new one, through the same `GenreTagInput`) and *Add to list…*. The endpoint behind it already exists: `POST /api/books/bulk-tag { book_ids, genre_ids?, names? }` (shipped for `GenreView`'s add-a-book search). Its writes end in `GenreService::attachGenres`, the one additive pivot write, so implication derivation hooked there is inherited rather than reimplemented. The bar's *Add tag…* should send `genre_ids` for tags picked from `GenreTagInput` and `names` only for new ones. `LibraryView` also gains a **multi-value, intersecting `genre` filter** in its URL query — today there is none, so "german history ∩ political philosophy" can't be expressed at all. With both, seeding a new tag is: filter to the intersection, tick the books that qualify, type `fascism`, apply.

**Capture: lists as the tagging scratchpad.** The hunch doesn't always arrive when there's time to act on it, and it doesn't always arrive with a name. Lists are already the app's "curated set of books" primitive, are per-user, and gain quick-add from the same selection bar — so a list accumulates candidates over weeks. `ListView` gains one action: *Tag every book on this list with…*, with an option to delete the list afterward. Items reference `version_id`; the mapping to `book_id` is a join. A dedicated `pending_tags` table was considered and rejected: lists already do this, and a list can be named before the genre has a name.

**Propagate: seed, then let the app finish.** Once a new tag sits on a handful of books, a per-genre *Find more candidates* action (on `GenreView` and in the admin) scores every untagged book against the tagged set — IDF-weighted cosine between the book's genre vector and the centroid of the tagged books' vectors, using the same table `GenreAffinity` in `similarity-scoring.md` builds; shelf proximity to the tagged set joins the score once that plan ships — and returns a ranked checklist to accept in bulk. This is the same query as the co-occurrence worklist, parameterized by target genre instead of scanning all pairs. The human's job shrinks to seeding five or so books; the app proposes the rest, and keeps proposing as new books are entered.

The seeding threshold matters: a centroid of two books is noise. *Find more candidates* should refuse (or warn) below `df ≥ 3`, and the co-occurrence worklist's minimum-support rule applies to the same table.

### Inline suggestions on the book form

The second surface for the same co-occurrence data, for once the bulk entry is done and the workflow shifts to a handful of new books at a time: as tags are added in `GenreTagInput`, a "you might also add" row appears beneath, populated from co-occurrence against the tags already entered. Click to accept. Implied tags render in the same row, greyed, non-removable, labelled *implied by X* — so the user sees what the graph will add without having to type it.

This is the only part of the plan that changes the input experience, and it is additive.

### Display: specificity first, trunk de-emphasized

Everywhere a book's genres are listed (`BookTableRow`, `BookCard`, `BookView`, `LibraryBookRow`):

- **Order** explicit before implied, then by ascending `books_count` (rarer = more specific) as a proxy for depth. Depth in the graph is available too but `books_count` is cheaper and already loaded.
- **Style** implied tags visibly secondary (muted colour, smaller, or collapsed behind a `+N` chip).
- **`BookTableRow` "first two"** becomes "first two *explicit*," which alone fixes the drowning-out complaint for the common case.

`GenresView` and `GenreView` get a small header addition: what this genre implies, what implies it — the graph made browsable.

### Statistics

`app/Statistics/Metrics/GenreBreakdown.php` counts pivot rows. With materialization, `nonfiction` approaches 100% of nonfiction books, which is *correct* but makes the breakdown a pie with one slice. The metric gains a parameter: `explicit_only` (default for the breakdown widget) and, with `kind` populated, `kind = subject` etc. as a filter. `ScopeResolver`'s genre scope (`Support\GenreQuery`) reads `book_genre` directly, so once implied rows are materialized it scopes on explicit + implied with no change — "all my history books" wants the cascade. Its `genreBreakdown` ("Often tagged with" on `GenreView`) will want `explicit_only`, or every history page will lead with `nonfiction`.

### Similarity

`similarity-scoring.md` step 5 is the handshake. Decisions that plan should inherit from this one:

- The IDF vector includes implied rows. A fully consistent `nonfiction` gets weight → 0, which is right; the current 75% prevalence is a loading artifact *and* an inconsistency artifact, and materialization removes the second.
- The co-occurrence table is shared infrastructure; build it once.
- Once `kind` is populated, per-kind similarity (place-affinity vs subject-affinity) becomes possible without a facet migration. Not in either plan's v1.

### Facets as the eventual upgrade

If the app ever goes to true facets, the path from here is short and non-destructive: a facet *is* the set of genres with a given `kind`; the implication graph *is* the roll-up within a facet (`united states history` → `history` inside `subject`, `united states history` → `united states` across into `place`). What changes is the input surface and the storage of composite labels, not the data. Nothing in this plan should make that harder, and nothing in this plan builds toward it beyond `kind`.

### Phased rollout

1. **Schema.** `genre_implications`, `book_genre.source`, the `(book_id, genre_id)` unique index (a pre-check migration in the style of the `genres.name` one — duplicate pivot rows may exist), `genres.kind`, `books.genres_reviewed_at`. Riders: `genres.slug` from `genres.md` item 2 if it hasn't landed.
2. **Derivation.** `GenreImplicationService` — closure, upsert-implied, remove-unreachable, cycle check. Wired into `GenreService::attachGenres` (every additive door) / `syncFromInput` / `merge` / `delete`. Feature tests per door. Nothing in the graph yet, so behaviour is unchanged.
3. **Graph admin.** Edges CRUD in `/admin/genres` (a per-genre "implies / implied by" editor). `genres:suggest-implications` command writing proposals. Accept the seed. Re-derive.
4. **Display.** Source flag in every genre payload; explicit-first ordering; muted implied tags; `GenreBreakdown` `explicit_only`.
5. **Worklists.** Registry + the first four (under-tagged, no form tag, singletons, near-duplicates). `genres_reviewed_at` and the progress count.
6. **Selection + bulk tag.** Checkbox + action bar on the five listing views; `genre` filter on `LibraryView`; *Add to list* from the bar; *Tag every book on this list* on `ListView`. This step has no dependency on 2–5 and could ship first — it is the highest-leverage piece for the remaining data entry.
7. **Co-occurrence.** Shared table; suggestion worklist; *Find more candidates* per genre; inline suggestions on the book form.
8. **Compact** admin action with dry-run.
9. *(Ongoing)* `kind` curation; shelf-disagreement worklist once similarity ships.

Steps 1–4 are the feature. 5–8 are the tools. Each step is independently shippable, and 6 is a candidate to go first.

### Testing

- **Unit** — closure computation on a small fixture graph: diamond (`A → B, A → C, B → D, C → D` yields `D` once), chain depth, cycle rejection, empty graph is a no-op.
- **Feature** — per ingest door: explicit tags produce the right implied rows; removing an explicit tag removes unreachable implied rows and preserves still-reachable ones; an implied row never overwrites an explicit one; merge re-derives for the kept genre; forced delete of a genre in the middle of a chain (`history`) re-derives correctly for everything above and below. `GenreBooksOrderingTest` gains the explicit-first ordering. `GenreBreakdown` with and without `explicit_only`.
- **Feature (bulk)** — `POST /api/books/bulk-tag` derives implied genres for every listed book (the endpoint's own contract is already pinned in `BulkTagBooksTest`); *Tag every book on this list* maps version → book and dedupes a book present as two versions; `LibraryView`'s `genre` filter intersects rather than unions.
- **Migration** — the pivot unique-index pre-check refuses and reports on a seeded duplicate.
- **Vue** — `tests/services/BookServices.test.js` for the suggestion row; `tests/utils/` for the specificity sort.

## Touches existing systems

- **`GenreService`** — every tag write path gains a derivation call. The four-door consolidation in `genres.md` is what makes this one seam; don't reopen it.
- **`BulkImportService::attachGenres`** — inherits derivation through `GenreService`. CSV imports of 800 books will exercise the closure at volume; check the cost per row.
- **`book_genre`** — schema change plus the unique index. The `syncWithoutDetaching` workaround in `attachByName` can be simplified once the index exists.
- **`GenreBreakdown` / `statistics-widgets.md`** — the metric needs the flag; the planned genre scope should include implied rows.
- **`similarity-scoring.md`** — shares the co-occurrence table; its step 5 depends on this plan. Its `GenreAffinity` weight is the handshake.
- **`genres.md`** — "Add a hierarchy or alias system" points here. "Add a `slug` column on `genres`" can ride the schema migration.
- **`genre-management.md`** — merge and forced delete now trigger re-derivation, and the audit-log item there gains two more candidates (graph edits, compact).
- **`BookTableRow`, `BookCard`, `BookView`, `LibraryBookRow`** — genre rendering changes. `BookView` is under active iteration; coordinate.
- **`GenreTagInput`** — gains the implied row and the suggestion row. The threshold loosening in `genres.md` ("Loosen `GenreTagInput` thresholds") is a natural rider.
- **`admin-routes.js` / `AdminActionView`** — new action(s). The `meta.adminMenu` convention makes this a config addition.
- **`LibraryView`** — new `genre` filter in the URL-driven query (`BookListing` grows a genre constraint) and selection mode. `GenreView`, `AuthorView`, `LocationView`, `ListView` — selection mode. A shared `BookSelectionBar` component; do not build five.
- **Lists (`lists.md`, `ListItemController`)** — quick-add from the selection bar and *Tag every book on this list*. Both go through `ListItemController::store` / a new list-level action, not around them; the bulk-upload second-writer warning in `lists.md` is the precedent for why.
- **Tenancy** — genres and the graph are global (shared catalog). A graph edit re-derives for every user's books. Consistent with the existing decision; no per-user graph.

## Open questions

- **Does `literature` imply `fiction`?** `southern literature` (34 books) and `united states literature` (19) are in the branch pattern, but "literature" as a tag may be carrying essays and criticism too. Decide per edge at seed-review time rather than by rule — this is the case that argues against name-pattern rules being permanent.
- **Should `genres_reviewed_at` reset when a new genre is created?** A new tag (`cold war`) might apply to already-reviewed books. Resetting everything is too aggressive; the co-occurrence worklist will catch most of these anyway. Leaning: no reset, rely on the worklist.
- **Co-occurrence threshold and minimum support.** `p ≥ 0.7` and `df(A) ≥ 5` are starting guesses. A tag on 3 books co-occurring 100% with another is noise. Tune against the live data once the table exists.
- **Where does the graph editor live** — inside the existing `GenreRow` (an expandable "implies" section) or a separate admin action? The row is already dense; leaning separate.
- **Should implied tags be removable per book?** "This book is `military fiction` but is *not* `fiction`" is incoherent, so probably no — if the implication is wrong for a book, the edge is wrong or the explicit tag is. But a per-book suppression list is cheap insurance if a counterexample shows up.
- **Should scratchpad lists be marked as such?** Using lists as tagging drafts puts `fascism (pending)` next to a real TBR on `ListsView`. Options: accept it and delete on convert; a `purpose` column on `lists`; or a filter. Leaning: accept it — the convert-and-delete option keeps the index clean enough, and a column is a schema change for a UI nuisance.
- **Selection persistence across pages.** `LibraryView` is paginated; does a selection survive moving to page 2? Probably yes (ids in component state), with a visible count. Not across navigation.
- **Ideas not yet captured.** Prompts, to be filled in as they surface: genre *descriptions*; a "primary genre" per book; genre-level stats on `GenreView` (covered by `statistics-widgets.md` scopes, but what should the header show?); genres on list and shelf views; negative / exclusion tags in search; how genres should appear in bulk-import dry-run output; whether `kind` should ever be required.
