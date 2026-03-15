<?php

namespace App\Core\Support;

use App\Core\Models\Company;
use App\Core\Models\User;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    protected $fillable = [
        'uuid',
        'company_id',
        'created_by_user_id',
        'assigned_to_platform_user_id',
        'subject',
        'status',
        'priority',
        'category',
        'last_message_at',
        'first_response_at',
        'resolved_at',
        'closed_by_platform_user_id',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (SupportTicket $ticket) {
            $ticket->uuid ??= (string) Str::uuid();
            $ticket->status ??= 'open';
            $ticket->priority ??= 'normal';
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'assigned_to_platform_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'closed_by_platform_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function latestMessage()
    {
        return $this->hasOne(SupportMessage::class, 'ticket_id')->latestOfMany();
    }
}
