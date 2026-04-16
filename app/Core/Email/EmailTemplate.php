<?php

namespace App\Core\Email;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'key', 'category', 'name', 'subject_fr', 'subject_en',
        'body_fr', 'body_en', 'variables', 'is_active', 'is_system', 'preview_data',
    ];

    protected $casts = [
        'variables' => 'array',
        'preview_data' => 'array',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function subject(string $locale = 'fr'): string
    {
        return $locale === 'en' ? $this->subject_en : $this->subject_fr;
    }

    public function body(string $locale = 'fr'): string
    {
        return $locale === 'en' ? $this->body_en : $this->body_fr;
    }
}
