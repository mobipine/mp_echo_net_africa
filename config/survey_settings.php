<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Survey Messages Enabled
    |--------------------------------------------------------------------------
    |
    | This setting controls whether survey-related messages can be sent.
    | When set to false, all survey commands will skip message sending.
    | This includes:
    | - dispatch:sms (SendSMS command)
    | - process:surveys-progress (ProcessSurveyProgressCommand)
    | - surveys:due-dispatch (DispatchDueSurveysCommand)
    |
    | To disable sending: Set to false
    | To enable sending: Set to true (default)
    |
    */

    'messages_enabled' => env('SURVEY_MESSAGES_ENABLED', true),

];

