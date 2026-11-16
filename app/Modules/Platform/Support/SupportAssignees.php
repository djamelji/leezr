<?php

namespace App\Modules\Platform\Support;

use App\Core\Support\SupportTicket;
use App\Platform\Models\PlatformUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADR-801.1 — support assignment, without administration.
 *
 * - An ELIGIBLE assignee is a Platform user who can actually TAKE CHARGE of a ticket (reply, resolve) :
 *   holder of `manage_support` through a role, or `super_admin` (same bypass as PlatformUser::hasPermission).
 *   `assign_support` alone dispatches tickets but cannot handle them → not an eligible assignee.
 * - The ASSIGNMENT QUEUE is the strictly minimal read needed to dispatch : no messages, no internal notes,
 *   no requester identity or e-mail, no SLA/metrics.
 */
final class SupportAssignees
{
    public static function eligible(): Builder
    {
        return PlatformUser::query()->whereHas('roles', fn (Builder $r) => $r->where('key', 'super_admin')
            ->orWhereHas('permissions', fn (Builder $p) => $p->where('key', 'manage_support')));
    }

    public static function isEligible(int $platformUserId): bool
    {
        return self::eligible()->whereKey($platformUserId)->exists();
    }

    /** @return array<int, array{id:int, first_name:?string, last_name:?string}> */
    public static function list(): array
    {
        return self::eligible()->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (PlatformUser $u) => ['id' => $u->id, 'first_name' => $u->first_name, 'last_name' => $u->last_name])
            ->all();
    }

    public static function queue(?string $status, ?string $search, int $perPage): LengthAwarePaginator
    {
        $q = SupportTicket::query()
            ->select(['id', 'company_id', 'assigned_to_platform_user_id', 'subject', 'status', 'priority', 'category', 'created_at', 'last_message_at'])
            ->with(['company:id,name', 'assignee:id,first_name,last_name'])
            ->orderByDesc('last_message_at')->orderByDesc('created_at');

        $status ? $q->where('status', $status) : $q->whereNotIn('status', ['resolved', 'closed']);
        if ($search) {
            $q->where(fn (Builder $w) => $w->where('subject', 'like', "%{$search}%")
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")));
        }

        // `sla_status` is an appended SLA indicator (support management) : not needed to dispatch.
        return $q->paginate(min(max($perPage, 1), 50))->through(fn (SupportTicket $t) => $t->makeHidden('sla_status'));
    }
}
