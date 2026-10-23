<?php

namespace App\Modules\Platform\Dashboard\Http;

use App\Core\Billing\Invoice;
use App\Core\Models\Company;
use App\Core\Models\User;
use App\Core\Support\SupportTicket;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ADR-467: Platform global search (header search bar).
 *
 * Multi-table search across Companies, Users, Invoices, Support Tickets.
 * Returns grouped results matching the AppBarSearch component format.
 *
 * ADR-595 (LOT 1): each result category is gated by the caller's effective permission so
 * global search can never leak a resource the user is not otherwise authorized to see
 * (e.g. a Support agent must not discover invoice numbers). Super admin bypasses via hasPermission.
 */
class PlatformSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim($request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $user = $request->user('platform');
        $can = fn (string $permission): bool => $user !== null && $user->hasPermission($permission);

        $like = '%' . $q . '%';
        $results = [];

        // 1. Companies — requires company visibility
        $companies = $can('manage_companies') || $can('view_company_users')
            ? Company::where('name', 'LIKE', $like)->limit(5)->get(['id', 'name'])
            : collect();

        if ($companies->isNotEmpty()) {
            $results[] = [
                'title' => 'Companies',
                'children' => $companies->map(fn ($c) => [
                    'title' => $c->name,
                    'icon' => 'tabler-building',
                    'url' => [
                        'name' => 'platform-companies-id',
                        'params' => ['id' => $c->id],
                    ],
                ])->values()->all(),
            ];
        }

        // 2. Users (company members) — requires user visibility
        $users = $can('view_company_users') || $can('manage_platform_users')
            ? User::where('email', 'LIKE', $like)
                ->orWhere('first_name', 'LIKE', $like)
                ->orWhere('last_name', 'LIKE', $like)
                ->limit(5)
                ->get(['id', 'first_name', 'last_name', 'email'])
            : collect();

        if ($users->isNotEmpty()) {
            $results[] = [
                'title' => 'Users',
                'children' => $users->map(fn ($u) => [
                    'title' => $u->display_name . ' (' . $u->email . ')',
                    'icon' => 'tabler-user',
                    'url' => [
                        'name' => 'platform-access-tab',
                        'params' => ['tab' => 'users'],
                    ],
                ])->values()->all(),
            ];
        }

        // 3. Invoices — requires billing visibility
        $invoices = $can('view_billing')
            ? Invoice::withoutCompanyScope()
                ->where('number', 'LIKE', $like)
                ->limit(5)
                ->get(['id', 'number', 'company_id', 'amount', 'status'])
            : collect();

        if ($invoices->isNotEmpty()) {
            $results[] = [
                'title' => 'Invoices',
                'children' => $invoices->map(fn ($inv) => [
                    'title' => $inv->display_number . ' — ' . $inv->status,
                    'icon' => 'tabler-file-invoice',
                    'url' => [
                        'name' => 'platform-billing-invoices-id',
                        'params' => ['id' => $inv->id],
                    ],
                ])->values()->all(),
            ];
        }

        // 4. Support Tickets — requires support visibility
        $tickets = $can('manage_support')
            ? SupportTicket::withoutCompanyScope()
                ->where('subject', 'LIKE', $like)
                ->limit(5)
                ->get(['id', 'subject', 'status', 'priority'])
            : collect();

        if ($tickets->isNotEmpty()) {
            $results[] = [
                'title' => 'Support Tickets',
                'children' => $tickets->map(fn ($t) => [
                    'title' => $t->subject,
                    'icon' => 'tabler-headset',
                    'url' => [
                        'name' => 'platform-support-id',
                        'params' => ['id' => $t->id],
                    ],
                ])->values()->all(),
            ];
        }

        return response()->json($results);
    }
}
