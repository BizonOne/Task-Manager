<?php

namespace App\Models;

use App\Support\RichText;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'category',
        'tags',
        'is_favorite',
        'date',
        'time',
    ];

    protected $casts = [
        'is_favorite' => 'boolean',
        'tags' => 'array',
        'date' => 'datetime',
    ];

    /**
     * Note content is rich text, stored as HTML and rendered unescaped.
     */
    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = RichText::clean($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getExcerptAttribute()
    {
        return strip_tags(substr($this->content, 0, 150)).(strlen(strip_tags($this->content)) > 150 ? '...' : '');
    }

    public function getWordCountAttribute()
    {
        return str_word_count(strip_tags($this->content));
    }

    public function getFormattedDateAttribute()
    {
        return $this->date ? $this->date->format('M j, Y') : null;
    }

    public function getFormattedTimeAttribute()
    {
        return $this->time ? Carbon::parse($this->time)->format('g:i A') : null;
    }

    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('content', 'like', "%{$search}%")
                ->orWhere('category', 'like', "%{$search}%");
        });
    }
}
