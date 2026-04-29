<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchQuery extends Model
{
    protected $table = 'search_query';

    protected $fillable = [
        'language_id',
        'type_business_id',
        'text',
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

    /**
     * Конфигурации (наборы) поиска, использующие этот запрос.
     */
    public function querySets(): HasMany
    {
        return $this->hasMany(QuerySet::class);
    }
}
