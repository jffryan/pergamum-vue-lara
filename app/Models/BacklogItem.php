<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacklogItem extends Model
{
    use HasFactory;
    
    protected $primaryKey = "backlog_item_id";
    
    protected $fillable = ['book_id', 'order'];

    public function book() {
        return $this->belongsTo(Book::class, "book_id");
    }

}
