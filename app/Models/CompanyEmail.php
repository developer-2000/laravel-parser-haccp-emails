<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEmail extends Model
{
    protected $fillable = [
        'company_id',
        'email',
        'letter',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
