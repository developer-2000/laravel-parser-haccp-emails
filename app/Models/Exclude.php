<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Exclude extends Model
{
    protected $table = 'exclude';

    public $timestamps = false;

    protected $fillable = [
        'language_id',
        'keyword',
    ];

    /**
     * Язык, к которому относится стоп-слово.
     */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
