<?php

namespace App\Core\Notifications;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NotificationTopic extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key', 'label', 'description', 'category', 'icon',
        'scope', 'severity', 'default_channels', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_channels' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForScope(Builder $query, string $scope): Builder
    {
        return $query->whereIn('scope', [$scope, 'both']);
    }
}
