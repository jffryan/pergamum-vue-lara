<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Version extends Model
{
    use HasFactory;

    protected $primaryKey = 'version_id';

    protected $fillable = ['page_count', 'audio_runtime', 'format_id', 'book_id', 'nickname', 'is_discarded', 'discarded_at', 'location_id', 'shelf_ordinal'];

    protected $casts = [
        'is_discarded' => 'boolean',
        'discarded_at' => 'date:Y-m-d',
    ];

    /**
     * Copies we no longer own. `is_discarded` is the state; `discarded_at` is
     * optional and null means "discarded, date unknown" — do not infer the
     * state from the date.
     */
    public function scopeDiscarded(Builder $query): Builder
    {
        return $query->where('is_discarded', true);
    }

    public function scopeNotDiscarded(Builder $query): Builder
    {
        return $query->where('is_discarded', false);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(Format::class, 'format_id');
    }

    /**
     * Where the copy physically is, or null when unshelved. Lives beside
     * `is_discarded` because both are facts about the object, not the
     * edition; discarding a copy clears it — see `VersionController::discard`.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function readInstances()
    {
        return $this->hasMany(ReadInstance::class, 'version_id');
    }

    public function listItems(): HasMany
    {
        return $this->hasMany(ListItem::class, 'version_id');
    }
}
