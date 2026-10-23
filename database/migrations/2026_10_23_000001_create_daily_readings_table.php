<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_readings', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedTinyInteger('language'); // App\Enums\Language (Katameros languageId)
            $table->string('coptic_date', 20);        // "13/2/1743" (day/month/year)
            $table->json('payload');
            $table->string('source', 10);             // App\Enums\ReadingSource
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['date', 'language']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('daily_readings');
    }
};
