<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('docs_meta', function (Blueprint $table) {
            // Change tags column from string to JSON
            // First, convert existing string values to JSON arrays
            $connection = \DB::getDriverName();

            if ($connection === 'sqlite') {
                // SQLite: Convert string to JSON array format
                \DB::statement("UPDATE docs_meta SET tags = '[\"' || tags || '\"]' WHERE tags IS NOT NULL AND tags != ''");
            } else {
                // MySQL/PostgreSQL: Use JSON_ARRAY function
                \DB::statement("UPDATE docs_meta SET tags = JSON_ARRAY(tags) WHERE tags IS NOT NULL AND tags != ''");
            }

            // Change column type to JSON
            $table->json('tags')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('docs_meta', function (Blueprint $table) {
            $connection = \DB::getDriverName();

            if ($connection === 'sqlite') {
                // SQLite: Extract first element from JSON array
                \DB::statement("UPDATE docs_meta SET tags = json_extract(tags, '$[0]') WHERE tags IS NOT NULL");
            } else {
                // MySQL: Convert JSON arrays back to strings (take first element)
                \DB::statement("UPDATE docs_meta SET tags = JSON_UNQUOTE(JSON_EXTRACT(tags, '$[0]')) WHERE tags IS NOT NULL");
            }

            // Change column type back to string
            $table->string('tags')->nullable()->change();
        });
    }
};
