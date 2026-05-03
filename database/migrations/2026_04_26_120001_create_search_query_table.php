<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_query', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')
                ->constrained('languages')
                ->cascadeOnDelete();
            $table->foreignId('type_business_id')
                ->constrained('type_business')
                ->cascadeOnDelete();
            $table->string('text');
            // Флаг завершения парсинга: 0 — не запускался / в процессе,
            // 1 — SearchJob прошёл все страницы и завершился без ошибок.
            // Выставляется в SearchJob::handle() в самом конце успешного прогона.
            $table->boolean('completion_status')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query');
    }
};
