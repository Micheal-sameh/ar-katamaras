<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One stored copy per day, language AND Bible version. 0 = the language's default Bible (existing rows). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_readings', function (Blueprint $table) {
            $table->unsignedInteger('bible_id')->default(0)->after('language');
        });

        Schema::table('daily_readings', function (Blueprint $table) {
            $table->dropUnique(['date', 'language']);
            $table->unique(['date', 'language', 'bible_id']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_readings', function (Blueprint $table) {
            $table->dropUnique(['date', 'language', 'bible_id']);
            $table->unique(['date', 'language']);
        });

        Schema::table('daily_readings', function (Blueprint $table) {
            $table->dropColumn('bible_id');
        });
    }
};
