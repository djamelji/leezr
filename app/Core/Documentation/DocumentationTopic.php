<?php

namespace App\Core\Documentation;

use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentationTopic extends Model
{
    protected $fillable = [
        'uuid',
        'title',
        'slug',
        'description',
        'icon',
        'group_id',
        'audience',
        'sort_order',
        'is_published',
        'created_by_platform_user_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $topic) {
            $topic->uuid ??= (string) Str::uuid();
            $topic->slug ??= Str::slug($topic->title);
            $topic->icon ??= 'tabler-book';
            $topic->audience ??= 'company';
        });
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(DocumentationGroup::class, 'group_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(DocumentationArticle::class, 'topic_id');
    }

    public function publishedArticles(): HasMany
    {
        return $this->hasMany(DocumentationArticle::class, 'topic_id')
            ->where('is_published', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'created_by_platform_user_id');
    }

    public function scopeForAudience($query, string $audience): void
    {
        match ($audience) {
            'public' => $query->where('audience', 'public'),
            'company' => $query->whereIn('audience', ['company', 'public']),
            'platform' => $query->where('audience', 'platform'),
            default => $query,
        };
    }

    public function scopePublished($query): void
    {
        $query->where('is_published', true);
    }
}
