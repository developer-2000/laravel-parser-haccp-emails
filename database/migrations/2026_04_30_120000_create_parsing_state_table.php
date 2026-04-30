<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parsing_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')
                ->constrained('languages');
            $table->foreignId('type_business_id')
                ->constrained('type_business');
            $table->tinyInteger('completion_status')->default(0);
            $table->json('next_page_params')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parsing_state');
    }
};
