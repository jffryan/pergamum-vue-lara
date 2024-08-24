<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class ReadInstance extends Model
{
    use HasFactory;

    protected $primaryKey = "read_instances_id";

    protected $dates = ['date_read'];
    
    protected $fillable = ['book_id', 'version_id', 'date_read', 'rating'];

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    public function version()
    {
        return $this->belongsTo(Version::class, 'version_id');
    }

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d');
    }
}
