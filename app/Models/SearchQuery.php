<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchQuery extends Model
{
    protected $table = 'search_query';

    protected $fillable = [
        'language_id',
        'type_business_id',
        'text',
        'completion_status',
    ];

    protected $casts = [
        'completion_status' => 'boolean',
    ];

    /**
     * Язык, к которому привязан запрос.
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /**
     * Тип бизнеса, к которому привязан запрос.
     */
    public function typeBusiness(): BelongsTo
    {
        return $this->belongsTo(TypeBusiness::class);
    }
}
