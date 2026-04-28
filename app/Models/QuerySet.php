<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuerySet extends Model
{
    protected $fillable = [
        'language_id',
        'name',
        'description',
        'query_ids',
        'group_ids',
        'exclude_ids',
        'is_active',
    ];

    protected $casts = [
        'query_ids' => 'array',
        'group_ids' => 'array',
        'exclude_ids' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Язык, к которому привязан набор конфигурации.
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
