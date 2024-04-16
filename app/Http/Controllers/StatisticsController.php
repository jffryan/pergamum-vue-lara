<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\BacklogItem;
use Carbon\Carbon;

class StatisticsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $completedBooks = Book::where('is_completed', 1)
            ->with("authors", "versions.format", "genres", "readInstances")
            ->get();

        return response()->json($completedBooks);
    }

    public function fetchUserStats()
    {
        $stats = [];
        $stats['total_books'] = Book::count();
        $stats['total_books_read'] = $this->calculateTotalBooksRead();
        $stats['booksReadByYear'] = $this->calculateBooksReadByYear();
        $stats['totalPagesByYear'] = $this->calculateTotalPagesReadByYear();
        $stats['percentageOfBooksRead'] = $this->calculatePercentageOfBooksRead();
        $stats['topBacklogItems'] = $this->retrieveTopBacklogItems();

        return response()->json($stats);
    }

    public function calculateTotalBooksRead()
    {
        return Book::where('is_completed', 1)->count();
    }

    public function calculateBooksReadByYear()
    {
        $booksByYear = ReadInstance::selectRaw('YEAR(date_read) as year, COUNT(*) as total')
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get();

        return $booksByYear;
    }
    public function calculateTotalPagesReadByYear()
    {
        $totalPagesByYear = ReadInstance::with('version') // Assuming direct relation to the version
            ->get()
            ->groupBy(function ($date) {
                // Group the read instances by year
                return Carbon::parse($date->date_read)->format('Y');
            })
            ->mapWithKeys(function ($year, $key) {
                // For each group, sum up the page counts and format as {year, total}
                return [
                    $key => [
                        'year' => $key,
                        'total' => $year->sum(function ($readInstance) {
                            return $readInstance->version->page_count; // Assuming 'version' is the direct relation
                        })
                    ]
                ];
            });
    
        // Convert the collection to an array of objects sorted by year descending
        return array_values($totalPagesByYear->sortByDesc('year')->toArray());
    }
    
    public function calculatePercentageOfBooksRead()
    {
        $totalBooks = Book::count(); // Count of all books
        $completedBooks = Book::where('is_completed', 1)->count(); // Count of completed books

        if ($totalBooks == 0) {
            return 0; // To avoid division by zero
        }

        $percentageRead = ($completedBooks / $totalBooks) * 100;
        $percentageRead = number_format($percentageRead, 2, '.', '');

        return $percentageRead;
    }
    public function retrieveTopBacklogItems() {
        // Retrieve the top 5 backlog items with the lowest backlog_ordinal (i.e., 0-4)
        // Exclude null values
        $topBacklogItems = BacklogItem::with('book')
            ->whereNotNull('backlog_ordinal')
            ->orderBy('backlog_ordinal')
            ->limit(5)
            ->get();

        return $topBacklogItems;
    }
}
