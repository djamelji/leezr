<?php

namespace App\Core\Documentation;

use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentationArticle extends Model
{
    protected $fillable = [
        'uuid',
        'topic_id',
        'title',
        'slug',
        'content',
        'excerpt',
        'audience',
        'is_published',
        'sort_order',
        'created_by_platform_user_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $article) {
            $article->uuid ??= (string) Str::uuid();
            $article->slug ??= Str::slug($article->title);
            $article->audience ??= 'company';
        });
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(DocumentationTopic::class, 'topic_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'created_by_platform_user_id');
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(DocumentationFeedback::class, 'article_id');
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
