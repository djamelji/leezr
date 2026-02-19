<?php

namespace App\Modules\Platform\Audience;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailingList extends Model
{
    protected $fillable = ['slug', 'name', 'purpose', 'double_opt_in', 'is_enabled'];

    protected function casts(): array
    {
        return [
            'double_opt_in' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MailingListSubscription::class, 'list_id');
    }
}
