<?php

namespace App\Modules\Workforce\Documents\Security;

use App\Core\Documents\GeneratedDocument;
use App\Modules\Workforce\Signature\Models\SignatureRequest;
use Closure;
use Illuminate\Http\Request;

/**
 * ADR-771 — applies GeneratedDocumentAccess to the routes that stream a generated document or its
 * signed version. `signature` mode : the route id is a signature request (its document is checked).
 * Unknown or foreign ids fall through to the controller, which answers 404 inside the tenant.
 */
class EnsureCanOpenGeneratedDocument
{
    public function handle(Request $request, Closure $next, string $mode = 'document')
    {
        $companyId = (int) $request->attributes->get('company_id');
        $id = (int) $request->route('id');

        $documentId = $mode === 'signature'
            ? SignatureRequest::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $id)->value('generated_document_id')
            : $id;
        $document = $documentId
            ? GeneratedDocument::withoutGlobalScopes()->where('company_id', $companyId)->where('id', $documentId)->get()->first()
            : null;

        abort_if($document !== null && ! GeneratedDocumentAccess::canOpen($request, $document), 403, 'Not authorized to open this document.');

        return $next($request);
    }
}
