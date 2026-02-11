<?php

namespace App\Company\Http\Middleware;

use App\Core\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-Id');

        if (!$companyId) {
            return response()->json([
                'message' => 'X-Company-Id header is required.',
            ], 400);
        }

        $company = Company::find($companyId);

        if (!$company) {
            return response()->json([
                'message' => 'Company not found.',
            ], 404);
        }

        if (!$request->user()->isMemberOf($company)) {
            return response()->json([
                'message' => 'You are not a member of this company.',
            ], 403);
        }

        $request->merge(['company' => $company]);
        $request->attributes->set('company', $company);

        return $next($request);
    }
}
