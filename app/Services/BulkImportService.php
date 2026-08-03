<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookList;
use App\Models\Format;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\Version;
use App\Services\BulkImport\ImportRow;
use App\Services\BulkImport\ListCollector;
use App\Services\Exceptions\BulkImportHeaderException;
use App\Services\Exceptions\BulkImportListNameException;
use App\Services\Exceptions\BulkImportRowException;
use App\Support\RatingValidator;
use App\Support\Slugger;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BulkImportService
{
    /** Every column name the importer recognizes. Anything else fails the file. */
    private const KNOWN_COLUMNS = [
        'title',
        'authors',
        'format',
        'page_count',
        'audio_runtime',
        'version_nickname',
        'genres',
        'date_read',
        'rating',
    ];

    /**
     * Columns that must be present in the header. This is about column presence only —
     * whether a *value* is required is a per-row question (page_count vs audio_runtime
     * depends on the row's format), answered by the gates in validateRow.
     */
    private const REQUIRED_COLUMNS = ['title', 'authors', 'format'];

    private const DATE_FORMATS = ['Y-m-d', 'n/j/Y', 'm/d/Y'];

    public function importCsv(UploadedFile $file, int $userId, bool $dryRun = false, ?string $listName = null): array
    {
        // Deliberately ahead of header validation, so a request that is both
        // name-colliding and header-invalid reports list_name_taken. Nothing has been
        // opened or written at this point, so a collision costs the user nothing.
        $collector = $listName === null ? null : $this->makeListCollector($listName, $userId, $dryRun);

        $handle = fopen($file->getPathname(), 'r');

        try {
            $rawHeader = fgetcsv($handle);
            $headerMap = $this->validateHeader(is_array($rawHeader) ? $rawHeader : []);

            $results = [];
            $rowNumber = 0;
            $succeeded = 0;
            $failed = 0;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($this->isBlankRow($row)) {
                    continue;
                }

                $cells = $this->mapRow($row, $headerMap);
                $rowResult = $this->processRow($cells, $rowNumber, $userId, $dryRun, $collector);
                $results[] = $rowResult;

                if ($rowResult['status'] === 'success') {
                    $succeeded++;
                } else {
                    $failed++;
                }
            }

            return [
                'summary' => [
                    'total' => $rowNumber,
                    'succeeded' => $succeeded,
                    'skipped' => 0,
                    'failed' => $failed,
                ],
                'results' => $results,
                'list' => $collector?->toArray(),
            ];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @throws BulkImportListNameException when the user already owns a list with this slug
     */
    private function makeListCollector(string $listName, int $userId, bool $dryRun): ListCollector
    {
        // Str::slug, not Slugger — list slugs follow ListController::store so the list
        // this creates is indistinguishable from a hand-created one. The collision is on
        // slug rather than raw name, so "My List" and "my list" collide.
        $slug = Str::slug($listName);

        $taken = BookList::where('user_id', $userId)
            ->where('slug', $slug)
            ->exists();

        if ($taken) {
            throw new BulkImportListNameException($listName);
        }

        return new ListCollector($listName, $slug, $userId, $dryRun);
    }

    private function validateHeader(array $header): array
    {
        $normalized = array_map(fn ($cell) => strtolower(trim((string) $cell)), $header);

        $unknown = array_values(array_filter($normalized, fn ($name) => $name !== '' && ! in_array($name, self::KNOWN_COLUMNS, true)));
        if (! empty($unknown)) {
            throw new BulkImportHeaderException('Unknown column(s): '.implode(', ', $unknown));
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $normalized));
        if (! empty($missing)) {
            throw new BulkImportHeaderException('Missing required column(s): '.implode(', ', $missing));
        }

        $duplicates = array_keys(array_filter(array_count_values(array_filter($normalized)), fn ($n) => $n > 1));
        if (! empty($duplicates)) {
            throw new BulkImportHeaderException('Duplicate column(s): '.implode(', ', $duplicates));
        }

        $map = [];
        foreach ($normalized as $index => $name) {
            if ($name !== '') {
                $map[$name] = $index;
            }
        }

        return $map;
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $cells = [];
        foreach ($headerMap as $name => $index) {
            $cells[$name] = trim((string) ($row[$index] ?? ''));
        }

        return $cells;
    }

    private function processRow(array $cells, int $rowNumber, int $userId, bool $dryRun, ?ListCollector $collector): array
    {
        $title = $cells['title'] ?? '';
        $formatName = $cells['format'] ?? '';

        try {
            $row = $this->validateRow(
                $cells,
                $formatName === '' ? null : $this->findFormat($formatName),
            );
        } catch (BulkImportRowException $e) {
            return $this->fail($rowNumber, $title, $e->reasonCode, $e->getMessage());
        }

        return $this->persistRow($row, $rowNumber, $userId, $dryRun, $collector);
    }

    /**
     * Turn a row's raw cells into a validated ImportRow, or throw the row exception
     * carrying the reason code the caller reports.
     *
     * Pure: no file handle, no database. The format is resolved by the caller and
     * passed in (null when the name matched nothing), which is what keeps every gate
     * in here testable against a plain array of cells.
     *
     * @param  array<string, string>  $cells
     *
     * @throws BulkImportRowException
     */
    public function validateRow(array $cells, ?Format $format): ImportRow
    {
        $title = $cells['title'] ?? '';
        $authorsRaw = $cells['authors'] ?? '';
        $formatName = $cells['format'] ?? '';
        $pageCountRaw = $cells['page_count'] ?? '';
        $audioRuntimeRaw = $cells['audio_runtime'] ?? '';
        $nickname = $cells['version_nickname'] ?? '';
        $genresRaw = $cells['genres'] ?? '';
        $dateReadRaw = $cells['date_read'] ?? '';
        $ratingRaw = $cells['rating'] ?? '';

        if ($title === '') {
            throw new BulkImportRowException('title is required', 'missing_required_field');
        }
        if ($authorsRaw === '') {
            throw new BulkImportRowException('authors is required', 'missing_required_field');
        }
        if ($formatName === '') {
            throw new BulkImportRowException('format is required', 'missing_required_field');
        }
        if ($format === null) {
            throw new BulkImportRowException("format '{$formatName}' not found", 'format_not_found');
        }

        // The format declares which length field a row must carry, so a new
        // format is a row in `formats` rather than another branch here. The two
        // checks are independent — a format may legitimately expect both.
        if ($format->expects('audio_runtime') && $audioRuntimeRaw === '') {
            throw new BulkImportRowException("audio_runtime is required for {$format->name} rows", 'audio_runtime_required');
        }

        if ($format->expects('page_count') && $pageCountRaw === '') {
            throw new BulkImportRowException("page_count is required for {$format->name} rows", 'page_count_required');
        }

        $authors = $this->parseAuthors($authorsRaw);
        if ($authors === null) {
            throw new BulkImportRowException('one or more author entries are malformed (expected First|Last)', 'author_entry_malformed');
        }

        $rating = null;
        if ($ratingRaw !== '') {
            if (! is_numeric($ratingRaw)) {
                throw new BulkImportRowException("rating '{$ratingRaw}' is not numeric", 'rating_not_numeric');
            }
            // is_numeric is checked first so a non-numeric rating keeps its own reason
            // code; RatingValidator::isValid folds both checks into one boolean.
            if (! RatingValidator::isValid($ratingRaw)) {
                throw new BulkImportRowException("rating '{$ratingRaw}' must be between 0.5 and 5 in 0.5 steps", 'rating_out_of_range');
            }
            $rating = (float) $ratingRaw;
        }

        $dateRead = null;
        if ($dateReadRaw !== '') {
            $dateRead = $this->parseDate($dateReadRaw);
            if ($dateRead === null) {
                throw new BulkImportRowException("date_read '{$dateReadRaw}' did not match Y-m-d, n/j/Y, or m/d/Y", 'date_parse_failed');
            }
        }

        return new ImportRow(
            title: $title,
            authors: $authors,
            format: $format,
            // A value the format doesn't expect is dropped, not stored: an
            // audiobook that carries a page count would be counted twice by
            // `estimatedTotalPagesByYear`, which folds its runtime into pages.
            pageCount: $format->expects('page_count') && $pageCountRaw !== '' ? (int) $pageCountRaw : null,
            audioRuntime: $format->expects('audio_runtime') && $audioRuntimeRaw !== '' ? (int) $audioRuntimeRaw : null,
            nickname: $nickname === '' ? null : $nickname,
            genres: $this->parseList($genresRaw),
            dateRead: $dateRead,
            rating: $rating,
        );
    }

    private function persistRow(ImportRow $row, int $rowNumber, int $userId, bool $dryRun, ?ListCollector $collector): array
    {
        $collector?->beginRow();

        DB::beginTransaction();
        try {
            $book = $this->resolveBook($row->title);
            $this->attachAuthors($book, $row->authors);
            $this->attachGenres($book, $row->genres);
            $version = $this->resolveVersion($book, $row->format, $row->nickname, $row->pageCount, $row->audioRuntime);

            if ($row->dateRead !== null) {
                ReadInstance::create([
                    'user_id' => $userId,
                    'book_id' => $book->book_id,
                    'version_id' => $version->version_id,
                    'date_read' => $row->dateRead,
                    'rating' => $row->rating,
                ]);
            }

            // Found and created versions both count — the list reflects the CSV's
            // contents, not the delta. The key is resolveVersion's dedupe tuple, with
            // the book's slug standing in for its id so it survives a dry-run rollback.
            $collector?->add($version, implode('|', [
                $book->slug,
                $row->format->format_id,
                $row->nickname ?? '',
            ]));

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return [
                'row' => $rowNumber,
                'title' => $row->title,
                'status' => 'success',
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            $collector?->rollBackRow();
            Log::error('BulkImport row failed', [
                'row' => $rowNumber,
                'title' => $row->title,
                'exception' => $e,
            ]);

            return $this->fail($rowNumber, $row->title, 'internal_error', 'an unexpected error occurred while importing this row');
        }
    }

    private function findFormat(string $name): ?Format
    {
        return Format::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
    }

    private function fail(int $rowNumber, string $title, string $reasonCode, string $reason): array
    {
        return [
            'row' => $rowNumber,
            'title' => $title === '' ? '(empty)' : $title,
            'status' => 'failed',
            'reason_code' => $reasonCode,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<int, array{first: string, last: string, slug: string}>|null
     */
    private function parseAuthors(string $raw): ?array
    {
        $authors = [];
        foreach (explode(';', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (! str_contains($entry, '|')) {
                return null;
            }
            [$first, $last] = explode('|', $entry, 2);
            $first = trim($first);
            $last = trim($last);
            if ($first === '' && $last === '') {
                return null;
            }
            $slug = Slugger::for(trim($first.' '.$last));
            if ($slug === '') {
                return null;
            }
            $authors[] = ['first' => $first, 'last' => $last, 'slug' => $slug];
        }

        if (empty($authors)) {
            return null;
        }

        return $authors;
    }

    private function parseList(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $items = [];
        foreach (explode(';', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $items[] = $entry;
            }
        }

        return $items;
    }

    private function parseDate(string $raw): ?Carbon
    {
        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $raw);
                if ($date !== false && $date->format($format) === $raw) {
                    return $date;
                }
            } catch (Throwable) {
                // try next format
            }
        }

        return null;
    }

    private function resolveBook(string $title): Book
    {
        // Slugger for derivation only — deliberately not BookCreator::create, which
        // suffixes -2/-3 on collision. Here a slug match means "same book" (see
        // /documentation/bulk-upload.md, Row semantics 1).
        $slug = Slugger::for($title);
        $book = Book::where('slug', $slug)->first();
        if ($book) {
            return $book;
        }

        return Book::create(['title' => $title, 'slug' => $slug]);
    }

    private function attachAuthors(Book $book, array $authors): void
    {
        $existingIds = $book->authors()->pluck('authors.author_id')->all();
        $maxOrdinal = (int) DB::table('book_author')
            ->where('book_id', $book->book_id)
            ->max('author_ordinal');

        foreach ($authors as $entry) {
            $author = Author::where('slug', $entry['slug'])->first();
            if (! $author) {
                $author = Author::create([
                    'first_name' => $entry['first'],
                    'last_name' => $entry['last'],
                    'slug' => $entry['slug'],
                ]);
            }
            if (! in_array($author->author_id, $existingIds, true)) {
                $maxOrdinal++;
                $book->authors()->attach($author->author_id, ['author_ordinal' => $maxOrdinal]);
                $existingIds[] = $author->author_id;
            }
        }
    }

    private function attachGenres(Book $book, array $names): void
    {
        $existingIds = $book->genres()->pluck('genres.genre_id')->all();

        foreach ($names as $name) {
            $genre = Genre::whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->first();
            if (! $genre) {
                $genre = Genre::create(['name' => $name]);
            }
            if (! in_array($genre->genre_id, $existingIds, true)) {
                $book->genres()->attach($genre->genre_id);
                $existingIds[] = $genre->genre_id;
            }
        }
    }

    private function resolveVersion(Book $book, Format $format, ?string $nickname, ?int $pageCount, ?int $audioRuntime): Version
    {
        $query = Version::where('book_id', $book->book_id)
            ->where('format_id', $format->format_id);

        if ($nickname === null) {
            $query->whereNull('nickname');
        } else {
            $query->where('nickname', $nickname);
        }

        $version = $query->first();
        if ($version) {
            return $version;
        }

        return Version::create([
            'book_id' => $book->book_id,
            'format_id' => $format->format_id,
            'nickname' => $nickname,
            'page_count' => $pageCount,
            'audio_runtime' => $audioRuntime,
        ]);
    }
}
