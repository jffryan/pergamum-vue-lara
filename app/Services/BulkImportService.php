<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\Version;
use App\Services\Exceptions\BulkImportHeaderException;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BulkImportService
{
    private const REQUIRED_HEADERS = ['title', 'authors', 'format', 'page_count', 'audio_runtime'];

    private const OPTIONAL_HEADERS = ['version_nickname', 'genres', 'date_read', 'rating'];

    private const DATE_FORMATS = ['Y-m-d', 'n/j/Y', 'm/d/Y'];

    public function importCsv(UploadedFile $file, int $userId, bool $dryRun = false): array
    {
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
                $rowResult = $this->processRow($cells, $rowNumber, $userId, $dryRun);
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
            ];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function validateHeader(array $header): array
    {
        $normalized = array_map(fn ($cell) => strtolower(trim((string) $cell)), $header);

        $allowed = array_merge(self::REQUIRED_HEADERS, self::OPTIONAL_HEADERS);
        $unknown = array_values(array_filter($normalized, fn ($name) => $name !== '' && ! in_array($name, $allowed, true)));
        if (! empty($unknown)) {
            throw new BulkImportHeaderException('Unknown column(s): '.implode(', ', $unknown));
        }

        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $normalized));
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

    private function processRow(array $cells, int $rowNumber, int $userId, bool $dryRun): array
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
            return $this->fail($rowNumber, $title, 'missing_required_field', 'title is required');
        }
        if ($authorsRaw === '') {
            return $this->fail($rowNumber, $title, 'missing_required_field', 'authors is required');
        }
        if ($formatName === '') {
            return $this->fail($rowNumber, $title, 'missing_required_field', 'format is required');
        }

        $format = Format::whereRaw('LOWER(name) = ?', [strtolower($formatName)])->first();
        if (! $format) {
            return $this->fail($rowNumber, $title, 'format_not_found', "format '{$formatName}' not found");
        }

        $isAudio = strcasecmp($format->name, 'Audiobook') === 0;

        if ($isAudio) {
            if ($audioRuntimeRaw === '') {
                return $this->fail($rowNumber, $title, 'audio_runtime_required', 'audio_runtime is required for Audiobook rows');
            }
        } else {
            if ($pageCountRaw === '') {
                return $this->fail($rowNumber, $title, 'page_count_required', 'page_count is required for non-audio formats');
            }
        }

        $pageCount = $pageCountRaw === '' ? 0 : (int) $pageCountRaw;
        $audioRuntime = $audioRuntimeRaw === '' ? null : (int) $audioRuntimeRaw;

        $authors = $this->parseAuthors($authorsRaw);
        if ($authors === null) {
            return $this->fail($rowNumber, $title, 'author_entry_malformed', 'one or more author entries are malformed (expected First|Last)');
        }

        $rating = null;
        if ($ratingRaw !== '') {
            if (! is_numeric($ratingRaw)) {
                return $this->fail($rowNumber, $title, 'rating_not_numeric', "rating '{$ratingRaw}' is not numeric");
            }
            $ratingFloat = (float) $ratingRaw;
            if ($ratingFloat < 0.5 || $ratingFloat > 5 || fmod($ratingFloat * 2, 1) !== 0.0) {
                return $this->fail($rowNumber, $title, 'rating_out_of_range', "rating '{$ratingRaw}' must be between 0.5 and 5 in 0.5 steps");
            }
            $rating = $ratingFloat;
        }

        $dateRead = null;
        if ($dateReadRaw !== '') {
            $dateRead = $this->parseDate($dateReadRaw);
            if ($dateRead === null) {
                return $this->fail($rowNumber, $title, 'date_parse_failed', "date_read '{$dateReadRaw}' did not match Y-m-d, n/j/Y, or m/d/Y");
            }
        }

        DB::beginTransaction();
        try {
            $book = $this->resolveBook($title);
            $this->attachAuthors($book, $authors);
            $this->attachGenres($book, $this->parseList($genresRaw));
            $version = $this->resolveVersion($book, $format, $nickname === '' ? null : $nickname, $pageCount, $audioRuntime);

            if ($dateRead !== null) {
                ReadInstance::create([
                    'user_id' => $userId,
                    'book_id' => $book->book_id,
                    'version_id' => $version->version_id,
                    'date_read' => $dateRead,
                    'rating' => $rating,
                ]);
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return [
                'row' => $rowNumber,
                'title' => $title,
                'status' => 'success',
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('BulkImport row failed', [
                'row' => $rowNumber,
                'title' => $title,
                'exception' => $e,
            ]);

            return $this->fail($rowNumber, $title, 'internal_error', 'an unexpected error occurred while importing this row');
        }
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
            $slug = Str::slug(trim($first.' '.$last));
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
        $slug = Str::slug($title);
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

    private function resolveVersion(Book $book, Format $format, ?string $nickname, int $pageCount, ?int $audioRuntime): Version
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
