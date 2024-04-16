<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Version extends Model
{
    use HasFactory;

    protected $primaryKey = "version_id";

    protected $fillable = ['page_count', 'format_id', 'book_id'];

    public function format(): BelongsTo
    {
        return $this->belongsTo(Format::class, "format_id");
    }
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, "book_id");
    }
    public function readInstances()
    {
        return $this->hasMany(ReadInstance::class, "version_id");
    }
}
