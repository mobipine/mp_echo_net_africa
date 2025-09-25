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
        Schema::create('member_edit_requests', function (Blueprint $table) {
            $table->id();
            $table->string("name")->nullable();
            $table->string("phone_number")->nullable();
            $table->integer("national_id")->nullable();
            $table->integer("year_of_birth")->nullable();
            $table->string("gender")->nullable();
            $table->string("group")->nullable();
            $table->string("status")->default("pending");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_edit_requests');
    }
};
