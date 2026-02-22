<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Genre extends Model
{
    use HasFactory;

    protected $primaryKey = "genre_id";

    protected $fillable = ['name'];

    public function books(): BelongsToMany 
    {
        return $this->belongsToMany(Book::class, "book_genre", "genre_id", "book_id")->withTimestamps();
    }
}
