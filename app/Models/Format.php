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

    protected $fillable = ['name', 'slug', 'expects_page_count', 'expects_audio_runtime'];

    protected $casts = [
        'expects_page_count' => 'boolean',
        'expects_audio_runtime' => 'boolean',
    ];

    /**
     * The length fields this format carries, keyed by column.
     *
     * Callers that need to decide what to persist or what to ask for should go
     * through here rather than matching on `name` or `format_id`: a format is
     * the config, so adding "Comic" with a panel count is a row, not a branch.
     */
    public function expectedLengthFields(): array
    {
        return [
            'page_count' => $this->expects_page_count,
            'audio_runtime' => $this->expects_audio_runtime,
        ];
    }

    public function expects(string $field): bool
    {
        return $this->expectedLengthFields()[$field] ?? false;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, 'format_id');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'versions', 'format_id', 'book_id');
    }
}
