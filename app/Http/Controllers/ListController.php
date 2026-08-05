<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReorderListRequest;
use App\Http\Requests\StoreListRequest;
use App\Http\Requests\UpdateListRequest;
use App\Models\BookList;
use App\Models\ListItem;
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
        return auth()->user()->lists()->with('items:list_item_id,list_id,version_id')->get();
    }

    public function show(BookList $list)
    {
        $list->load([
            'items.version.book.authors',
            'items.version.book.genres:genre_id,name',
            'items.version.book.readInstances' => function ($query) {
                $query->select(['read_instance_id', 'book_id', 'rating']);
            },
            'items.version.format',
        ]);

        return $list;
    }

    public function store(StoreListRequest $request)
    {
        $list = auth()->user()->lists()->create([
            'name' => $request->name(),
            'slug' => Str::slug($request->name()),
        ]);

        return response()->json($list, 201);
    }

    public function update(UpdateListRequest $request, BookList $list)
    {
        $list->update([
            'name' => $request->name(),
            'slug' => Str::slug($request->name()),
        ]);

        return $list;
    }

    public function destroy(BookList $list)
    {
        $list->delete();

        return response()->noContent();
    }

    /**
     * Ordinals follow the order of the submitted ids.
     *
     * Both the ownership check and the "these ids are exactly this list's
     * items" check now live in `ReorderListRequest`, in that order — see the
     * note there on why the sequencing matters.
     */
    public function reorder(ReorderListRequest $request, BookList $list)
    {
        $itemIds = $request->orderedItemIds();

        DB::transaction(function () use ($itemIds) {
            foreach ($itemIds as $ordinal => $listItemId) {
                ListItem::where('list_item_id', $listItemId)
                    ->update(['ordinal' => $ordinal]);
            }
        });

        return $list->load('items.version.book.authors', 'items.version.format');
    }
}
