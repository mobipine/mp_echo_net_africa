<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration is no longer needed as groups table is already created
        // by 2022_06_02_073958_create_groups_table migration
        // Keeping this file for migration history but doing nothing
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
