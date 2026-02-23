<?php

namespace App\Core\Billing\ReadModels;

use App\Core\Billing\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class SubscriptionReadModel
{
    public static function list(int $perPage = 20): LengthAwarePaginator
    {
        return Subscription::with('company:id,name,slug')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public static function pendingCount(): int
    {
        return Subscription::where('status', 'pending')->count();
    }
}
