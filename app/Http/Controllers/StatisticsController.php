<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\ReadInstance;
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
        $stats['total_books_read'] = $this->calculateTotalBooksRead();
        $stats['booksReadByYear'] = $this->calculateBooksReadByYear();
        $stats['totalPagesByYear'] = $this->calculateTotalPagesReadByYear();
        $stats['percentageOfBooksRead'] = $this->calculatePercentageOfBooksRead();

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
            ->map(function ($year) {
                // For each group, sum up the page counts
                return $year->sum(function ($readInstance) {
                    return $readInstance->version->page_count; // Assuming 'version' is the direct relation
                });
            });

        return $totalPagesByYear;
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
}
