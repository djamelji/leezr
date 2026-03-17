<?php

namespace App\Core\Documentation;

use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DocumentationGroup extends Model
{
    protected $fillable = [
        'uuid',
        'title',
        'slug',
        'icon',
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
        static::creating(function (self $group) {
            $group->uuid ??= (string) Str::uuid();
            $group->slug ??= Str::slug($group->title);
            $group->icon ??= 'tabler-folder';
            $group->audience ??= 'company';
        });
    }

    public function topics(): HasMany
    {
        return $this->hasMany(DocumentationTopic::class, 'group_id');
    }

    public function publishedTopics(): HasMany
    {
        return $this->hasMany(DocumentationTopic::class, 'group_id')
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
