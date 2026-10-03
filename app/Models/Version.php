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

    protected $fillable = ['page_count', 'audio_runtime', 'format_id', 'book_id', 'nickname', 'is_discarded', 'discarded_at', 'is_on_loan', 'loaned_to', 'loaned_at', 'location_id', 'shelf_ordinal'];

    protected $casts = [
        'is_discarded' => 'boolean',
        'discarded_at' => 'date:Y-m-d',
        'is_on_loan' => 'boolean',
        'loaned_at' => 'date:Y-m-d',
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

    /**
     * Copies we own that someone else has. Orthogonal to shelving — a lent
     * copy keeps its `location_id` as the shelf it goes back to — but
     * exclusive with discarding: `VersionController::discard` ends a loan,
     * and lending a discarded copy is refused.
     */
    public function scopeOnLoan(Builder $query): Builder
    {
        return $query->where('is_on_loan', true);
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(Format::class, 'format_id');
    }

    /**
     * Where the copy lives, or null when unshelved. Lives beside
     * `is_discarded` because both are facts about the object, not the
     * edition; discarding a copy clears it — see `VersionController::discard`.
     * Lending does not: a lent copy's location is its home shelf.
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
