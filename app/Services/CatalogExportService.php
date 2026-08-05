<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookList;
use App\Support\CsvContract;
use Generator;

/**
 * The catalog as a CSV the bulk importer can read back.
 *
 * The shape is {@see CsvContract::COLUMNS} — the importer's own column list, in
 * its own order — so an export is a valid upload without translation. That is
 * the whole point: a `migrate:fresh` followed by an import of yesterday's
 * export should land where it started.
 *
 * What crosses the boundary follows the tenancy split. Books, authors, genres,
 * versions and their discard state are the shared catalog and are exported
 * whole. Read instances and lists belong to a person, so both are the
 * exporting user's — `BelongsToCurrentUser` handles the first and the list
 * query filters the second.
 *
 * One row is one (version, read) pair. A version that has never been read
 * still gets a row, with the read columns blank, so a copy nobody has opened
 * survives the trip.
 */
class CatalogExportService
{
    /**
     * @return Generator<int, array<int, string>> the header row, then one row per (version, read)
     */
    public function rows(int $userId): Generator
    {
        yield CsvContract::COLUMNS;

        $listsByVersion = $this->listMembership($userId);

        // lazyById, not cursor: the whole point of this surface is a catalog too
        // big to want to lose, which is also one too big to hold in memory — but
        // cursor() resolves each eager load per record, so it trades the memory
        // for an N+1. lazyById eager-loads per chunk. Row order follows book_id
        // as a result; the file needs to be deterministic, not alphabetical.
        $query = Book::with([
            'authors',
            'genres',
            'versions.format',
            'versions.readInstances',
        ]);

        foreach ($query->lazyById(200, 'books.book_id') as $book) {
            $authors = $this->authorField($book);
            $genres = $this->genreField($book);

            foreach ($book->versions->sortBy('version_id') as $version) {
                $reads = $version->readInstances
                    ->sortBy([['date_read', 'asc'], ['read_instance_id', 'asc']])
                    ->values();

                $base = [
                    'title' => $book->title,
                    'authors' => $authors,
                    'format' => $version->format?->name ?? '',
                    'page_count' => $version->page_count === null ? '' : (string) $version->page_count,
                    'audio_runtime' => $version->audio_runtime === null ? '' : (string) $version->audio_runtime,
                    'version_nickname' => $version->nickname ?? '',
                    'genres' => $genres,
                    'date_read' => '',
                    'rating' => '',
                    'is_discarded' => $version->is_discarded ? '1' : '0',
                    'discarded_at' => $version->discarded_at?->format('Y-m-d') ?? '',
                    // Membership belongs to the version, not to any one read of
                    // it. Emitting it on every row of a three-times-read book
                    // would be three identical claims; the importer would
                    // no-op the last two, but the file would be lying about
                    // what it contains.
                    'lists' => $listsByVersion[$version->version_id] ?? '',
                ];

                if ($reads->isEmpty()) {
                    yield $this->ordered($base);

                    continue;
                }

                foreach ($reads as $index => $read) {
                    yield $this->ordered(array_replace($base, [
                        'date_read' => $read->date_read?->format('Y-m-d') ?? '',
                        'rating' => $this->ratingField($read->rating),
                        'lists' => $index === 0 ? $base['lists'] : '',
                    ]));
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $row
     * @return array<int, string>
     */
    private function ordered(array $row): array
    {
        return array_map(fn ($column) => $row[$column] ?? '', CsvContract::COLUMNS);
    }

    private function authorField(Book $book): string
    {
        return $book->authors
            ->sortBy(fn ($author) => $author->pivot->author_ordinal ?? PHP_INT_MAX)
            ->map(fn ($author) => trim($author->first_name ?? '').'|'.trim($author->last_name ?? ''))
            ->implode(';');
    }

    private function genreField(Book $book): string
    {
        return $book->genres->sortBy('name')->pluck('name')->implode(';');
    }

    /**
     * Ratings are stored doubled — `ReadInstance::setRatingAttribute` multiplies
     * by two on write and nothing halves on read — so the export has to undo it
     * or a roundtrip would double the rating every time through.
     */
    private function ratingField(?int $stored): string
    {
        if ($stored === null) {
            return '';
        }

        $display = $stored / 2;

        return rtrim(rtrim(number_format($display, 1, '.', ''), '0'), '.');
    }

    /**
     * version_id => `Name|ordinal;Other|ordinal`, for the exporting user's lists.
     *
     * @return array<int, string>
     */
    private function listMembership(int $userId): array
    {
        $lists = BookList::where('user_id', $userId)
            ->with('items')
            ->orderBy('list_id')
            ->get();

        $membership = [];

        foreach ($lists as $list) {
            foreach ($list->items as $item) {
                // `|` splits the entry the same way it splits an author, and
                // the ordinal rides along because row order can't carry it: a
                // version on two lists sits at a different position in each.
                $membership[$item->version_id][] = $list->name.'|'.$item->ordinal;
            }
        }

        return array_map(fn ($entries) => implode(';', $entries), $membership);
    }
}
