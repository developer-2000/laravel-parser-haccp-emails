<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Query extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'language_id',
        'keyword',
    ];

    /**
     * Язык, к которому относится поисковый запрос.
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
