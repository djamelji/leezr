<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDashboardLayout extends Model
{
    protected $fillable = ['company_id', 'layout_json'];

    protected function casts(): array
    {
        return [
            'layout_json' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
