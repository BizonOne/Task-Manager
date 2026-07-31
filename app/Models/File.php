<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'original_name',
        'path',
        'type',
        'mime_type',
        'size',
    ];

    /**
     * Whether the browser can sensibly display this inline rather than
     * downloading it.
     */
    public function isViewableInline(): bool
    {
        return in_array($this->mime_type, [
            'application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/svg+xml',
        ], true);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
