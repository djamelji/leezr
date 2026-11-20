<?php

namespace App\Modules\Platform\Companies\Creation;

use App\Core\Models\Company;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ADR-834 — trace + idempotency of a company created from Platform. */
class PlatformCompanyCreation extends Model
{
    protected $fillable = ['idempotency_key', 'company_id', 'platform_user_id', 'decisions', 'initialization'];

    protected function casts(): array
    {
        return ['decisions' => 'array', 'initialization' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'platform_user_id');
    }
}
