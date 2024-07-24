<?php

namespace App\Http\Controllers;

use App\Models\BacklogItem;
use Illuminate\Http\Request;
use App\Models\ReadInstance;
use App\Models\Book;
use Carbon\Carbon;

class BacklogController extends Controller
{
    public function index(Request $request)
    {
        $incompleteQuery = BacklogItem::with('book.authors', 'book.versions.format', 'book.genres', 'book.readInstances')
            ->whereHas('book', function ($query) {
                $query->where('is_completed', false);
            })
            ->orderBy('backlog_ordinal', 'asc')
            ->limit(100)
            ->get();

        $currentYear = Carbon::now()->year;
        $completedQuery = Book::with('authors', 'versions.readInstances', 'versions.format', 'versions.readInstances', 'genres')
            ->whereHas('versions.readInstances', function ($query) use ($currentYear) {
                $query->whereYear('date_read', $currentYear);
            })
            ->get();
        // $completedQuery = BacklogItem::with('book.authors', 'book.versions.format', 'book.genres', 'book.readInstances')
        //     ->join('books', 'backlog_items.book_id', '=', 'books.book_id')
        //     ->where('books.is_completed', true)
        //     // Use a subquery to order by the latest read instance date
        //     ->orderByDesc(
        //         ReadInstance::select('date_read')
        //             ->whereColumn('book_id', 'books.book_id')
        //             ->latest('date_read')
        //             ->limit(1)
        //     )
        //     ->select('backlog_items.*') // Ensure you're only selecting columns from backlog_items to avoid conflicts
        //     ->limit(100)
        //     ->get();

        // Additional query conditions (format, sort_by, etc.)
        // ...

        // Fetch and transform incomplete items
        $incompleteItems = $incompleteQuery->map(function ($item) {
            return $this->transformBacklogItem($item);
        });

        // Fetch and transform completed items
        /*
        $completedItems = $completedQuery->map(function ($item) {
            return $this->transformBacklogItem($item);
        });
        */

        // Combine results
        return [
            'incompleteItems' => $incompleteItems,
            // 'completedItems' => $completedItems
            'completedItems' => $completedQuery
        ];
    }

    private function transformBacklogItem($item)
    {
        $book = $item->book;
        $transformed = $item->toArray();
        $transformed['book_id'] = $book->book_id;
        $transformed['title'] = $book->title;
        $transformed['slug'] = $book->slug;
        $transformed['is_completed'] = $book->is_completed;
        $transformed['rating'] = $book->rating;
        $transformed['primary_author_last_name'] = $book->authors->sortBy('last_name')->first()->last_name ?? null;
        $transformed['authors'] = $book->authors;
        $transformed['versions'] = $book->versions;
        $transformed['genres'] = $book->genres;
        $transformed['read_instances'] = $book->readInstances;
        return $transformed;
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
