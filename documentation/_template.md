---
path: /documentation/
status: living
---

# <Feature name>

## Scope

One line: what this doc covers and — if there's a nearby feature it could be confused with — what it doesn't. E.g. "Covers Book / Version / ReadInstance. Read-history *aggregation* (year-browse, completed views) lives in `read-history.md`."

## Summary

One or two sentences on what the feature does and where it sits in the domain. If the relationships are non-trivial and prose alone is awkward, an optional ASCII diagram can follow — skip it for simple features.

## How it's wired

Concrete files and layers, grouped by tier. A reader should be able to find every relevant piece of code from this section.

### Backend

- **Routes** (`routes/api.php`, note any non-default middleware):
  - List the endpoints with one-line descriptions.
- **Controllers**: name them and note whether they're thin/delegating or hold logic directly.
- **Services**: what business logic lives where. Call out the canonical entrypoint(s) — the function a new contributor should read first.
- **Models**: name them and call out non-default primary keys, table-name overrides, or anything else that breaks Eloquent conventions.
- **Policies / authorization**: which policy applies, which actions it covers, and which actions are authorized manually instead of via `authorizeResource`.
- **Migrations**: list the relevant migration files and any unique/foreign-key constraints worth knowing.

### Frontend

- **API layer**: which `resources/js/api/<Domain>Controller.js` wrappers exist and which routes they cover.
- **Stores**: which Pinia stores hold which state. Note anything that *isn't* in the store (e.g. view-local state that probably should be).
- **Service**: if there's a `resources/js/services/` orchestrator for this feature, name it and what it coordinates.
- **Routes**: which `router/<feature>-routes.js` file, and whether routing is by ID, slug, or both.
- **Views**: list the route components.
- **Components**: list the feature-specific components (and any shared components reused from elsewhere worth flagging).

## Non-obvious decisions and gotchas

The most important section. Anything a future contributor would otherwise have to reverse-engineer from the code:

- Custom column names or PKs that diverge from Eloquent defaults.
- Intentional deviations from the usual data flow (`views → services/stores → api → axios`).
- Schema quirks (e.g. dual-attached FKs, mutators that transform on write without a matching accessor).
- Authorization patterns that differ from the obvious read (`viewAny` returning `true` while scoping happens in the controller, etc.).
- Endpoint behavior that isn't visible from the route definition (e.g. "store doubles as add-version-to-existing-book via slug-match short-circuit").
- Conventions that must be replicated when extending — eager-load shapes the UI silently depends on, ordering applied at the relationship level, user-scoping that has to be threaded through every new query, etc.

Each item should be one paragraph: what the decision is, and what to do (or not do) because of it. Avoid forward-looking content — "this should probably be refactored" belongs in the plan file, not here.

## Usage notes

How callers actually use the feature. Either organized by user-facing flow (creating a book, recording a read, …) or by API endpoint — pick whichever maps more cleanly to the feature. Cover request shape, response shape, validation, status codes, and any side effects worth knowing. Skip exhaustive curl examples; a sentence or two per endpoint is usually enough.

## Related

- Plan file: `/feature-plans/<feature>.md` — future improvements and known limitations.
- Adjacent docs: link to other `/documentation/` files this one cross-references or depends on.