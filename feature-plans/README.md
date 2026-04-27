# /feature-plans/

Markdown plans for upcoming, in-flight, and recently shipped features. Plans may sit here for weeks or months before implementation — treat them as durable design context, not scratch notes.

## Status convention

Every plan starts with a `status:` frontmatter field:

```markdown
---
status: draft | in-progress | living
---
```

- **draft** — an idea, nothing implemented yet. Safe to revise freely.
- **in-progress** — implementation has started but isn't complete. The plan should reflect what's been done and what's left.
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

A `_template.md` is provided — copy it when starting a new plan.