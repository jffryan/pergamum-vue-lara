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

    public function setRatingAttribute($value)
    {
        $this->attributes['rating'] = $value !== null ? $value * 2 : null;
    }

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d');
    }
}
