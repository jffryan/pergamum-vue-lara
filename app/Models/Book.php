<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    use HasFactory;

    protected $primaryKey = 'book_id';

    protected $fillable = ['title', 'slug'];

    /**
     * Books still on the shelf.
     *
     * A book is "discarded" only when *every* version of it is discarded —
     * owning the paperback but having got rid of the audiobook still leaves
     * the book in the library. Books with no versions at all are treated as
     * on-shelf so they never silently vanish.
     */
    public function scopeOnShelf(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereDoesntHave('versions')
                ->orWhereHas('versions', function ($v) {
                    $v->notDiscarded();
                });
        });
    }

    /**
     * The inverse of {@see scopeOnShelf()}: books where every version is
     * discarded. Versionless books belong to neither shelf.
     */
    public function scopeFullyDiscarded(Builder $query): Builder
    {
        return $query->whereHas('versions', function ($q) {
            $q->discarded();
        })->whereDoesntHave('versions', function ($q) {
            $q->notDiscarded();
        });
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_author', 'book_id', 'author_id')->withTimestamps();
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'book_genre', 'book_id', 'genre_id')->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, 'book_id');
    }

    public function formats(): BelongsToMany
    {
        return $this->belongsToMany(Format::class, 'versions', 'book_id', 'format_id');
    }

    public function readInstances()
    {
        return $this->hasMany(ReadInstance::class, 'book_id');
    }
}
