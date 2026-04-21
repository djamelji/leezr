<?php

namespace App\Core\Email;

use Illuminate\Database\Eloquent\Model;

class EmailContact extends Model
{
    protected $fillable = [
        'email',
        'name',
        'frequency',
        'last_used_at',
    ];

    protected $casts = [
        'frequency' => 'integer',
        'last_used_at' => 'datetime',
    ];

    /**
     * Record a contact usage (auto-extract on send).
     * Uses firstOrCreate + increment to avoid DB::raw() casting issues with Eloquent.
     */
    public static function recordUsage(string $email, ?string $name = null): void
    {
        $email = strtolower(trim($email));
        if (! $email) {
            return;
        }

        $contact = static::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'frequency' => 1, 'last_used_at' => now()]
        );

        if (! $contact->wasRecentlyCreated) {
            $contact->increment('frequency');
            $contact->update(array_filter([
                'name' => $name ?: $contact->name,
                'last_used_at' => now(),
            ]));
        }
    }
}
