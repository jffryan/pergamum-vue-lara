---
path: /feature-plans/
status: draft
---

# Book Similarity Scoring

## Goal

A reusable "how alike are these two books" score, computed from data the app already holds, exposed first as a **related-books section on `BookView`** sitting alongside "More by this author," and later as the engine behind a "suggest something from my TBR" recommender.

The two use cases want different things and should not be conflated. Related-books is pure content adjacency — the near-term deliverable. Read-next is adjacency *minus* what's already read, weighted by reading appetite (length, format, mood), and it depends on read-history data that is deliberately not being entered yet. This plan builds the scoring seam so the second arrives as a new consumer of the first, not a rewrite.

## Approach

### Measured baseline, and why it is provisional

Sampled ~40k book pairs against the current dev database (676 books) using shelf co-location as a ground-truth proxy — books shelved together are, by definition, books the owner judged to belong together:

```
                     same-shelf   diff-shelf   separation
raw Jaccard             0.318        0.142        2.2x
IDF-weighted cosine     0.211        0.035        6.1x
```

IDF weighting roughly triples the discriminative power. The reason is that raw overlap is dominated by near-universal tags: `nonfiction` is on 510 of 676 books (75%), `history` on 282. Two books both tagged "nonfiction, history" tells you almost nothing; two books both tagged `espionage` tells you a great deal.

**Every number above is provisional and will move.** The library is roughly half-loaded — ~1200-1400 books expected, with the fiction shelves barely started and no ebooks, PDFs, or audiobooks entered. Specifically:

- `nonfiction` at 75% prevalence is an artifact of loading order, not the shape of the collection. It will fall as fiction lands.
- All 688 versions are `Physical` and all 688 have a `location_id`. **Both are loading artifacts.** Digital formats have no shelf, so shelf coverage will drop from 100% to something closer to 60-70% once ebooks and audiobooks are entered. This is the single most important consequence for the design — see *Graceful degradation* below.
- 195 genres today, 55 of them on exactly one book. The planned genre rework will reshape this vocabulary substantially.

The design response is to **derive all weights at runtime from live data rather than hardcoding them**. An IDF table recomputed from the current `book_genre` rows is self-correcting: it does not need the library to be finished, or the taxonomy frozen, to be right about the data it has.

### Sequencing against the genre rework

The genre rework should precede *trusting* genre-driven similarity, but it does not need to block *building* it, because IDF is derived rather than hand-tuned. What the rework changes is whether the tags are trustworthy — a data-quality question — not the weighting mechanism, which recomputes either way.

So: build the genre component in v1, but register its weight in config so it can sit at or near zero until the rework lands, then be raised without a code change.

This also inverts the dependency in a useful way. A shelf-based similarity score is a **QA instrument for the genre rework**: books whose shelf neighbours disagree sharply with their genre tags are exactly the books whose tags are wrong or too generic. Shipping shelf similarity first gives the rework a worklist instead of a spreadsheet.

### Score shape

Follow the `app/Statistics/` pattern rather than writing a `SimilarityService` with one hardcoded expression — this is precisely the "build the seam the first time" case from `CLAUDE.md`, because the component set is known to grow (genre after the rework, read-history after the backfill, description/embeddings after enrichment).

```
app/Similarity/
  Contracts/Component.php      key, weight, appliesTo(pair), score(a, b): 0..1
  ComponentRegistry.php        config-driven; composes weighted sum + renormalisation
  Components/ShelfProximity.php
  Components/AuthorOverlap.php
  Components/GenreAffinity.php
  Support/GenreIdfTable.php    df/idf derived from book_genre, cached
  SimilarityQuery.php          top-N similar to a given book
config/similarity.php          component list + weights + tuning constants
```

Each component returns a normalised 0..1 and declares whether it *applies* to a given pair.

**v1 components:**

- **`ShelfProximity`** — same shelf > same bookcase > same room > different room, as a small decaying ladder. Walks the existing `locations` parent tree. For multi-copy books, take the closest relation among non-discarded copies. Does not apply when either book has no shelved copy.
- **`AuthorOverlap`** — shared-author fraction. Sparse (577 of 665 authors are singletons) but near-certain when it fires, so it belongs in the score even though it rarely contributes. High precision, low recall.
- **`GenreAffinity`** — IDF-weighted cosine over `book_genre`. Present in v1, weight gated on the rework.

**Deferred components**, each a new file and a config line rather than a change to the formula:

- **Read-history affinity** — needs the completed-books backfill pass. Co-read patterns, rating correlation.
- **Description / embedding similarity** — needs `books.description` from `enrichment-microservices.md`. This is the one that will eventually outclass everything else here; a 3.8-tag genre vector is a thin proxy for what a book is about.
- **Physical heft** — `page_count` as a reading-commitment estimate. Near-useless for "is this similar," genuinely useful for "what should I read next," which is why it waits for the surface that wants it.

### Graceful degradation (the digital-books problem)

Because shelf coverage is about to fall well short of 100%, a naive weighted sum would score every ebook as dissimilar to everything — the shelf term would contribute zero and drag the total down, and digital books would silently vanish from related-books sections across the app.

Instead: **renormalise over the components that apply to the pair.** If `ShelfProximity` does not apply, its weight is redistributed across the remaining applicable components rather than scored as zero. A pair with no applicable components returns null, not zero, and is excluded rather than ranked last.

This is the same instinct as the statistics registry declaring its own caveats — the response should describe what it could and could not measure, rather than quietly conflating "no evidence" with "evidence of no."

### Backend integration

`BookService::getBookWithRelations` already assembles the detail payload and already builds `authorRelatedBooks` with a `RELATED_BOOK_LIMIT`. Add a sibling `similarBooks` key in the same `{book, authors, genres}` row shape, so the frontend needs no new row component.

**One conflict to handle deliberately:** if `AuthorOverlap` carries real weight, the top similar books will largely *be* the "More by this author" books, and the page will show the same titles twice in adjacent sections. The fix belongs at the surface, not in the score — the score stays general, and the `BookView` payload subtracts the `authorRelatedBooks` set from `similarBooks` before returning. The author term still earns its weight for read-next, where there is no sibling section to collide with.

### Frontend integration

Small, because the shape already exists:

- `views/BookView.vue` — a second `<section>` mirroring the existing related block, rendering `LibraryBookRow` over `detail.similarBooks`. Section hides when empty, exactly as the author block does.
- `stores/BooksStore.js` — detail shape extends with `similarBooks`. No new store.
- No new `api/` wrapper for v1; it rides the existing book-detail request.

A dedicated `GET /api/book/{slug}/similar` endpoint can come later if a surface needs similarity without the full detail payload.

### Compute strategy

**On-demand for now; revisit once the library stabilises.**

At 1400 books the full pairwise matrix is ~980k rows — cheap to store and cheap to rebuild. The argument against materialising it is not size, it is churn: during bulk entry every inserted book shifts the genre `df` table globally, invalidating every precomputed score in the matrix. A materialised table would spend the entire data-entry phase stale or perpetually rebuilding.

So v1 computes top-N for one book per request, with the IDF table cached (it is small — one row per genre — and only needs busting on `book_genre` writes). Revisit materialisation when the library is fully loaded and the genre rework has settled, or when a surface needs similarity for many books at once — which the TBR recommender eventually will.

### Testing

- **Unit** — one test class per component against a small fixture library: shelf ladder tiers, author overlap fractions, IDF maths including the single-book-genre case.
- **Registry** — renormalisation when components do not apply; null rather than zero when none apply; config-driven weight changes taking effect.
- **Feature** — `BookDetailPayloadTest` extends with `similarBooks`: correct shape, respects the limit, excludes the book itself, excludes the `authorRelatedBooks` set, stable ordering. The existing test already guards against the unordered-limit flicker bug in `authorRelatedBooks`; similarity needs the same deterministic tie-break.
- **Vue** — `resources/js/tests/utils/bookDetail.test.js` extends for the new key.

### Phased rollout

1. `app/Similarity/` skeleton + `ShelfProximity` + registry with renormalisation. Nothing consumes it yet.
2. `similarBooks` in the detail payload, `BookView` section. Ships as "shelved nearby" — a real, honest feature on its own, and the walking skeleton for everything after.
3. `AuthorOverlap` + the `authorRelatedBooks` subtraction.
4. `GenreAffinity` with a near-zero config weight; use its disagreement with shelf proximity as the genre-rework worklist.
5. *(After genre rework)* Raise the genre weight. Retune against shelf ground truth.
6. *(After read-history backfill)* Read-history component; "what to read next" surface.
7. *(After enrichment)* Description/embedding component.

## Touches existing systems

- **`BookService::getBookWithRelations`** — gains the `similarBooks` key and the author-set subtraction. The most likely collision point with other book-detail work.
- **`views/BookView.vue`** — new section. Currently being iterated on (`abcb077 updates book view`), so coordinate.
- **`genres` / `book_genre`** — the planned genre rework is an input to this plan, and this plan produces a QA worklist for it. Neither blocks the other, but the weight in `config/similarity.php` is the handshake between them.
- **`locations`** — read-only use of the parent tree. `ScopeResolver` already walks it for statistics; check whether that traversal is worth extracting rather than duplicating.
- **`enrichment-microservices.md`** — `books.description` is the eventual high-value input. Nothing here blocks it; the component registry is the slot it lands in.
- **No migrations in v1.** Materialisation, if it happens later, is the only schema change this plan foresees.

## Open questions

- **Does the shelf signal survive a fully-loaded library?** The 6.1x separation was measured on a nonfiction-heavy partial set where shelving is strongly subject-driven. Fiction may be shelved more by author or size than by subject, which would weaken the ladder. Re-measure after the fiction shelves land, and be prepared to drop the shelf weight if it thins out.
- **Do digital books get a pseudo-location?** A "Kindle" or "Audible" location would restore shelf coverage, but it would assert adjacency between books that merely share a delivery mechanism — probably worse than null. Leaning null, and letting renormalisation handle it.
- **Should the score be surface-parameterised, or one score with per-surface filtering?** Currently assuming the latter (one general score, surfaces subtract). If read-next wants genuinely different weights rather than a different filter, that assumption breaks.
- **Tie-break for equal scores.** Title order matches `authorRelatedBooks` and is stable; whether that is the *best* order for a similarity list is untested.
- **Does "shelved nearby" want to be its own visible section** rather than folded into a single "Similar books" list? It is a different and arguably more interesting claim — "other books you put here" — and the book page already surfaces physical location.
