<?php

namespace App\Modules\Dashboard;

use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformUserDashboardLayout extends Model
{
    protected $fillable = ['user_id', 'layout_json'];

    protected function casts(): array
    {
        return [
            'layout_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class, 'user_id');
    }
}
