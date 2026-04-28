<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Language extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Поисковые запросы, привязанные к языку.
     */
    public function queries(): HasMany
    {
        return $this->hasMany(Query::class);
    }

    /**
     * Группы ключевых слов, привязанные к языку.
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /**
     * Стоп-слова (исключения), привязанные к языку.
     */
    public function excludes(): HasMany
    {
        return $this->hasMany(Exclude::class);
    }

    /**
     * Сохранённые наборы поисковых конфигураций для языка.
     */
    public function querySets(): HasMany
    {
        return $this->hasMany(QuerySet::class);
    }
}
