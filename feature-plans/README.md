# /feature-plans/

Markdown plans for upcoming and in-flight work. Plans may sit here for weeks or months before implementation — treat them as durable design context, not scratch notes.

## Status convention

Every plan starts with a `status:` frontmatter field:

```markdown
---
status: draft | in-progress | living
---
```

- **draft** — an idea, nothing implemented yet. Safe to revise freely.
- **in-progress** — implementation has started but isn't complete. The plan describes what's left, not what's been done.
- **living** — feature is shipped and has a corresponding file in `/documentation/`. The plan now tracks **future improvements** and **known limitations** only; descriptive content has moved to the doc.

## What goes in a plan

A draft or in-progress plan should cover:

- **Goal** — what the feature does and why.
- **Approach** — the design sketch, including which files / layers are involved (routes, controller, service, store, components, migrations).
- **Open questions** — anything not yet decided.
- **Touches existing systems** — flag any module that current code paths share with this plan, so unrelated work doesn't accidentally block it.

A living plan keeps only:

- **Future improvements** — things that would be nice but aren't built.
- **Known limitations** — current shortcomings that callers should be aware of.

## Lifecycle

When finishing a feature, flip the status to `living`, move the descriptive content into `/documentation/<feature>.md`, and leave behind only the future-improvements and known-limitations sections. Delete the file only when both of those sections are empty.

**Plans hold only work that is still ahead.** When an item ships, or a limitation is fixed, delete it. Don't strike it through, and don't mark it "Done", "Fixed", "Moot" or "Shipped" — `CHANGELOG.md` and git history record what shipped, and anything a future reader still needs belongs in `/documentation/`. When an item is only partly done, rewrite it to describe what's left. When it moves to another plan, keep a one-line pointer there.

**Refer to items by title, not number** — `/feature-plans/books.md` ("Soft-delete books, versions, and read instances"), not "item 1". Deleting finished items renumbers the list.

A `_template.md` is provided — copy it when starting a new plan.