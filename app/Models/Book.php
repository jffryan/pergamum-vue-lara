<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;


class Book extends Model
{
    use HasFactory;

    protected $primaryKey = "book_id";

    protected $fillable = ['title', 'slug', 'date_completed'];

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
    public function getDateCompletedAttribute($date)
    {
        return $date ? Carbon::parse($date)->format('m/d/Y') : null;
    }
    public function formats(): BelongsToMany
    {
        return $this->belongsToMany(Format::class, "versions", "book_id", "format_id");
    }

    public function readInstances()
    {
        return $this->hasMany(ReadInstance::class, "book_id");
    }

}
