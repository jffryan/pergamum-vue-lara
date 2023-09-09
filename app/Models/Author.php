<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Author extends Model
{
    use HasFactory;

    protected $primaryKey = "author_id";

    protected $fillable = ['first_name', 'last_name'];

    public function books(): BelongsToMany 
    {
        return $this->belongsToMany(Book::class, "book_author", "author_id", "book_id")->withTimestamps();
    }

}
