<?php

namespace App\Console\Commands;

use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Modules\Workforce\Employees\Services\EmployeeAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;

/**
 * E2E test fixture (LOCAL/TESTING only) — W-HR-UX-4 §2/§4.
 *
 * Grants portal access to an employee through the REAL EmployeeAccessService (creates the User
 * without a password + Membership, exactly like the RH "Donner accès" action), then returns a
 * password-reset token as JSON. That token is the literal content of the invitation e-mail link —
 * on a real environment the employee receives it by e-mail; in the controlled E2E run we surface it
 * here so the browser can drive the REAL /reset-password UI (first-login activation), instead of
 * faking the password server-side. No business rule or auth is bypassed.
 *
 * NOT for production — guarded by environment.
 */
class E2EHrux4GrantAccessCommand extends Command
{
    protected $signature = 'e2e:hrux4-grant-access {--employee=} {--company=1}';

    protected $description = 'Grant portal access to an employee and emit the reset token (the e-mail link) as JSON — local/testing only';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Refused: this fixture command only runs in local/testing.');

            return self::FAILURE;
        }

        $companyId = (int) $this->option('company');
        $employeeId = (int) $this->option('employee');

        $company = Company::withoutGlobalScopes()->findOrFail($companyId);
        $employee = Employee::withoutGlobalScopes()->where('company_id', $companyId)->findOrFail($employeeId);

        if (empty($employee->email)) {
            $this->error("Employee #{$employeeId} has no e-mail — cannot grant access.");

            return self::FAILURE;
        }

        app(EmployeeAccessService::class)->grant($company, $employee, $employee->email, null, 1);

        $user = $employee->fresh()->user;
        $token = Password::broker('users')->createToken($user);

        $this->line(json_encode([
            'user_id' => $user->id,
            'email' => $user->email,
            'token' => $token,
            'reset_url' => "/reset-password?token={$token}&email=".urlencode($user->email),
        ]));

        return self::SUCCESS;
    }
}
