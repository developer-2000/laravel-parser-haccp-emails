<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('url')->unique();
            $table->string('name')->nullable();
            $table->json('emails')->nullable();
            $table->json('phones')->nullable();
            // tier классификации лида (0..3): 0=ignore, 1=weak, 2=good, 3=ideal
            $table->unsignedTinyInteger('tier')->nullable()->index();
            $table->foreignId('search_query_id')->nullable()->constrained('search_query');
            $table->boolean('raw_checked')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
