<?php

namespace App\Core\Documentation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentationFeedback extends Model
{
    const UPDATED_AT = null;

    protected $table = 'documentation_feedbacks';

    protected $fillable = [
        'article_id',
        'user_type',
        'user_id',
        'helpful',
        'comment',
    ];

    protected $casts = [
        'helpful' => 'boolean',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(DocumentationArticle::class, 'article_id');
    }
}
