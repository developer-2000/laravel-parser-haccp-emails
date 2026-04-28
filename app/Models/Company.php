<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'url',
        'name',
        'emails',
        'phones',
        'raw_checked',
    ];

    protected $casts = [
        'emails' => 'array',
        'phones' => 'array',
        'raw_checked' => 'boolean',
    ];
}
