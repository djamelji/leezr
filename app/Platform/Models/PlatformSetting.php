<?php

namespace App\Platform\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PlatformSetting extends Model
{
    protected $fillable = ['general', 'theme', 'session', 'typography', 'maintenance', 'billing', 'world', 'ai', 'email'];

    protected $casts = [
        'general' => 'array',
        'theme' => 'array',
        'session' => 'array',
        'typography' => 'array',
        'maintenance' => 'array',
        'billing' => 'array',
        'world' => 'array',
        'ai' => 'array',
    ];

    /**
     * Singleton access — always returns the single row.
     * Creates it on first call. Auto-heals if >1 row detected (seeder race).
     */
    public static function instance(): static
    {
        $first = static::query()->orderBy('id')->first();

        if (! $first) {
            return static::create(['theme' => null]);
        }

        // Auto-heal: keep first row, delete duplicates (seeder race condition)
        $count = static::query()->count();
        if ($count > 1) {
            static::query()->where('id', '>', $first->id)->delete();
        }

        return $first;
    }

    /**
     * Encrypt sensitive fields before storing email settings.
     * ADR-462: SMTP/IMAP credentials encryption.
     */
    public function setEmailAttribute($value): void
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (is_array($value)) {
            foreach (['smtp_password', 'imap_password'] as $key) {
                if (isset($value[$key]) && $value[$key] !== '' && ! $this->isEncrypted($value[$key])) {
                    $value[$key] = Crypt::encryptString($value[$key]);
                }
            }
        }

        $this->attributes['email'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Decrypt sensitive fields when reading email settings.
     */
    public function getEmailAttribute($value): ?array
    {
        $data = is_string($value) ? json_decode($value, true) : $value;

        if (! is_array($data)) {
            return $data;
        }

        foreach (['smtp_password', 'imap_password'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                try {
                    $data[$key] = Crypt::decryptString($data[$key]);
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    // Already plain text (pre-migration data) — leave as-is
                }
            }
        }

        return $data;
    }

    /**
     * Check if a value looks like a Laravel encrypted string.
     */
    private function isEncrypted(string $value): bool
    {
        $decoded = json_decode(base64_decode($value, true), true);

        return is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac']);
    }
}
