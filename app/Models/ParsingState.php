<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Прогресс парсинга для пары (language_id + type_business_id).
 *
 * Уникальный ключ — это пара, а НЕ search_query_id. Продуктовое решение:
 * для одной пары "язык + тип бизнеса" существует один прогон парсинга;
 * разные SearchQuery с одной такой парой считаются разными формулировками
 * одного и того же поиска и не порождают отдельных ParsingState.
 *
 * Поля:
 *   - completion_status: 0 = идёт / прерван, 1 = завершён.
 *   - next_page_params:  cursor (hidden-поля формы Next из DDG) для возобновления
 *                        прерванного парсинга с конкретной страницы выдачи.
 */
class ParsingState extends Model
{
    protected $table = 'parsing_state';

    protected $fillable = [
        'language_id',
        'type_business_id',
        'completion_status',
        'next_page_params',
    ];

    protected $casts = [
        'completion_status' => 'integer',
        'next_page_params' => 'array',
    ];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function typeBusiness(): BelongsTo
    {
        return $this->belongsTo(TypeBusiness::class);
    }
}
