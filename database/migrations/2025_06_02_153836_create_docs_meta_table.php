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
        Schema::create('docs_meta', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('tags')->nullable();
                $table->string('expiry')->default("NO");
                $table->string('description')->nullable();
                $table->integer('max_file_count')->default(1);
                $table->timestamps();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('docs_meta');
    }
};
