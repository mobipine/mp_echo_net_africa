<div class="space-y-4">
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Question Text</h3>
        <p class="text-gray-700 dark:text-white">{{ $question->question }}</p>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Answer Data Type</h3>
            <p class="text-gray-700 dark:text-white">{{ $question->answer_data_type ?? 'N/A' }}</p>
        </div>

        <div>
            <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Answer Strictness</h3>
            <p class="text-gray-700 dark:text-white">{{ $question->answer_strictness ?? 'N/A' }}</p>
        </div>

        <div>
            <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Purpose</h3>
            <p class="text-gray-700 dark:text-white">{{ $question->purpose ?? 'N/A' }}</p>
        </div>

        <div>
            <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Position</h3>
            <p class="text-gray-700 dark:text-white">{{ $question->pivot->position ?? 'N/A' }}</p>
        </div>
    </div>

    @if($question->data_type_violation_response)
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Data Type Violation Response</h3>
        <p class="text-gray-700 dark:text-white">{{ $question->data_type_violation_response }}</p>
    </div>
    @endif

    @if($question->possible_answers && is_array($question->possible_answers) && count($question->possible_answers) > 0)
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Possible Answers</h3>
        <ul class="list-disc list-inside space-y-1">
            @foreach($question->possible_answers as $answer)
                <li class="text-gray-700 dark:text-white">
                    @if(is_string($answer))
                        {{ $answer }}
                    @elseif(is_array($answer))
                        {{ json_encode($answer) }}
                    @else
                        {{ (string) $answer }}
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
    @endif

    @if($question->swahili_question_id)
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Kiswahili Alternative</h3>
        @php
            $swahiliQuestion = $question->swahiliQuestion;
        @endphp
        @if($swahiliQuestion && $swahiliQuestion->id != $question->id)
            <p class="text-gray-700 dark:text-white">{{ $swahiliQuestion->question }}</p>
        @else
            <p class="text-gray-500 dark:text-gray-400 italic">No Alternative (English-only question)</p>
        @endif
    </div>
    @endif

    @if($question->question_interval)
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Question Interval</h3>
        <p class="text-gray-700 dark:text-white">
            {{ $question->question_interval }} 
            {{ $question->question_interval_unit ?? 'days' }}
        </p>
    </div>
    @endif

    @if($question->is_recurrent)
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-white mb-2">Recurrence Settings</h3>
        <p class="text-gray-700 dark:text-white">
            Repeats every {{ $question->recur_interval ?? 'N/A' }} 
            {{ $question->recur_unit ?? 'N/A' }}
            @if($question->recur_times)
                ({{ $question->recur_times }} times)
            @endif
        </p>
    </div>
    @endif
</div>
