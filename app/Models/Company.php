<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends Model
{
    protected $fillable = [
        'url',
        'name',
        'emails',
        'phones',
        'tier',
        'search_query_id',
        'raw_checked',
    ];

    protected $casts = [
        'emails' => 'array',
        'phones' => 'array',
        'tier' => 'integer',
        'raw_checked' => 'boolean',
    ];

    /**
     * Набор поиска, в рамках которого была найдена компания.
     */
    public function searchQuerySet(): BelongsTo
    {
        return $this->belongsTo(SearchQuery::class);
    }
}
