<?php

namespace App\Modules\Platform\Audience;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailingListSubscription extends Model
{
    protected $fillable = ['list_id', 'subscriber_id', 'status', 'confirmed_at', 'unsubscribed_at'];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function mailingList(): BelongsTo
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
