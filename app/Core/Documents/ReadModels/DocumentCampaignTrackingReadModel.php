<?php

namespace App\Core\Documents\ReadModels;

use App\Core\Documents\DocumentCampaign;
use App\Core\Documents\DocumentCampaignRecipient;
use App\Core\Documents\DocumentRequest;
use App\Core\Documents\MemberDocument;
use App\Core\Documents\Services\DocumentComplianceStateService as State;
use App\Core\Workforce\Employee;

/**
 * ADR-761 (DMS M4) — suivi d'une campagne. Agrège les destinataires via leurs demandes + documents
 * (DocumentComplianceStateService). La PARTITION (pending/received/validated/rejected) est
 * mutuellement exclusive ; overdue / expired / account_less sont des DIMENSIONS complémentaires.
 * Pagination serveur (ne charge pas toutes les pièces).
 */
class DocumentCampaignTrackingReadModel
{
    public static function summary(DocumentCampaign $campaign): array
    {
        $recips = DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)->get();
        $requestIds = $recips->pluck('document_request_id')->filter()->all();
        $requests = DocumentRequest::whereIn('id', $requestIds)->get()->keyBy('id');

        $keyOf = fn ($st, $sid) => $st.':'.$sid.':'.$campaign->document_type_id;
        $docs = MemberDocument::where('company_id', $campaign->company_id)
            ->where('document_type_id', $campaign->document_type_id)
            ->get()
            ->keyBy(fn ($d) => ($d->document_subject_type ?: ($d->user_id ? 'user' : null)).':'.($d->document_subject_id ?: $d->user_id).':'.$d->document_type_id);

        $part = ['pending' => 0, 'received' => 0, 'validated' => 0, 'rejected' => 0];
        $dim = ['overdue' => 0, 'expired' => 0, 'account_less' => 0];

        foreach ($recips as $r) {
            if ($r->document_subject_type === 'employee') {
                $dim['account_less']++;
            }
            $req = $r->document_request_id ? $requests->get($r->document_request_id) : null;
            $doc = $docs->get($keyOf($r->document_subject_type, $r->document_subject_id));
            $state = State::evaluate($doc, $req?->status);

            // Partition priorisée (un seul bucket).
            if ($req?->status === DocumentRequest::STATUS_REJECTED) {
                $part['rejected']++;
            } elseif ($req?->status === DocumentRequest::STATUS_APPROVED) {
                $part['validated']++;
            } elseif ($state['has_file']) {
                $part['received']++;
            } else {
                $part['pending']++;
            }

            // Dimensions complémentaires.
            if ($req && $req->isOverdue()) {
                $dim['overdue']++;
            }
            if ($state['validity'] === State::L_EXPIRED) {
                $dim['expired']++;
            }
        }

        $total = $recips->count();

        return [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'document_type' => $campaign->documentType?->code,
                'document_label' => $campaign->documentType?->label,
                'due_date' => $campaign->due_date?->toDateString(),
                'launched_at' => $campaign->launched_at?->toIso8601String(),
                'recipients_total' => $total,
            ],
            // Partition mutuellement exclusive.
            'partition' => $part,
            // Dimensions complémentaires (non additives avec la partition).
            'dimensions' => $dim,
            'progress' => $total > 0 ? round($part['validated'] / $total * 100, 1) : 0,
        ];
    }

    /** Liste paginée des destinataires (recherche serveur, pas de chargement global des pièces). */
    public static function recipients(DocumentCampaign $campaign, int $page = 1, int $perPage = 25): array
    {
        $query = DocumentCampaignRecipient::where('document_campaign_id', $campaign->id)->orderBy('id');
        $total = $query->count();
        $rows = $query->forPage($page, $perPage)->get();

        $requests = DocumentRequest::whereIn('id', $rows->pluck('document_request_id')->filter())->get()->keyBy('id');
        $employeeIds = $rows->where('document_subject_type', 'employee')->pluck('document_subject_id')->all();
        $employees = $employeeIds ? Employee::withoutGlobalScopes()->whereIn('id', $employeeIds)->get()->keyBy('id') : collect();
        $userIds = $rows->where('document_subject_type', 'user')->pluck('document_subject_id')->all();
        $users = $userIds ? \App\Core\Models\User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id') : collect();

        $items = $rows->map(function ($r) use ($requests, $employees, $users) {
            $req = $r->document_request_id ? $requests->get($r->document_request_id) : null;
            $person = $r->document_subject_type === 'employee' ? $employees->get($r->document_subject_id) : $users->get($r->document_subject_id);

            return [
                'id' => $r->id,
                'person_ref' => $r->document_subject_type.'-'.$r->document_subject_id,
                'name' => $person ? trim(($person->first_name ?? '').' '.($person->last_name ?? '')) : null,
                'account_less' => $r->document_subject_type === 'employee',
                'state' => $r->state,
                'request_status' => $req?->status,
                'is_overdue' => (bool) $req?->isOverdue(),
            ];
        })->values();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }
}
