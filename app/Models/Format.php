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

    /**
     * Reduce a version payload to the length fields this format actually carries.
     *
     * A field the format doesn't expect is nulled rather than trusted, so
     * re-formatting an audiobook as paper can't leave a stale runtime behind;
     * a field it does expect is coalesced, so a payload that omits it stores
     * null instead of throwing an undefined-key error.
     *
     * Every write path goes through here — book create, book edit, and
     * `POST /versions` — so "what length does this medium have" is answered
     * once, from data on the format row.
     */
    public function lengthFieldsFrom(array $versionData): array
    {
        $fields = [];

        foreach ($this->expectedLengthFields() as $field => $expected) {
            $fields[$field] = $expected ? ($versionData[$field] ?? null) : null;
        }

        return $fields;
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
