<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

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

    /**
     * Письма, отправленные на email'ы этой компании. На один email может быть
     * несколько писем (история переписки), поэтому hasMany.
     */
    public function companyEmails(): HasMany
    {
        return $this->hasMany(CompanyEmail::class);
    }
}
