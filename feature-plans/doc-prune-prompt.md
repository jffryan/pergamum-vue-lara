  Do a reduction pass over this repo's documentation. The goal is to cut volume hard
  while making what remains more trustworthy, not less.

  ## Read first

  - `/documentation/README.md` and `/feature-plans/README.md` — the conventions.
    Note as you read: the practice has drifted from the convention. The READMEs
    already say docs should carry summary, wiring, non-obvious decisions, and usage.
    The actual files are dominated by inventories that restate the code. You are
    enforcing the existing convention, not inventing a new one.
  - `CLAUDE.md`.
  - Then inventory both folders and report sizes before changing anything.

  ## Out of scope

  - **`CHANGELOG.md` is a dated historical record. Do not prune it.** Entries describe
    what was true at a release. "Stale" is the correct state for history.
  - **Do not change application code.** If you find code that contradicts a doc, the
    doc is wrong until proven otherwise — but if the *code* is the wrong one, log it
    in the relevant plan file under Known limitations and leave it. This pass touches
    markdown only.

  ## The three failure modes

  These are distinct and have different fixes. Recent examples from this repo:

  1. **Claims that were never true.** `/feature-plans/genres.md` asserted that case
     drift between callers silently creates near-duplicate genres, and that the live
     DB likely holds `Fantasy` and `fantasy` as separate rows. Both false — the
     connection collation is `utf8mb4_unicode_ci`, so `firstOrCreate` already matches
     case-insensitively. Separately, two files said `BookTableRow` renders "the first
     three genres"; the code slices `(0, 2)`. Nobody had checked either. This is what
     volume does — the sixtieth bullet gets written from inference, not from reading.

  2. **Claims that were true and went stale.** `CLAUDE.md` and
     `/feature-plans/frontend-tests.md` both said the `@` alias was absent from
     `vite.config.js` and needed adding before component tests. It had been added.
     The docs sent a reader to do finished work and flagged a false blocker.

  3. **Referential fragility.** Plans cross-reference each other by list ordinal
     ("`/feature-plans/admin.md` item 21"). Shipping anything renumbers a list and
     silently breaks references in files nobody was editing.

  ## Risk asymmetry — where to spend verification effort

  Not all stale claims cost the same. A stale inventory is noticed instantly. These
  two sentence-shapes cause wrong work and deserve disproportionate scrutiny:

  - **Statements of absence** — "there is no X", "no endpoint exists", "nothing
    enforces this". A false one makes someone rebuild what's there.
  - **Statements of prerequisite** — "do X before Y", "this is blocked on Z".
    A false one manufactures a blocker.

  Find every one of these across both folders and verify each against the code.
  They are rare enough to check exhaustively. Correct or delete them.

  ## Cut

  - **Inventories that restate the code.** Route lists, model/column lists, migration
    filenames, "these are the files involved" bullets. A grep answers these faster
    and can't be wrong. Replace a section of them with a couple of pointers to the
    entry points (controller, service, store) and let the reader navigate.
  - **Prose that restates a name.** "`CreateFormat.vue` — a small form for creating
    formats."
  - **Speculation dressed as fact** — "likely", "probably", "presumably", "may need".
    Either verify it and state it, or cut it. Hedged claims are the ones that turn
    out never to have been true.
  - **Duplication across files.** The same fact in a doc, its plan, and CLAUDE.md.
    Pick the one place a reader will actually be when they need it; delete the others
    or reduce them to a pointer.
  - **Resolved items still carrying their full argument.** Struck-through "shipped"
    entries, and future-improvement items whose work is done. Delete them outright —
    plans are forward-looking.
  - **Ordinal cross-references.** Convert every one to the referenced item's title:
    `/feature-plans/admin.md` "Audit log table". Titles survive insertion and
    deletion. Do this repo-wide; it is mechanical and high-value.

  ## Keep — and be careful here

  The expensive mistake is cutting the reasoning that stops someone doing the wrong
  thing. Keep, at full length if needed:

  - **Why the obvious approach is wrong.** "Merge attaches the difference and deletes
    the losers rather than `UPDATE book_genre SET genre_id`, because the pivot has no
    unique constraint and the naive update leaves duplicate rows." Nobody derives that
    from the code; they rediscover it from a bug.
  - **Constraints that aren't derivable.** Sequencing ("the unique index cannot land
    until merge has cleaned the existing data"), inherited behavior ("the conflict
    check is case-insensitive only because of the collation — nothing in PHP enforces
    it"), and contracts callers key on (`reason_code` values, `force` semantics).
  - **Gotchas that have already cost someone time.** Custom primary keys (`genre_id`,
    never `id`), the `<Suspense>` requirement around top-level `await`, the dual-shape
    computeds. These are the highest value-per-line content in the repo.

  Two sharp edges the brief invites you to get wrong:

  - **"Rejected decisions" are not automatically cuttable.** A rejection that still
    prevents someone re-litigating is load-bearing — "uniqueness is deliberately not
    a `Rule::unique` validation rule, because a 422 can't carry the colliding row's
    id and the SPA needs it" must survive. Cut rejections that no longer prevent
    anything: alternatives to a decision nobody would revisit, or debates settled by
    a change that made them moot.
  - **"Stale justification" means the *claim* is stale, not that justification is
    clutter.** The reason behind a live constraint is exactly what you're preserving.
    Only cut a justification when the thing it justifies is gone.

  ## Plans vs docs — reduce them differently

  A plan is read once, at implementation time, then collapses. Exhaustive plans earn
  their length; `/feature-plans/genre-management.md` in its draft form is why that
  feature went in correctly. A doc is read repeatedly and must stay true indefinitely.

  The lifecycle in CLAUDE.md says to "move descriptive content into the doc" when a
  feature ships — that transfers volume when it should shrink it. Most of a plan is
  scaffolding for the build, not reference material. When reducing a `living` plan or
  the doc it fed, assume the majority of transferred prose should not have made the
  trip.

  ## Verification is the point

  Do not do a prose-quality pass. Every factual claim you keep in a `/documentation/`
  file should be one you either verified against the code or judged obviously safe.
  Read the code. When a claim is wrong, say so in your report rather than quietly
  deleting it — a doc that was actively misleading is a finding, not just noise.

  ## Process — calibrate before sweeping

  1. Report your inventory and a short plan.
  2. **Reduce exactly one file first** — pick a representative bloated one
     (`/documentation/genres.md` or `/documentation/books.md`) — and stop. Show the
     before/after and your cut rationale. Wait for sign-off on the calibration.
  3. Only then sweep the rest, applying the agreed calibration.
  4. Do the ordinal→title conversion repo-wide as its own step, so it reviews
     separately from the prose cuts.

  Expect substantial reduction on the descriptive sections and near-zero reduction on
  the decisions-and-gotchas sections. If a file comes out roughly evenly trimmed
  throughout, you probably cut by feel rather than by rule. **This is not a quota** —
  a file that is already dense should come out nearly unchanged, and saying so is a
  valid result.

  ## Report

  - Per file: before/after size, and what categories you cut.
  - Every claim you found to be false, with the code that disproves it. Flag
    separately any where the *code* looks wrong rather than the doc.
  - Every ordinal reference converted, and any you couldn't resolve.
  - Anything you were unsure whether to cut. Err toward keeping and flagging.

  Work on a branch. Commit the calibration file separately from the sweep.