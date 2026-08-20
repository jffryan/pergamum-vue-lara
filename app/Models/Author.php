<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Author extends Model
{
    use HasFactory;

    protected $primaryKey = 'author_id';

    protected $fillable = ['first_name', 'last_name', 'slug'];

    /**
     * The SQL value an author files under, for ordering.
     *
     * Mononyms and single-name entities — Plato, Aristotle, National
     * Geographic — have no surname, and are stored the way they read: first
     * name filled, last name empty. Ordering on `last_name` alone files every
     * one of them under the empty string, so they clump ahead of A in an order
     * no reader recognizes. Falling back to the first name is what a library
     * catalog does: Aristotle lands between Arendt and Armstrong.
     *
     * This is a sort key, not a data change. The alternative — moving the name
     * into `last_name` at write time — buys the same ordering by recording
     * "Plato" as a surname, which is wrong everywhere the two columns are read
     * separately (edit forms, imports, display "First Last").
     *
     * Returned as raw SQL rather than an Expression so it composes into both
     * `selectRaw()` and `orderByRaw()`. It interpolates no caller input.
     */
    public static function sortNameExpression(): string
    {
        return "coalesce(nullif(trim(authors.last_name), ''), authors.first_name)";
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_author', 'author_id', 'book_id')
            ->withPivot('author_ordinal')
            ->withTimestamps();
    }
}
