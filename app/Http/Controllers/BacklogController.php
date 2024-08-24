<?php

namespace App\Http\Controllers;

use App\Models\BacklogItem;
use Illuminate\Http\Request;
use App\Services\BookService;
use App\Models\Book;
use Carbon\Carbon;

class BacklogController extends Controller
{
    protected $bookService;

    public function __construct(BookService $bookService)
    {
        $this->bookService = $bookService;
    }

    public function index(Request $request)
    {
        $currentYear = Carbon::now()->year;
    
        $incompleteItems = $this->bookService->getIncompleteItems(100);
        $completedItems = $this->bookService->getCompletedItemsForYear($currentYear);
    
        return response()->json([
            'incompleteItems' => $incompleteItems,
            'completedItems' => $completedItems
        ]);
    }
    
    

    protected function transformBacklogItem($item)
    {
        $book = $item->book;
        $bookAttributes = $book->only(['book_id', 'title', 'slug', 'is_completed', 'rating']);
    
        return [
            'book' => $bookAttributes,
            'authors' => $book->authors,
            'versions' => $book->versions->map(function ($version) {
                return [
                    'version_id' => $version->version_id,
                    'page_count' => $version->page_count,
                    'audio_runtime' => $version->audio_runtime,
                    'format' => [
                        'format_id' => $version->format->format_id,
                        'name' => $version->format->name,
                        'slug' => $version->format->slug,
                    ]
                ];
            }),
            'genres' => $book->genres,
            // No readInstances for incomplete items
            'readInstances' => []
        ];
    }

    public function updateOrdinals(Request $request)
    {
        $this->validate($request, [
            'items' => 'required|array',
            'items.*.backlog_item_id' => 'required|exists:backlog_items,backlog_item_id'
        ]);

        foreach ($request->items as $index => $item) {
            BacklogItem::where('backlog_item_id', $item['backlog_item_id'])
                ->update(['backlog_ordinal' => $index]);
        }

        return response()->json(['message' => 'Backlog order updated successfully']);
    }

    public function getBooksCompletedInCurrentYear()
    {
        $currentYear = Carbon::now()->year;
        return Book::with(['versions.readInstances'])
            ->whereHas('versions.readInstances', function ($query) use ($currentYear) {
                $query->whereYear('date_read', $currentYear);
            })
            ->get();
    }
}
