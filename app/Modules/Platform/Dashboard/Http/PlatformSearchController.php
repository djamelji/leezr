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
 */
class PlatformSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim($request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $like = '%' . $q . '%';
        $results = [];

        // 1. Companies
        $companies = Company::where('name', 'LIKE', $like)
            ->limit(5)
            ->get(['id', 'name']);

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

        // 2. Users (company members)
        $users = User::where('email', 'LIKE', $like)
            ->orWhere('first_name', 'LIKE', $like)
            ->orWhere('last_name', 'LIKE', $like)
            ->limit(5)
            ->get(['id', 'first_name', 'last_name', 'email']);

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

        // 3. Invoices
        $invoices = Invoice::withoutCompanyScope()
            ->where('number', 'LIKE', $like)
            ->limit(5)
            ->get(['id', 'number', 'company_id', 'amount', 'status']);

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

        // 4. Support Tickets
        $tickets = SupportTicket::withoutCompanyScope()
            ->where('subject', 'LIKE', $like)
            ->limit(5)
            ->get(['id', 'subject', 'status', 'priority']);

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
