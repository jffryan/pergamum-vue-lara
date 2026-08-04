---
path: /documentation/
status: living
---

# Lists

## Scope

Covers the `BookList` / `ListItem` domain — user-owned ordered collections of book versions, plus the policy-based authorization that's specific to this feature. List *statistics* are a scope of the shared metric registry and are documented in `statistics.md`; this doc covers the list domain those metrics read from.

## Summary

Lists are user-owned, ordered collections of book *versions* (not logical books). A user creates named lists, adds versions of books to them, reorders them, and views aggregate statistics (completion %, total pages, genre breakdown, average rating) served by `GET /api/statistics/list/{id}`. Lists are the only feature in the app with real per-user authorization today.

```
User ──< BookList ──< ListItem ──> Version ──> Book ──< Author / Genre / ReadInstance
```

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `Route::resource('/lists', ListController::class)` — index/store/show/update/destroy.
  - `PATCH /lists/{list}/reorder` — bulk reorder of items by ID array.
  - `POST /lists/{list}/items` — append a version to a list.
  - `DELETE /lists/{list}/items/{item}` — remove an item.

- **Controllers**: `ListController`, `ListItemController`. `ListController::__construct` calls `authorizeResource(BookList::class, 'list')`, which wires the resource methods to `BookListPolicy`. Every method on `ListItemController` calls `$this->authorize('update', $list)` manually because `authorizeResource` does not cover custom actions; `reorder` is the exception — its request class does it (see Non-obvious decisions).
- **Requests**: `StoreListRequest`, `UpdateListRequest`, `StoreListItemRequest`, `ReorderListRequest` in `app/Http/Requests/`, all extending `ApiFormRequest`.
- **Models**: `BookList` (table `lists`, PK `list_id`) and `ListItem` (table `list_items`, PK `list_item_id`). The Eloquent class is `BookList`, not `List`, because `List` is a reserved PHP-ish word and trips autoloading on case-insensitive filesystems — the `$table` and `$primaryKey` properties bridge to the natural names.
- **Policies / authorization**: `BookListPolicy` is the load-bearing piece for this feature and the only policy in the app — read it first. `viewAny` and `create` return `true`; `view`, `update`, `delete` compare `$user->user_id === $list->user_id`. There is no `ListItemPolicy`; item operations authorize against the parent list's `update` ability.
- **Migrations**: `2023_12_16_230406_create_list_table.php`, `2026_02_18_234602_create_list_items_table.php`, plus `2026_02_22_000000_alter_list_items_use_version_id.php` which migrated the FK from `book_id` → `version_id`. Constraints: `lists` is unique on `(user_id, slug)`; `list_items` is unique on `(list_id, version_id)`. Both FKs cascade on user/list delete.

### Frontend

- **API layer**: `resources/js/api/ListController.js` — `getAllLists`, `getOneList`, `createList`, `updateList`, `deleteList`, `reorderList`, `addItemToList`, `removeItemFromList`. Built on `apiHelpers.js` (`makeRequest`/`buildUrl`) for the resource routes; the nested `/items` and `/reorder` paths are hand-built strings since `buildUrl` only handles the flat shape.
- **Stores**: `ListsStore` (Pinia) — holds `allLists` (the index payload) and `currentList` (the show payload). Provides `setAllLists`, `setCurrentList`, `addList`, `updateList`, `removeList`. Item-level mutations (add/remove/reorder) are *not* in the store today; the views mutate `this.list.items` locally after the API round-trip.
- **Routes**: `resources/js/router/list-routes.js` — `lists.index`, `lists.show`, `lists.statistics`. All three route by numeric `list_id`, **not** slug.
- **Views**: `ListsView.vue` (index + create), `ListView.vue` (detail, rename, delete, item add/remove, search-to-add), `ListStatisticsView.vue` (a `StatisticsGrid` plus the genre drill-down table).
- **Components**: `resources/js/components/lists/ListItemsTable.vue` is the only list-specific component; statistics reuses `components/books/table/BookshelfTable.vue` for the genre-filtered view.

## Non-obvious decisions and gotchas

- **List items reference `version_id`, not `book_id`.** A list pins a *specific edition* — paperback, audiobook, etc. — not the logical work. Migration `2026_02_22_000000_alter_list_items_use_version_id.php` flipped this; older code that assumed `book_id` is gone, but if you write a query against `list_items` expecting a `book_id` column you'll get a missing-column error. The book is reached through `item.version.book`.
- **The Eloquent class is `BookList`, the table is `lists`.** The class name avoids collision with PHP's `List` keyword usage and case-insensitive filesystems. `$table = 'lists'` and `$primaryKey = 'list_id'` are both required; relations to it must always pass the FK explicitly (`hasMany(BookList::class, 'user_id')`).
- **`items()` always orders by `ordinal`.** Defined on the relationship itself (`hasMany(ListItem::class, 'list_id')->orderBy('ordinal')`), so any eager-load — including the index payload — comes back ordered. Don't add a second `->orderBy()` at the call site; you'll get a redundant secondary sort.
- **Reorder is a single transactional bulk update.** `ListController::reorder` accepts the full `items` array (every `list_item_id` in the new order), validates that the set matches the list's current item IDs exactly (no missing, no extras), then assigns `ordinal = $index` inside `DB::transaction`. Partial reorders are not supported — send the whole list.
- **`viewAny` returns `true`, but `index` is still scoped.** The policy permits any authenticated user to *call* index, but the controller queries `auth()->user()->lists()`, so the user only ever sees their own. The "viewAny = true" is intentional: ownership is enforced by the query, not the policy.
- **Routing is by numeric ID, not slug.** Lists *have* a slug column (auto-generated via `Str::slug` on create/update, with a `(user_id, slug)` unique index), but no route — frontend or backend — looks up by slug.
- **No `ListItemPolicy`.** Item mutations authorize against `$this->authorize('update', $list)`. If you add an item-level action that should have different rules (e.g. "anyone can comment on an item on a public list"), you'll need to add the policy first — don't extend the implicit "update the parent" check.
- **`reorder` validates membership before touching the DB**, in `ReorderListRequest` rather than the controller. Both directions are checked, so passing a subset returns 422 instead of silently dropping items from the order; the response names the ids that aren't in the list and the ones left out. `errors.items[0]` is always the literal `Invalid item IDs for this list.` — callers key on it, so keep it first if you add detail.
- **`ReorderListRequest` authorizes itself.** It is the one request in `app/Http/Requests/` that overrides `authorize()` instead of deferring to the controller, because its validation reads the list's own item ids. Deferring would let a foreign list answer "those ids aren't in it", which is itself an answer about a list you can't see. `FormRequest` runs `authorize()` before `rules()`, which is the ordering that makes it a 403.
- **`destroy` on `ListItemController` re-checks `list_id` match** even though the route already binds `{list}` and `{item}`. This guards against `DELETE /lists/1/items/999` where item 999 belongs to a different list — without the check, route-model binding would happily delete it. Replicate this pattern if you add more nested item actions.
- **Statistics are computed server-side** by the `list` scope of the metric registry (`GET /api/statistics/list/{id}` — see `statistics.md`), not derived from the show payload. `ListStatisticsView.vue` still loads the list, but only for the heading and the genre drill-down table, which need the items themselves rather than an aggregate.
- **The show payload is intentionally heavy.** `ListController::show` eager-loads `items.version.book.authors`, `items.version.book.genres`, `items.version.format`, and `items.version.book.readInstances` filtered to the current user. The drill-down table depends on all of these; the statistics no longer do.
- **Read-instance scoping mirrors books.** The `readInstances` eager-load is constrained to `auth()->id()` — without that filter, the drill-down table would show other users' reads.
- **Stats dedupe by book_id, except pages.** A list's uniqueness constraint is on `(list_id, version_id)`, so the *same book* can appear multiple times via different versions (paperback + audiobook of one title). `totalItems`, `completedCount` and `genreBreakdown` collapse those to one book; `totalPages` sums across *all* items. That asymmetry is intentional — books count works, pages count physical volume. See the works-versus-volume note in `statistics.md`.

## Usage notes

### Listing a user's lists

`GET /lists` — returns an array of the current user's lists, each with a slim `items` projection (`list_item_id, list_id, version_id` only) for cheap "how many items?" rendering on the index. No pagination.

### Fetching a single list

`GET /lists/{list_id}` — returns the list with the deep eager-load described above. Authorized by `BookListPolicy::view`. There is no slug-based lookup.

### Creating a list

`POST /lists` with `{ name }`. Validates `name` as `required|string|max:255`. The slug is generated server-side via `Str::slug`; do not send it. Returns 201 with the new list (no items).

### Renaming a list

`PATCH /lists/{list_id}` with `{ name }`. Same validation as create; the slug is regenerated. Returns the updated list.

### Deleting a list

`DELETE /lists/{list_id}` → 204 No Content. The `cascadeOnDelete` on `list_items.list_id` removes all items.

### Adding an item

`POST /lists/{list_id}/items` with `{ version_id }`. Validation: `version_id` must exist in `versions`. The new item is appended (`ordinal = max(ordinal) + 1`, or `0` for an empty list). Response is the created item with `version.book.authors` and `version.format` eager-loaded. The `(list_id, version_id)` unique index means re-adding the same version returns a 500 — callers should check before posting.

### Removing an item

`DELETE /lists/{list_id}/items/{list_item_id}` → 204. Returns 404 if the item exists but belongs to a different list.

### Reordering

`PATCH /lists/{list_id}/reorder` with `{ items: [list_item_id, ...] }` — the **complete** new order, every existing item ID exactly once. Returns the reloaded list with items in the new order. There is no per-item move endpoint; clients construct the full array and send it.

### Statistics

`GET /api/statistics/list/{list_id}` serves `totalItems`, `completedCount`, `completedPercent`, `totalPages`, `genreBreakdown`, `averageRating` and `ratingDistribution`. A list you don't own is a 403. Any surface can request them — see "Adding a surface" in `statistics.md` — so list numbers are no longer trapped in one view.

## Related

- Plan file: `/feature-plans/lists.md` — future improvements and known limitations for this domain.
- `/documentation/books.md` — list items pin versions, not books; the rating-doubling convention is defined there and applies to the client-side stats here.
- `/documentation/statistics.md` — the metric registry that serves list statistics, and how to add a metric or a surface.