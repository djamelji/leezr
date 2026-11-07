<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use App\Core\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDashboardWidgetSuggestion extends Model
{
    use BelongsToCompany; // ADR-733.1 (P0-D.1) : scope auto + auto-fill company_id

    protected $fillable = ['company_id', 'module_key', 'widget_key', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
