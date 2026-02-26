<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Author;
use App\Models\Format;
use App\Models\Version;
use App\Models\ReadInstance;
use Carbon\Carbon;

class BulkUploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate(['csv_file' => 'required|file|mimes:csv,txt']);

        $file = $request->file('csv_file');
        $handle = fopen($file->getPathname(), 'r');

        // Skip header row
        fgetcsv($handle);

        $results = [];
        $rowNumber = 0;
        $succeeded = 0;
        $skipped = 0;
        $failed = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count(array_filter($row, fn ($v) => trim($v) !== '')) === 0) {
                continue;
            }

            $title = trim($row[0] ?? '');
            $fname = trim($row[1] ?? '');
            $lname = trim($row[2] ?? '');
            $formatName = trim($row[3] ?? '');
            $pageCount = trim($row[4] ?? '');
            $dateRead = trim($row[5] ?? '');
            $rating = trim($row[6] ?? '');
            $genresRaw = trim($row[7] ?? '');

            // Validate required fields
            if ($title === '' || $formatName === '' || $pageCount === '') {
                $results[] = [
                    'row' => $rowNumber,
                    'title' => $title ?: '(empty)',
                    'status' => 'failed',
                    'reason' => 'Title, Version_Format, and Version_PageCount are required',
                ];
                $failed++;
                continue;
            }

            if ($fname === '' && $lname === '') {
                $results[] = [
                    'row' => $rowNumber,
                    'title' => $title,
                    'status' => 'failed',
                    'reason' => 'At least one of Author_FNAME or Author_LNAME is required',
                ];
                $failed++;
                continue;
            }

            // Format lookup
            $format = Format::whereRaw('LOWER(name) = ?', [strtolower($formatName)])->first();
            if (!$format) {
                $results[] = [
                    'row' => $rowNumber,
                    'title' => $title,
                    'status' => 'failed',
                    'reason' => "Format '{$formatName}' not found",
                ];
                $failed++;
                continue;
            }

            // Slug generation
            $slug = Str::of($title)
                ->lower()
                ->replaceMatches('/[^a-z0-9\s]/', '')
                ->replace(' ', '-')
                ->limit(50);

            // Duplicate check
            if (Book::where('slug', $slug)->exists()) {
                $results[] = [
                    'row' => $rowNumber,
                    'title' => $title,
                    'status' => 'skipped',
                    'reason' => 'Book already exists',
                ];
                $skipped++;
                continue;
            }

            // Per-row transaction
            try {
                DB::beginTransaction();

                $book = Book::create(['title' => $title, 'slug' => $slug]);

                // Author
                $slugParts = array_filter([$fname, $lname]);
                $authorSlug = str_replace(' ', '-', preg_replace('/[^a-z0-9\s]/', '', strtolower(implode(' ', $slugParts))));
                $author = Author::firstOrCreate(
                    ['slug' => $authorSlug],
                    ['first_name' => $fname, 'last_name' => $lname]
                );

                // Genres
                $genres = [];
                if ($genresRaw !== '') {
                    foreach (explode(',', $genresRaw) as $genreName) {
                        $genreName = trim($genreName);
                        if ($genreName !== '') {
                            $genres[] = Genre::firstOrCreate(['name' => $genreName]);
                        }
                    }
                }

                // Attach author and genres
                $book->authors()->attach($author->author_id);
                if (!empty($genres)) {
                    $book->genres()->attach(array_map(fn ($g) => $g->genre_id, $genres));
                }

                // Version
                $version = Version::create([
                    'book_id' => $book->book_id,
                    'format_id' => $format->format_id,
                    'page_count' => $pageCount,
                ]);

                // Read instance (only if date provided)
                if ($dateRead !== '') {
                    $parsedDate = Carbon::createFromFormat('n/j/Y', $dateRead);
                    ReadInstance::create([
                        'user_id' => auth()->id(),
                        'book_id' => $book->book_id,
                        'version_id' => $version->version_id,
                        'date_read' => $parsedDate,
                        'rating' => $rating !== '' ? (float) $rating : null,
                    ]);
                }

                DB::commit();

                $results[] = [
                    'row' => $rowNumber,
                    'title' => $title,
                    'status' => 'success',
                ];
                $succeeded++;
            } catch (\Exception $e) {
                DB::rollBack();

                $results[] = [
                    'row' => $rowNumber,
                    'title' => $title,
                    'status' => 'failed',
                    'reason' => $e->getMessage(),
                ];
                $failed++;
            }
        }

        fclose($handle);

        return response()->json([
            'summary' => [
                'total' => $rowNumber,
                'succeeded' => $succeeded,
                'skipped' => $skipped,
                'failed' => $failed,
            ],
            'results' => $results,
        ]);
    }
}
