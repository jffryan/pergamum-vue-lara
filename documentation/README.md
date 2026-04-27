# /documentation/

One markdown file per implemented feature. Documentation describes what *is*, not what *might be* — forward-looking notes (future improvements, known limitations) belong in the corresponding `/feature-plans/` file, not here.

## What goes in a feature doc

- **Summary** — what the feature does, in one or two sentences.
- **How it's wired** — the concrete files and layers involved (routes, controller, service, store, components, migrations). A reader should be able to find the code from this doc.
- **Non-obvious decisions** — anything a future contributor would otherwise have to reverse-engineer (custom column names, intentional deviations from the usual data flow, schema quirks, etc.).
- **Usage notes** — API shape, expected inputs/outputs, anything a caller needs.

## What does NOT go here

- "Future improvements" or wishlist items → `/feature-plans/<feature>.md` (status: living)
- "Known limitations" → same place
- Step-by-step build history or design alternatives that were rejected → either the plan or git history

A doc lands when the feature lands. If you're adding behavior to an existing feature, update the existing doc rather than creating a new one.

A `_template.md` is provided — copy it when starting a new plan.