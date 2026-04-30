<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('platform_settings')->first();
        if (! $row || ! $row->email) {
            return;
        }

        $data = json_decode($row->email, true);
        if (! is_array($data)) {
            return;
        }

        $changed = false;
        foreach (['smtp_password', 'imap_password'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && ! $this->isEncrypted($data[$key])) {
                $data[$key] = Crypt::encryptString($data[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            DB::table('platform_settings')
                ->where('id', $row->id)
                ->update(['email' => json_encode($data)]);
        }
    }

    public function down(): void
    {
        // Cannot safely reverse encryption — passwords would be lost
        // In practice, re-seeding or manual update is needed
    }

    private function isEncrypted(string $value): bool
    {
        $decoded = json_decode(base64_decode($value, true), true);

        return is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac']);
    }
};
