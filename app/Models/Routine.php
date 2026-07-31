<?php

namespace App\Models;

use App\Support\RichText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Routine extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'frequency',
        'days',
        'weeks',
        'months',
        'start_time',
        'end_time',
    ];

    /**
     * Descriptions are rich text, stored as HTML and rendered unescaped.
     */
    public function setDescriptionAttribute(?string $value): void
    {
        $this->attributes['description'] = RichText::clean($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
