<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/** PPT overrides were removed. Databases created before that still have the (empty) table. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('reading_overrides');
    }

    public function down(): void
    {
        // Not restored: the feature no longer exists.
    }
};
