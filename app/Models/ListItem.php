<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListItem extends Model
{
    use HasFactory;

    protected $primaryKey = 'list_item_id';

    protected $fillable = ['list_id', 'version_id', 'ordinal'];

    public function list(): BelongsTo
    {
        return $this->belongsTo(BookList::class, 'list_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'version_id');
    }
}
