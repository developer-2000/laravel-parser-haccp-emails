<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\JobStatusController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\SearchQueryController;
use App\Http\Controllers\TypeBusinessController;
use Illuminate\Support\Facades\Route;

Route::get('/languages', [LanguageController::class, 'index']);
Route::post('/language', [LanguageController::class, 'set']);

Route::post('/query', [QueryController::class, 'makeQuery']);

Route::get('/type-businesses', [TypeBusinessController::class, 'index']);

Route::get('/search-queries', [SearchQueryController::class, 'index']);
Route::post('/search-queries', [SearchQueryController::class, 'store']);

Route::get('/jobs/status', [JobStatusController::class, 'index']);

Route::get('/backup/daily', [BackupController::class, 'daily']);

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!horizon|languages|language|query|type-businesses|search-queries|jobs|backup).*$');
