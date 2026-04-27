---
path: /feature-plans/
status: draft
---

# Enrichment Microservice

## Goal

Enrich Pergamum book records with external metadata (cover, description, page count, published date, ISBN, subjects) by fanning out to OpenLibrary / Google Books / etc. asynchronously. A separate Python microservice handles this because async I/O fan-out across multiple upstream APIs is awkward in PHP-FPM and natural in Python (`httpx` + `asyncio`), and enrichment is bursty, slow (rate-limited upstreams), and can fail partially — wrong shape for an inline Laravel request.

**v1 scope:** ISBN-driven lookup returning a normalized payload (cover URL, description, page count, published date, subjects/genres, canonical title, author list). Deferred: review/rating ingestion, full-text search, recommendation, image hosting/proxying, write-back to upstream services.

## Approach

### Schema additions

Migrations to add before the service is useful (none exist today):

- `books`: `description` (text, nullable), `cover_url` (string, nullable), `published_date` (date, nullable), `enrichment_status` (enum: `pending` / `enriched` / `failed` / `skipped`), `enriched_at` (timestamp, nullable).
- `versions`: `isbn_10`, `isbn_13` (string, nullable, indexed), optional `cover_url` override (covers can differ per edition). ISBN belongs on `versions`, not `books`, because ISBN is edition-specific.
- New table `enrichment_attempts`: `book_id`, `source` (openlibrary/google_books/etc.), `status`, `response_payload` (json), `attempted_at`. Useful for debugging, retries, and provenance.

### Service shape

A small FastAPI app + a worker process, in one container. Two roles:

- **HTTP API** — synchronous lookup for cases where the user is actively waiting (e.g., ISBN-entry step in the new-book form).
- **Worker** — pulls jobs from a queue and runs enrichment in the background (bulk-enriching, retries, scheduled refreshes).

Endpoints (rough sketch):

- `GET /lookup/isbn/{isbn}` — synchronous, single-ISBN lookup; returns normalized payload or 404.
- `POST /enrich` — accepts `{book_id, isbn?, title?, authors?}`; enqueues a job, returns 202.
- `GET /healthz` — for compose healthcheck.

Internally: source adapters (OpenLibrary, Google Books, ...) behind a common interface; a merge step that picks fields by source priority and confidence; one normalized output schema.

### Transport between Laravel and Python

Start with **HTTP fire-and-forget** for the synchronous ISBN-lookup case (the new-book form needs it inline anyway), and **Python polling** (`GET /books?enrichment_status=pending`) for backfill/bulk. Defer a shared Redis queue until volume justifies it. This keeps `compose.yml` at five services instead of seven.

### Laravel integration

- `NewBookController::createOrGetBookByTitle` — add optional ISBN field; if provided, call Python `/lookup/isbn/{isbn}` and return its payload so the Vue form pre-fills.
- `NewBookController::completeBookCreation` — after `DB::commit()`, dispatch async enrichment request. Must happen post-commit so Python can read the row.
- New `EnrichmentController` — exposes `PATCH /books/{id}/enrichment` for Python write-back. Idempotent; only overwrites fields that are null or auto-enriched (not user-edited).
- New `App\Services\EnrichmentClient` — thin HTTP wrapper around the Python service URL (`ENRICHMENT_SERVICE_URL=http://enrichment:8000`).
- New artisan command `php artisan enrichment:backfill` — iterates books where `enrichment_status = pending` and queues them.

### Frontend integration

- `components/newBook/` — add "Enter ISBN" step calling `api/EnrichmentController.js` wrapper. Pre-fills the form. Manual path stays; ISBN is optional.
- `views/BookView` — render `cover_url`, `description`, `published_date` when present; degrade gracefully when null.
- `stores/BooksStore.js` — extend book shape to include new fields. No new store needed.

Follow existing data-flow rule (`views -> services/stores -> api/<Domain>Controller -> axios`). Do not call Python directly from Vue — go through Laravel.

### Docker / compose changes

- Add `enrichment` service to `compose.yml` on existing `appnet` network. `Dockerfile` under `docker/enrichment/`. Image: `python:3.12-slim`.
- Code in a sibling directory at repo root (`enrichment/`), versioned with the app but not mixed into Laravel's tree.
- Expose only inside `appnet` — no published host port. Laravel reaches it by service DNS.
- Env: API keys for upstreams loaded from `.env` via compose.
- Healthcheck on `/healthz` for `depends_on: condition: service_healthy`.

### Failure modes

- **Upstream rate limits** — per-source token bucket, exponential backoff, persist `enrichment_attempts` on every try.
- **Wrong book returned** — confidence scoring on title/author match; below threshold -> `enrichment_status = failed` with reason; never overwrite user data.
- **User edits enriched field** — track per-field provenance (`enriched` vs `user_edited`) so re-enrichment never clobbers manual edits. Simplest: `enrichment_locked` boolean per book.
- **Python service down** — `EnrichmentClient` fails soft. New-book form works without it.
- **Cover hotlinking** — v1 stores URL only. Image cache/S3 mirror deferred.

### Testing strategy

- **Python:** unit-test each source adapter against recorded fixtures (`respx` or `vcr.py`). Integration test merge logic with fake registry.
- **Laravel:** feature-test `PATCH /books/{id}/enrichment` (auth, idempotency, no overwrite of user-edited fields). Mock `EnrichmentClient` in `NewBookController` tests.
- **Vue:** extend `resources/js/tests/api/` with new controller; store test for books with enrichment fields.

### Phased rollout

1. Schema migrations (new columns + `enrichment_attempts`). Ship, deploy. Nothing uses them yet.
2. Python service skeleton: FastAPI with `/healthz` and one source adapter (OpenLibrary). Compose service running.
3. Synchronous ISBN lookup wired into `createOrGetBookByTitle`. Vue form pre-fills from ISBN.
4. Write-back endpoint `PATCH /books/{id}/enrichment` + per-field provenance.
5. Async enrichment on book creation (post-commit hook).
6. Backfill command for existing library.
7. Second source adapter (Google Books). Merge logic + confidence scoring.
8. *(Later)* Queue/worker if volume justifies.

## Touches existing systems

- **`NewBookController`** (`createOrGetBookByTitle`, `completeBookCreation`) — both methods get new code paths.
- **`BookController`** / book detail routes — may need to expose new fields.
- **`BooksStore.js`** — book shape extends with enrichment fields.
- **`components/newBook/`** — new ISBN step in the creation flow.
- **`compose.yml`** — new service added.
- **`books` and `versions` tables** — new columns via migration.

## Open questions

- **Auth between Laravel and Python:** shared secret header? mTLS? IP allow-list inside `appnet`? (Leaning shared secret — simplest, sufficient for internal-only service.)
- **Cover images:** store URL only, or proxy/cache? (Punt to v2.)
- **Admin UI for re-enrichment:** artisan-only, or also a "re-enrich" button on book detail? (Probably artisan first, button later.)
- **Source priority:** which upstream wins when fields conflict? (Suggest: OpenLibrary for descriptions/subjects, Google Books for covers, configurable.)
- **Refresh policy:** re-enrich periodically, or one-shot per book? (One-shot for v1.)
