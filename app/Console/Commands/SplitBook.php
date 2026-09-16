<?php

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\Scopes\BelongsToCurrentUser;
use App\Models\Version;
use App\Services\GenreService;
use App\Support\BookCreator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pull one book back out of another.
 *
 * The repair for a title-only merge: two different books filed as one
 * because they share a title. The operator names the versions, authors,
 * and genres that belong to the *other* book; this creates it (slugged
 * through `BookCreator`, so it lands on `ariel-rodo` rather than colliding)
 * and moves them across. Read history follows its version, for every
 * account. List items and shelf locations hang off the version row and
 * need no moving at all.
 */
class SplitBook extends Command
{
    protected $signature = 'book:split
        {book : book_id or slug of the book to split}
        {--copy=* : version_id of a copy that belongs to the new book (`--version` is artisan\'s own)}
        {--author=* : author_id or slug of an author who belongs to the new book}
        {--genre=* : name of a genre that belongs to the new book}
        {--title= : title for the new book (defaults to the original\'s)}
        {--dry-run : report what would move and roll back}';

    protected $description = 'Split a book that was wrongly merged into another into two records';

    public function __construct(protected GenreService $genreService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $book = $this->findBook($this->argument('book'));
        if ($book === null) {
            $this->error("No book matches '{$this->argument('book')}'.");

            return self::FAILURE;
        }

        $versions = $this->pick($book->versions, 'version_id', $this->option('copy'), 'version');
        $authors = $this->pick($book->authors, ['author_id', 'slug'], $this->option('author'), 'author');
        $genres = $this->pick($book->genres, 'name', array_map(
            fn ($name) => GenreService::normalize($name),
            $this->option('genre'),
        ), 'genre');

        if ($versions === null || $authors === null || $genres === null) {
            return self::FAILURE;
        }

        // A split leaves two books behind, each with a copy and an author.
        // Anything else is a rename or a delete, and those have their own doors.
        if ($versions->isEmpty() || $authors->isEmpty()) {
            $this->error('Name at least one --copy and one --author to move.');

            return self::FAILURE;
        }
        if ($book->versions->count() === $versions->count()) {
            $this->error('Every version would move; the original would be left with no copies.');

            return self::FAILURE;
        }
        if ($book->authors->count() === $authors->count()) {
            $this->error('Every author would move; the original would be left with no authors.');

            return self::FAILURE;
        }

        $title = $this->option('title') ?: $book->title;
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            $created = $this->split($book, $title, $versions, $authors, $genres);

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->report($book, $created, $versions, $authors, $genres, $dryRun);

        return self::SUCCESS;
    }

    private function findBook(string $identifier): ?Book
    {
        $query = Book::with(['versions.format', 'authors', 'genres']);

        return ctype_digit($identifier)
            ? $query->find((int) $identifier)
            : $query->where('slug', $identifier)->first();
    }

    /**
     * The rows of `$attached` the operator named, or null (with the errors
     * printed) if any name matched nothing on this book.
     *
     * @param  string|array<int, string>  $keys  attribute(s) a name may match on
     * @param  array<int, string>  $names
     */
    private function pick(Collection $attached, string|array $keys, array $names, string $noun): ?Collection
    {
        $keys = (array) $keys;
        $picked = new Collection;
        $ok = true;

        foreach (array_unique($names) as $name) {
            $row = $attached->first(function ($row) use ($keys, $name) {
                foreach ($keys as $key) {
                    // Case-insensitive so a genre name matches the way the
                    // collation would match it.
                    if (mb_strtolower((string) $row->{$key}) === mb_strtolower((string) $name)) {
                        return true;
                    }
                }

                return false;
            });

            if ($row === null) {
                $this->error("No {$noun} '{$name}' on this book.");
                $ok = false;

                continue;
            }

            $picked->push($row);
        }

        return $ok ? $picked->unique(fn ($row) => $row->getKey())->values() : null;
    }

    /**
     * @param  Collection<int, Version>  $versions
     * @param  Collection<int, Author>  $authors
     * @param  Collection<int, Genre>  $genres
     */
    private function split(Book $book, string $title, Collection $versions, Collection $authors, Collection $genres): Book
    {
        // Ordinals restart at 1 on the new book but keep their relative
        // order, so the primary author stays primary.
        $authors = $authors->sortBy(fn (Author $a) => $a->pivot->author_ordinal)->values();

        $created = BookCreator::create(
            $title,
            $authors->map(fn (Author $a) => $a->only(['first_name', 'last_name']))->all(),
        );

        $versionIds = $versions->pluck('version_id')->all();
        Version::whereIn('version_id', $versionIds)->update(['book_id' => $created->book_id]);

        // Every account's reads of these copies, not just the operator's —
        // the scope would otherwise leave other users' history pointing at
        // the wrong book.
        ReadInstance::withoutGlobalScope(BelongsToCurrentUser::class)
            ->whereIn('version_id', $versionIds)
            ->update(['book_id' => $created->book_id]);

        $book->authors()->detach($authors->pluck('author_id')->all());
        foreach ($authors as $ordinal => $author) {
            $created->authors()->attach($author->author_id, ['author_ordinal' => $ordinal + 1]);
        }

        $genreIds = $genres->pluck('genre_id')->all();
        $book->genres()->detach($genreIds);
        $created->genres()->attach($genreIds);

        return $created;
    }

    private function report(Book $book, Book $created, Collection $versions, Collection $authors, Collection $genres, bool $dryRun): void
    {
        $verb = $dryRun ? 'Would create' : 'Created';
        $this->info("{$verb} book #{$created->book_id} '{$created->title}' (slug: {$created->slug}) from book #{$book->book_id} '{$book->title}' (slug: {$book->slug}).");

        $this->line('Versions moved:');
        foreach ($versions as $version) {
            $label = $version->format?->name ?? "format #{$version->format_id}";
            $nickname = $version->nickname !== null ? " '{$version->nickname}'" : '';
            $this->line("  #{$version->version_id} {$label}{$nickname}");
        }

        $this->line('Authors moved:');
        foreach ($authors as $author) {
            $this->line("  #{$author->author_id} ".trim($author->first_name.' '.$author->last_name));
        }

        $this->line($genres->isEmpty() ? 'Genres moved: none' : 'Genres moved:');
        foreach ($genres as $genre) {
            $this->line("  {$genre->name}");
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }
    }
}
