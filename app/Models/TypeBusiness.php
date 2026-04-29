<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TypeBusiness extends Model
{
    protected $table = 'type_business';

    protected $fillable = [
        'name',
    ];

    /**
     * Поисковые запросы, относящиеся к данному типу бизнеса.
     */
    public function searchQueries(): HasMany
    {
        return $this->hasMany(SearchQuery::class);
    }
}
