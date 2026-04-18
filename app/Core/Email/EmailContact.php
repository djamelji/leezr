<?php

namespace App\Core\Email;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
     */
    public static function recordUsage(string $email, ?string $name = null): void
    {
        $email = strtolower(trim($email));
        if (! $email) {
            return;
        }

        static::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name ?: DB::raw('name'),
                'frequency' => DB::raw('frequency + 1'),
                'last_used_at' => now(),
            ],
        );
    }
}
