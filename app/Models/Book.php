<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Book extends Model
{
    use HasFactory;

    protected $primaryKey = "book_id";

    protected $fillable = ['title', 'is_completed', 'rating', 'date_completed'];

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, "book_author", "book_id", "author_id")->withTimestamps();
    }
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, "book_genre", "book_id", "genre_id")->withTimestamps();
    }
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, "book_id");
    }
}
