<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
