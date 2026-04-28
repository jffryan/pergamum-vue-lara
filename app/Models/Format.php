<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Format extends Model
{
    use HasFactory;

    protected $primaryKey = 'format_id';

    protected $fillable = ['name', 'slug'];

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, 'format_id');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'versions', 'format_id', 'book_id');
    }
}
