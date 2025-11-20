<?php
namespace App\Jobs;

use App\Models\Group;
use App\Models\GroupSurvey;
use App\Models\Survey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAllGroupsForSurveyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job's unique lock will be maintained.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public $surveyId,
        public $automated,
        public $startsAt,
        public $endsAt,
        public $channel
    ) {}

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        $startsAtStr = $this->startsAt ? date('Y-m-d-H-i', strtotime($this->startsAt)) : 'now';
        return "process-all-groups-survey-{$this->surveyId}-{$startsAtStr}-{$this->channel}";
    }

    public function handle()
    {
        $survey = Survey::find($this->surveyId);
        Log::info("Processing ALL groups for survey {$survey->title}.");

        Group::chunk(300, function ($groups) use ($survey) {
            foreach ($groups as $group) {
                // Use firstOrCreate to prevent duplicate assignments
                GroupSurvey::firstOrCreate(
                    [
                        'group_id'   => $group->id,
                        'survey_id'  => $survey->id,
                        'starts_at'  => $this->startsAt ?? now(),
                    ],
                    [
                        'automated'       => $this->automated,
                        'ends_at'         => $this->endsAt,
                        'channel'         => $this->channel,
                        'was_dispatched'  => !$this->automated,
                    ]
                );
            }
        });

        Log::info("Finished saving ALL group survey assignments.");

        // If not automated → dispatch messages
        if (!$this->automated) {
            $groupIds = Group::pluck('id')->toArray();

            dispatch(new \App\Jobs\SendSurveyToGroupJob(
                $groupIds,
                $survey,
                $this->channel
            ));

            Log::info("Dispatch job for ALL groups queued.");
        }
    }
}
