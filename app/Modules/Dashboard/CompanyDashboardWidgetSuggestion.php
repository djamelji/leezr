<?php

namespace App\Modules\Dashboard;

use App\Core\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDashboardWidgetSuggestion extends Model
{
    protected $fillable = ['company_id', 'module_key', 'widget_key', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
