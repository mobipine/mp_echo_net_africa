<?php

use App\Support\SurveyProgressState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('survey_progress', function (Blueprint $table) {
            $table->uuid('dispatch_batch_uuid')->nullable()->after('channel');
            $table->string('open_progress_guard')->nullable()->after('dispatch_batch_uuid');
        });

        DB::table('survey_progress')->get()->each(function ($progress) {
            DB::table('survey_progress')
                ->where('id', $progress->id)
                ->update([
                    'open_progress_guard' => SurveyProgressState::guardFor($progress->status, $progress->completed_at),
                ]);
        });

        Schema::table('survey_progress', function (Blueprint $table) {
            $table->unique(['survey_id', 'member_id', 'open_progress_guard'], 'survey_progress_open_unique');
            $table->index('dispatch_batch_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('survey_progress', function (Blueprint $table) {
            $table->dropUnique('survey_progress_open_unique');
            $table->dropIndex(['dispatch_batch_uuid']);
            $table->dropColumn(['dispatch_batch_uuid', 'open_progress_guard']);
        });
    }
};
