<?php

namespace App\Models;

use App\Models\Scopes\BelongsToCurrentUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReadInstance extends Model
{
    use HasFactory;

    // Singular, matching the column the migration actually creates. The table
    // is plural, the key is not.
    protected $primaryKey = 'read_instance_id';

    protected $casts = ['date_read' => 'date'];

    protected $fillable = ['user_id', 'book_id', 'version_id', 'date_read', 'rating'];

    protected static function booted(): void
    {
        // A read instance is the one row in the domain that belongs to a
        // person rather than to the catalog, so ownership is the default
        // rather than a predicate each reader remembers.
        static::addGlobalScope(new BelongsToCurrentUser);

        static::saving(function (self $instance): void {
            if ($instance->version_id === null || $instance->book_id === null) {
                return;
            }

            $versionBookId = Version::whereKey($instance->version_id)->value('book_id');

            if ($versionBookId === null) {
                return;
            }

            if ((int) $versionBookId !== (int) $instance->book_id) {
                throw new \DomainException(
                    "read_instance.version_id {$instance->version_id} belongs to book {$versionBookId}, not book {$instance->book_id}"
                );
            }
        });
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function version()
    {
        return $this->belongsTo(Version::class, 'version_id');
    }

    /**
     * Ratings live on a 0.5–5 scale with half-star steps, and the column is an
     * unsigned tinyint, so storage doubles the display value: 4.5 stars is a
     * `9`. This pair is the only place that conversion happens — read `rating`
     * off the model and you get the display scale back, so nothing downstream
     * needs to remember to halve.
     *
     * Two paths deliberately bypass it and must convert by hand: aggregates
     * (`avg()` and friends pass through to the query builder, which never runs
     * accessors) and anything hitting the table with `DB::table()`.
     */
    public function setRatingAttribute($value)
    {
        $this->attributes['rating'] = $value !== null ? $value * 2 : null;
    }

    public function getRatingAttribute($value)
    {
        return $value !== null ? $value / 2 : null;
    }

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d');
    }
}
