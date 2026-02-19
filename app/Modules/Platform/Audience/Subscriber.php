<?php

namespace App\Modules\Platform\Audience;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscriber extends Model
{
    protected $fillable = ['email', 'status', 'confirmed_at', 'unsubscribed_at', 'metadata'];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MailingListSubscription::class, 'subscriber_id');
    }
}
