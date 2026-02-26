<?php

namespace App\Http\Controllers;

use App\Models\BookList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ListController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(BookList::class, 'list');
    }

    public function index()
    {
        return auth()->user()->lists;
    }

    public function show(BookList $list)
    {
        $list->load('items.version.book.authors', 'items.version.format');
        return $list;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $list = auth()->user()->lists()->create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
        ]);

        return response()->json($list, 201);
    }

    public function update(Request $request, BookList $list)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $list->update([
            'name' => $data['name'],
            'slug' => Str::slug($data['name']),
        ]);

        return $list;
    }

    public function destroy(BookList $list)
    {
        $list->delete();
        return response()->noContent();
    }

    public function reorder(Request $request, BookList $list)
    {
        $this->authorize('update', $list);

        $data = $request->validate([
            'items' => 'required|array',
            'items.*' => 'integer',
        ]);

        $itemIds = $data['items'];
        $listItemIds = $list->items()->pluck('list_item_id')->all();

        if (count(array_diff($itemIds, $listItemIds)) > 0 || count($itemIds) !== count($listItemIds)) {
            return response()->json(['message' => 'Invalid item IDs for this list.'], 422);
        }

        DB::transaction(function () use ($itemIds) {
            foreach ($itemIds as $ordinal => $listItemId) {
                \App\Models\ListItem::where('list_item_id', $listItemId)
                    ->update(['ordinal' => $ordinal]);
            }
        });

        return $list->load('items.version.book.authors', 'items.version.format');
    }
}
