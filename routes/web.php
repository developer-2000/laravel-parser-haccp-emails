<?php

use App\Http\Controllers\LanguageController;
use App\Http\Controllers\QueryController;
use Illuminate\Support\Facades\Route;

Route::get('/languages', [LanguageController::class, 'index']);
Route::post('/language', [LanguageController::class, 'set']);

Route::post('/query', [QueryController::class, 'show']);

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!horizon|languages|language|query).*$');
