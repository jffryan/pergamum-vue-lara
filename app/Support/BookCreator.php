<?php

namespace App\Support;

use App\Models\Book;

class BookCreator
{
    public static function create(string $title): Book
    {
        $slug = self::uniqueSlug(Slugger::for($title));

        return Book::create([
            'title' => $title,
            'slug' => $slug,
        ]);
    }

    private static function uniqueSlug(string $baseSlug): string
    {
        $existingSlugs = Book::where('slug', $baseSlug)
            ->orWhere('slug', 'LIKE', $baseSlug.'-%')
            ->pluck('slug')
            ->all();

        if (! in_array($baseSlug, $existingSlugs, true)) {
            return $baseSlug;
        }

        $highest = 0;
        $pattern = '/^'.preg_quote($baseSlug, '/').'-(\d+)$/';
        foreach ($existingSlugs as $slug) {
            if (preg_match($pattern, $slug, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $baseSlug.'-'.($highest + 1);
    }
}
