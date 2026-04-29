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
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_query');
    }
};
