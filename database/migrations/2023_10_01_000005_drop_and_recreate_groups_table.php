<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Schema::dropIfExists('groups');

        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('county')->nullable();
            $table->string('sub_county')->nullable();
            $table->text('address')->nullable();
            $table->string('township')->nullable();
            $table->timestamps();
        });
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
