<?php

namespace App\Modules\Core\DataExport;

use App\Core\DataExport\CompanyDataExport;
use App\Core\Models\Company;
use App\Modules\Workforce\Exports\TimeExportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * ADR-845 (Leezr 4.2 M8) — builds the full data archive of ONE company (ZIP on the private disk) :
 * one CSV per dataset (UTF-8 + BOM, « ; »), the authorised document files, and `manifest.json` describing every
 * file (format, rows, period), the exclusions and the datasets that failed (partial failure never hides data :
 * the archive is still produced and the manifest says what is missing).
 *
 * Every query filters `company_id` explicitly. Never exported : password hashes, 2FA secrets, tokens, sessions,
 * API keys, IP addresses / user agents of the audit log, other companies' data.
 */
class CompanyDataExportBuilder
{
    private array $files = [];

    private array $errors = [];

    private array $exclusions = [];

    /** ADR-846 : company data that SHOULD be in the archive but is not — makes the export partial */
    private array $missing = [];

    private int $attempted = 0;

    private ZipArchive $zip;

    public function __construct(private readonly Company $company) {}

    /** @return array{path: string, size: int, manifest: array} */
    public function build(CompanyDataExport $export): array
    {
        $disk = Storage::disk($export->disk);
        $relative = 'company-exports/'.$this->company->id.'/'.Str::uuid().'.zip';
        $disk->makeDirectory(dirname($relative));
        $this->zip = new ZipArchive();
        if ($this->zip->open($disk->path($relative), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('archive could not be created');
        }

        $this->attempt('company', fn () => $this->companyJson());
        $this->attempt('members', fn () => $this->csv('members.csv', 'members', ['last_name', 'first_name', 'email', 'membership', 'role', 'access', 'joined_at'], $this->members()));
        $this->attempt('employees', fn () => $this->csv('workforce/employees.csv', 'employees', ['employee_number', 'last_name', 'first_name', 'email', 'phone', 'status', 'hire_date', 'termination_date', 'department', 'job_role'], $this->employees()));
        $this->attempt('contracts', fn () => $this->csv('workforce/contracts.csv', 'contracts', ['employee_number', 'contract_type', 'start_date', 'end_date', 'weekly_hours', 'status', 'is_current'], $this->contracts()));
        $this->attempt('time', fn () => $this->timeDatasets());
        $this->attempt('shipments', fn () => $this->csv('logistics/shipments.csv', 'shipments', ['reference', 'status', 'origin_address', 'destination_address', 'scheduled_at', 'assigned_to', 'notes', 'created_at'], $this->shipments()));
        $this->attempt('billing', fn () => $this->billing());
        $this->attempt('audit', fn () => $this->csv('audit/company_audit_log.csv', 'audit', ['date', 'action', 'actor_type', 'actor_id', 'target_type', 'target_id', 'severity'], $this->audit()));
        $this->attempt('documents', fn () => $this->documents());

        // ADR-846 : nothing usable produced → total failure (the caller marks the export failed, keeps every other archive)
        if (count($this->errors) === $this->attempted) {
            $this->zip->close();
            $disk->delete($relative);
            throw new \RuntimeException('every dataset failed');
        }
        foreach ($this->errors as $e) {
            $this->missing[] = ['part' => $e['part'], 'reason' => $e['reason'], 'count' => null];
        }

        $this->exclusions = array_merge([
            ['what' => 'passwords, two-factor secrets, sessions, API tokens', 'reason' => 'security secrets — never exported'],
            ['what' => 'IP addresses and user agents of the audit log', 'reason' => 'technical connection data of individual users'],
            ['what' => 'previous versions of documents', 'reason' => 'only the current version of each document is exported'],
            ['what' => 'social declarations (DSN) files', 'reason' => 'available from Payroll › DSN, filed with the authorities'],
            ['what' => 'files still in antivirus quarantine', 'reason' => 'not verified'],
        ], $this->exclusions);

        $manifest = [
            'format_version' => 1,
            'company' => ['id' => $this->company->id, 'name' => $this->company->name],
            'generated_at' => now()->toIso8601String(),
            'requested_by_user_id' => $export->requested_by_user_id,
            'timezone' => $this->company->market?->timezone ?: config('app.timezone'),
            'encoding' => 'CSV UTF-8 with BOM, separator « ; », durations in minutes, amounts in the invoice currency',
            'files' => $this->files,
            'exclusions' => $this->exclusions,
            'errors' => $this->errors,
            'outcome' => $this->missing ? 'partial' : 'complete',
            'missing' => $this->missing,
            'retention_policy' => config('company_lifecycle.retention.markets.'.($this->company->market_key ?? ''), null),
        ];
        $this->zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->zip->close();

        return ['path' => $relative, 'size' => $disk->size($relative), 'manifest' => $manifest];
    }

    // ── datasets ───────────────────────────────────────────────────────────────────────────────────────────

    private function attempt(string $part, callable $fn): void
    {
        $this->attempted++;
        try {
            $fn();
        } catch (\Throwable $e) {
            report($e);
            $this->errors[] = ['part' => $part, 'reason' => 'generation_failed']; // the message may hold internals : not exported
        }
    }

    private function companyJson(): void
    {
        $c = $this->company;
        $data = ['name' => $c->name, 'slug' => $c->slug, 'status' => $c->status, 'siret' => $c->siret, 'naf_code' => $c->naf_code,
            'address' => array_filter(['street' => $c->address_street, 'complement' => $c->address_complement, 'postal_code' => $c->address_postal_code, 'city' => $c->address_city, 'country' => $c->address_country_code]),
            'market' => $c->market_key, 'legal_status' => $c->legal_status_key, 'activity' => $c->jobdomain_key, 'plan' => $c->plan_key,
            'average_headcount' => $c->average_headcount, 'created_at' => $c->created_at?->toIso8601String(),
            'active_modules' => DB::table('company_modules')->where('company_id', $c->id)->where('is_enabled_for_company', true)->orderBy('module_key')->pluck('module_key')->all()];
        $this->zip->addFromString('company.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->files[] = ['path' => 'company.json', 'format' => 'json', 'rows' => 1, 'description' => __('companyExport.files.company')];
    }

    /** @param iterable<array> $rows */
    private function csv(string $path, string $key, array $columns, iterable $rows, ?array $period = null): void
    {
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($c) => __("companyExport.columns.$c"), $columns), ';');
        $n = 0;
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $columns), ';');
            $n++;
        }
        rewind($out);
        $this->zip->addFromString($path, stream_get_contents($out));
        fclose($out);
        $this->files[] = array_filter(['path' => $path, 'format' => 'csv', 'rows' => $n, 'period' => $period, 'description' => __("companyExport.files.$key")], fn ($v) => $v !== null);
    }

    private function members(): \Generator
    {
        $rows = DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->leftJoin('company_roles', 'company_roles.id', '=', 'memberships.company_role_id')
            ->where('memberships.company_id', $this->company->id)
            ->select('users.first_name', 'users.last_name', 'users.email', 'memberships.role', 'company_roles.name as role_name', 'memberships.suspended_at', 'memberships.acceptance_pending_at', 'memberships.created_at')
            ->orderBy('users.last_name')->cursor();
        foreach ($rows as $r) {
            yield ['last_name' => $r->last_name, 'first_name' => $r->first_name, 'email' => $r->email, 'membership' => $r->role, 'role' => $r->role_name,
                'access' => $r->suspended_at ? 'suspended' : ($r->acceptance_pending_at ? 'invitation_pending' : 'active'), 'joined_at' => substr((string) $r->created_at, 0, 10)];
        }
    }

    private function employees(): \Generator
    {
        $rows = DB::table('workforce_employees as e')->leftJoin('workforce_departments as d', 'd.id', '=', 'e.department_id')
            ->leftJoin('workforce_job_roles as j', 'j.id', '=', 'e.job_role_id')
            ->where('e.company_id', $this->company->id)
            ->select('e.employee_number', 'e.last_name', 'e.first_name', 'e.email', 'e.phone', 'e.status', 'e.hire_date', 'e.termination_date', 'd.name as department', 'j.title as job_role')
            ->orderBy('e.last_name')->cursor();
        foreach ($rows as $r) {
            yield (array) $r;
        }
    }

    private function contracts(): \Generator
    {
        $rows = DB::table('workforce_employment_contracts as c')->join('workforce_employees as e', 'e.id', '=', 'c.employee_id')
            ->where('c.company_id', $this->company->id)
            ->select('e.employee_number', 'c.contract_type', 'c.start_date', 'c.end_date', 'c.weekly_hours', 'c.status', 'c.is_current')
            ->orderBy('c.start_date')->cursor();
        foreach ($rows as $r) {
            yield ['is_current' => $r->is_current ? 'yes' : 'no'] + (array) $r;
        }
    }

    /** M7 exports reused, whole history, one file per dataset and year (same columns as the period export). */
    private function timeDatasets(): void
    {
        $bounds = [
            'entries' => ['workforce_time_entries', 'date'], 'daily' => ['workforce_timesheet_lines', 'date'],
            'planning' => ['workforce_shifts', 'date'], 'leaves' => ['workforce_leave_requests', 'date_from'],
        ];
        foreach (['entries', 'breaks', 'daily', 'planning', 'leaves'] as $dataset) {
            [$table, $col] = $bounds[$dataset === 'breaks' ? 'entries' : $dataset];
            $min = DB::table($table)->where('company_id', $this->company->id)->min($col);
            $max = DB::table($table)->where('company_id', $this->company->id)->max($dataset === 'leaves' ? 'date_to' : $col);
            if (! $min) {
                continue;
            }
            for ($year = (int) substr($min, 0, 4); $year <= (int) substr($max, 0, 4); $year++) {
                $service = new TimeExportService($this->company, ['from' => "$year-01-01", 'to' => "$year-12-31"], []); // whole company
                $out = fopen('php://temp', 'w+');
                $rows = $service->writeCsv($out, $dataset, array_map(fn ($c) => __("workforceExport.columns.$c"), TimeExportService::COLUMNS[$dataset]));
                rewind($out);
                $path = "workforce/time/{$dataset}_{$year}.csv";
                $this->zip->addFromString($path, stream_get_contents($out));
                fclose($out);
                $this->files[] = ['path' => $path, 'format' => 'csv', 'rows' => $rows, 'period' => ['from' => "$year-01-01", 'to' => "$year-12-31"],
                    'description' => __("companyExport.files.time_$dataset")];
            }
        }
    }

    private function shipments(): \Generator
    {
        $rows = DB::table('shipments as s')->leftJoin('users as u', 'u.id', '=', 's.assigned_to_user_id')
            ->where('s.company_id', $this->company->id)
            ->select('s.reference', 's.status', 's.origin_address', 's.destination_address', 's.scheduled_at', 's.notes', 's.created_at', 'u.first_name', 'u.last_name')
            ->orderBy('s.created_at')->cursor();
        foreach ($rows as $r) {
            yield ['assigned_to' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')), 'created_at' => substr((string) $r->created_at, 0, 19)] + (array) $r;
        }
    }

    private function billing(): void
    {
        $money = fn ($cents) => $cents === null ? '' : number_format($cents / 100, 2, ',', '');
        $this->csv('billing/invoices.csv', 'invoices', ['number', 'status', 'issued_at', 'due_at', 'paid_at', 'period_start', 'period_end', 'subtotal', 'tax_amount', 'amount', 'amount_due', 'currency'],
            (function () use ($money) {
                foreach (DB::table('invoices')->where('company_id', $this->company->id)->orderBy('issued_at')->cursor() as $i) {
                    yield ['number' => $i->number, 'status' => $i->status, 'issued_at' => substr((string) $i->issued_at, 0, 10), 'due_at' => substr((string) $i->due_at, 0, 10),
                        'paid_at' => substr((string) $i->paid_at, 0, 10), 'period_start' => substr((string) $i->period_start, 0, 10), 'period_end' => substr((string) $i->period_end, 0, 10),
                        'subtotal' => $money($i->subtotal), 'tax_amount' => $money($i->tax_amount), 'amount' => $money($i->amount), 'amount_due' => $money($i->amount_due), 'currency' => $i->currency];
                }
            })());
        $this->csv('billing/subscriptions.csv', 'subscriptions', ['plan', 'interval', 'status', 'contracted_price', 'period_start', 'period_end', 'cancel_at_period_end', 'created_at'],
            (function () use ($money) {
                foreach (DB::table('subscriptions')->where('company_id', $this->company->id)->orderBy('created_at')->cursor() as $s) {
                    yield ['plan' => $s->plan_key, 'interval' => $s->interval, 'status' => $s->status, 'contracted_price' => $money($s->plan_price_cents),
                        'period_start' => substr((string) $s->current_period_start, 0, 10), 'period_end' => substr((string) $s->current_period_end, 0, 10),
                        'cancel_at_period_end' => $s->cancel_at_period_end ? 'yes' : 'no', 'created_at' => substr((string) $s->created_at, 0, 10)];
                }
            })());
    }

    private function audit(): \Generator
    {
        foreach (DB::table('company_audit_logs')->where('company_id', $this->company->id)->orderBy('created_at')->cursor() as $a) {
            yield ['date' => (string) $a->created_at, 'action' => $a->action, 'actor_type' => $a->actor_type, 'actor_id' => $a->actor_id,
                'target_type' => $a->target_type, 'target_id' => $a->target_id, 'severity' => $a->severity];
        }
    }

    /** current version of every stored document of the company, within the size budget ; the rest is listed as excluded */
    private function documents(): void
    {
        $budget = (int) config('company_lifecycle.export.max_document_bytes');
        $used = 0;
        $index = [];
        $sources = [
            'member_documents' => ['dir' => 'documents/personnel', 'name' => 'file_name', 'kind' => 'personal_document'],
            'company_documents' => ['dir' => 'documents/company', 'name' => 'file_name', 'kind' => 'company_document'],
            'generated_documents' => ['dir' => 'documents/generated', 'name' => null, 'kind' => 'generated_document'],
            'published_payslips' => ['dir' => 'documents/payslips', 'name' => 'file_name', 'kind' => 'payslip'],
        ];
        $disk = Storage::disk('local');
        $skipped = 0;
        $absent = 0;
        foreach ($sources as $table => $src) {
            $q = DB::table($table)->where('company_id', $this->company->id)->whereNotNull('file_path');
            if ($table === 'published_payslips') {
                $q->whereNull('superseded_at')->whereNull('revoked_at');
            }
            foreach ($q->orderBy('id')->cursor() as $d) {
                $name = $src['name'] ? ($d->{$src['name']} ?: basename($d->file_path)) : basename($d->file_path);
                $target = $src['dir'].'/'.$d->id.'_'.Str::limit(preg_replace('/[^\w.\- ]+/u', '_', $name), 120, '');
                if (str_contains($d->file_path, '..') || ! $disk->exists($d->file_path)) {
                    $absent++;
                    $index[] = ['kind' => $src['kind'], 'id' => $d->id, 'file' => '', 'status' => 'missing_file'];

                    continue;
                }
                $size = $disk->size($d->file_path);
                if ($used + $size > $budget) {
                    $skipped++;
                    $index[] = ['kind' => $src['kind'], 'id' => $d->id, 'file' => '', 'status' => 'over_size_budget'];

                    continue;
                }
                $this->zip->addFile($disk->path($d->file_path), $target);
                $used += $size;
                $index[] = ['kind' => $src['kind'], 'id' => $d->id, 'file' => $target, 'status' => 'included'];
            }
        }
        $this->csv('documents/index.csv', 'documents', ['kind', 'id', 'file', 'status'], $index);
        if ($skipped) {
            $this->exclusions[] = ['what' => "$skipped document file(s)", 'reason' => 'beyond the size budget of one archive — listed in documents/index.csv, ask support for a complement'];
            $this->missing[] = ['part' => 'documents', 'reason' => 'over_size_budget', 'count' => $skipped];
        }
        if ($absent) {
            $this->missing[] = ['part' => 'documents', 'reason' => 'missing_file', 'count' => $absent];
        }
    }
}
