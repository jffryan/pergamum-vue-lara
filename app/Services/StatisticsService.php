<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ReadInstance;

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
            'newestBooks' => $this->retrieveFiveMostRecentlyCreatedBooks(),
        ];
    }

    private function calculateTotalBooks(): int
    {
        return Book::count();
    }

    private function calculateTotalBooksRead(): int
    {
        return Book::whereHas('readInstances', function ($query) {
            $query->where('user_id', auth()->id());
        })->count();
    }

    private function calculateBooksReadByYear()
    {
        return ReadInstance::selectRaw('YEAR(date_read) as year, COUNT(*) as total')
            ->where('user_id', auth()->id())
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get();
    }

    private function calculateTotalPagesReadByYear()
    {
        return ReadInstance::join('versions', 'read_instances.version_id', '=', 'versions.version_id')
            ->selectRaw('YEAR(read_instances.date_read) as year, SUM(versions.page_count) as total')
            ->where('read_instances.user_id', auth()->id())
            ->whereNotNull('versions.page_count')
            ->groupBy('year')
            ->orderBy('year', 'desc')
            ->get()
            ->map(fn ($row) => ['year' => (int) $row->year, 'total' => (int) $row->total])
            ->toArray();
    }

    private function calculatePercentageOfBooksRead(): float
    {
        $totalBooks = $this->calculateTotalBooks();
        $completedBooks = $this->calculateTotalBooksRead();

        return $totalBooks > 0 ? round(($completedBooks / $totalBooks) * 100, 2) : 0;
    }
    
    private function retrieveFiveMostRecentlyCreatedBooks()
    {
        return Book::latest()->limit(5)->get();
    }
}
