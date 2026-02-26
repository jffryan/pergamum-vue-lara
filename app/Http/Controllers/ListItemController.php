<?php

namespace App\Http\Controllers;

use App\Models\BookList;
use App\Models\ListItem;
use Illuminate\Http\Request;

class ListItemController extends Controller
{
    public function store(Request $request, BookList $list)
    {
        $this->authorize('update', $list);

        $data = $request->validate([
            'version_id' => 'required|integer|exists:versions,version_id',
        ]);

        $maxOrdinal = $list->items()->max('ordinal');
        $ordinal = $maxOrdinal === null ? 0 : $maxOrdinal + 1;

        $item = $list->items()->create([
            'version_id' => $data['version_id'],
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
