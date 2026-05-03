<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyEmailController;
use App\Http\Controllers\JobStatusController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\QueryController;
use App\Http\Controllers\SearchQueryController;
use App\Http\Controllers\TypeBusinessController;
use Illuminate\Support\Facades\Route;

// Список доступных языков интерфейса/парсинга — наполняет селектор языка.
Route::get('/languages', [LanguageController::class, 'index']);
// Сохраняет выбранный язык в сессии/куке (для последующих запросов).
Route::post('/language', [LanguageController::class, 'set']);

// Запускает парсинг для выбранного SearchQuery: ставит SearchJob в очередь
// search и чистит логи прошлого прогона.
Route::post('/query', [QueryController::class, 'makeQuery']);

// Список типов бизнеса — наполняет селектор «Тип бизнеса» на главной.
Route::get('/type-businesses', [TypeBusinessController::class, 'index']);

// Поисковые запросы для пары (type_business + language).
// GET    — список (для подстановки одной существующей записи в input).
// POST   — создать новый (firstOrCreate по тройке type+lang+text).
// PUT    — обновить text существующего запроса по id.
Route::get('/search-queries', [SearchQueryController::class, 'index']);
Route::post('/search-queries', [SearchQueryController::class, 'store']);
Route::put('/search-queries/{id}', [SearchQueryController::class, 'update'])
    ->whereNumber('id');

// Компании, найденные по конкретному SearchQuery.
// GET    — список (с флагом with_trashed для показа мягко удалённых).
// PUT    — обновить поля компании (сейчас — только name из модалки).
// DELETE — мягкое удаление (проставляет deleted_at, запись остаётся в БД).
Route::get('/companies', [CompanyController::class, 'index']);
Route::put('/companies/{id}', [CompanyController::class, 'update'])
    ->whereNumber('id');
Route::delete('/companies/{id}', [CompanyController::class, 'destroy'])
    ->whereNumber('id');

// Письма (letter), отправленные на email'ы компаний.
// GET  — история писем для пары (company_id + email) → гармошка в модалке.
// POST — сохранить новое письмо в company_emails.
Route::get('/company-emails', [CompanyEmailController::class, 'index']);
Route::post('/company-emails', [CompanyEmailController::class, 'store']);

// Статус очередей search/crawl: фронт опрашивает, чтобы понять, идёт ли сейчас
// парсинг (адаптивный polling, см. Home.vue).
Route::get('/jobs/status', [JobStatusController::class, 'index']);

// Тихий ежедневный бэкап БД. Идемпотентен по дате — если backup_YYYY-MM-DD.sql
// уже существует, ничего не делает. Дёргается фоном при заходе на главную.
Route::get('/backup/daily', [BackupController::class, 'daily']);

// SPA-фоллбек: всё, что не попало в API-маршруты выше и не horizon —
// отдаёт корневой view, чтобы Vue Router сам разрулил путь на клиенте.
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!horizon|languages|language|query|type-businesses|search-queries|companies|company-emails|jobs|backup).*$');
