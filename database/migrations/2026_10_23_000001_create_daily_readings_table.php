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

        Schema::create('reading_overrides', function (Blueprint $table) {
            $table->id();
            $table->date('date')->nullable();          // match by Gregorian date ...
            $table->string('coptic_key', 40)->nullable(); // ... or by Coptic day "MM-DD" / feast key
            $table->unsignedTinyInteger('language');
            $table->json('payload');
            $table->string('source_file');
            $table->string('file_hash', 64);
            $table->timestamps();

            $table->unique(['date', 'language']);
            $table->unique(['coptic_key', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_overrides');
        Schema::dropIfExists('daily_readings');
    }
};
