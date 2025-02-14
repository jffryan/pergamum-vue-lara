<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\BacklogItem;
use Carbon\Carbon;

class StatisticsService
{
    public function getUserStats()
    {
        return [
            'total_books' => $this->calculateTotalBooks(),
            'total_books_read' => $this->calculateTotalBooksRead(),
            'booksReadByYear' => $this->calculateBooksReadByYear(),
            'totalPagesByYear' => $this->calculateTotalPagesReadByYear(),
            'percentageOfBooksRead' => $this->calculatePercentageOfBooksRead(),
            'topBacklogItems' => $this->retrieveTopBacklogItems(),
            'newestBooks' => $this->retrieveFiveMostRecentlyCreatedBooks(),
        ];
    }

    private function calculateTotalBooks(): int
    {
        return Book::count();
    }

    private function calculateTotalBooksRead(): int
    {
        return Book::whereHas('readInstances')->count();
    }

    private function calculateBooksReadByYear()
    {
        return ReadInstance::selectRaw('YEAR(date_read) as year, COUNT(*) as total')
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get();
    }

    private function calculateTotalPagesReadByYear()
    {
        return ReadInstance::with('version')
            ->get()
            ->groupBy(fn ($date) => Carbon::parse($date->date_read)->format('Y'))
            ->mapWithKeys(fn ($year, $key) => [
                $key => [
                    'year' => $key,
                    'total' => $year->sum(fn ($readInstance) => $readInstance->version->page_count ?? 0)
                ]
            ])
            ->sortByDesc('year')
            ->values()
            ->toArray();
    }

    private function calculatePercentageOfBooksRead(): float
    {
        $totalBooks = $this->calculateTotalBooks();
        $completedBooks = $this->calculateTotalBooksRead();

        return $totalBooks > 0 ? round(($completedBooks / $totalBooks) * 100, 2) : 0;
    }

    private function retrieveTopBacklogItems()
    {
        return BacklogItem::with('book')
            ->whereNotNull('backlog_ordinal')
            ->orderBy('backlog_ordinal')
            ->limit(5)
            ->get();
    }

    private function retrieveFiveMostRecentlyCreatedBooks()
    {
        return Book::latest()->limit(5)->get();
    }
}
