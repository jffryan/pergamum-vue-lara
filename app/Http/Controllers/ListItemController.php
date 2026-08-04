<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreListItemRequest;
use App\Models\BookList;
use App\Models\ListItem;

class ListItemController extends Controller
{
    public function store(StoreListItemRequest $request, BookList $list)
    {
        $this->authorize('update', $list);

        $maxOrdinal = $list->items()->max('ordinal');
        $ordinal = $maxOrdinal === null ? 0 : $maxOrdinal + 1;

        $item = $list->items()->create([
            'version_id' => $request->versionId(),
            'ordinal' => $ordinal,
        ]);

        $item->load('version.book.authors', 'version.format');

        return response()->json($item, 201);
    }

    public function destroy(BookList $list, ListItem $item)
    {
        $this->authorize('update', $list);

        if ($item->list_id !== $list->list_id) {
            return response()->json(['message' => 'Item does not belong to this list.'], 404);
        }

        $item->delete();

        return response()->noContent();
    }
}
