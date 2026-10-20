<?php

namespace Tests\Feature;

use App\Core\Markets\Market;
use App\Core\Models\Company;
use App\Core\Workforce\Employee;
use App\Core\Workforce\EmploymentContract;
use App\Modules\Workforce\UseCases\ActivateContractUseCase;
use App\Modules\Workforce\UseCases\CreateContractUseCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LOT G (ANO-016) — contract integrity invariants (the non-arbitration slice):
 * no genuine period overlap between non-terminated contracts, contiguous succession allowed,
 * activation supersedes the prior active contract, at most one active and one current per employee.
 * (Amendment/avenant versioning and overlap tolerance remain deferred — ADR-574.)
 */
class WorkforceContractIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Market::create(['key' => 'FR', 'name' => 'France', 'currency' => 'EUR', 'locale' => 'fr-FR', 'timezone' => 'Europe/Paris', 'vat_rate_bps' => 2000, 'dial_code' => '+33', 'flag_code' => 'fr', 'flag_svg' => '']);
        $this->company = Company::create(['name' => 'Contract Co', 'slug' => 'contract-co', 'market_key' => 'FR']);
        $this->employee = Employee::create(['company_id' => $this->company->id, 'first_name' => 'C', 'last_name' => 'I', 'status' => Employee::STATUS_ACTIVE, 'hire_date' => '2024-01-01']);
        Model::reguard();
    }

    private function create(string $start, ?string $end, string $type = 'cdd'): EmploymentContract
    {
        return app(CreateContractUseCase::class)->execute(
            employee: $this->employee, contractType: $type, workModelKey: 'horaire', startDate: $start, endDate: $end,
        );
    }

    public function test_overlapping_contract_is_refused(): void
    {
        $this->create('2024-01-01', '2024-12-31');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('overlaps');
        $this->create('2024-06-01', null); // open-ended, overlaps the CDD
    }

    public function test_contiguous_succession_is_allowed(): void
    {
        $cdd = $this->create('2024-01-01', '2024-12-31', 'cdd');
        $cdi = $this->create('2025-01-01', null, 'cdi'); // starts the day after the CDD ends
        $this->assertNotNull($cdi->id);
        $this->assertNotSame($cdd->id, $cdi->id);
    }

    public function test_activation_supersedes_the_prior_active_contract(): void
    {
        $cdd = $this->create('2024-01-01', '2024-12-31', 'cdd');
        app(ActivateContractUseCase::class)->execute($cdd);
        $this->assertSame(EmploymentContract::STATUS_ACTIVE, $cdd->fresh()->status);

        $cdi = $this->create('2025-01-01', null, 'cdi'); // create flips is_current to the CDI
        app(ActivateContractUseCase::class)->execute($cdi);

        // The CDD is superseded (terminated, not current); the CDI is the sole active + current.
        $this->assertSame(EmploymentContract::STATUS_TERMINATED, $cdd->fresh()->status);
        $this->assertFalse((bool) $cdd->fresh()->is_current);
        $this->assertSame(EmploymentContract::STATUS_ACTIVE, $cdi->fresh()->status);
        $this->assertTrue((bool) $cdi->fresh()->is_current);

        $this->assertSame(1, EmploymentContract::where('employee_id', $this->employee->id)->where('status', EmploymentContract::STATUS_ACTIVE)->count());
        $this->assertSame(1, EmploymentContract::where('employee_id', $this->employee->id)->where('is_current', true)->count());
    }
}
