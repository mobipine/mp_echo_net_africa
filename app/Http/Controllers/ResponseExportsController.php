<?php

namespace App\Http\Controllers;

use App\Exports\ExportResponseRecords;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Http\Request;

class ResponseExportsController extends Controller
{
    public function export(Request $request)
    {
        $surveyId   = $request->query('survey_id');
        $questionId = $request->query('question_id');
        $answer     = $request->query('answer'); // optional, null = total responses

        $fileName = 'survey_' . ($surveyId ?? 'all') 
                  . '_question_' . ($questionId ?? 'all')
                  . ($answer ? '_answer_' . str_replace(' ', '_', $answer) : '')
                  . '_' . now()->format('Y_m_d_H_i_s') . '.xlsx';

        return Excel::download(
            new ExportResponseRecords($surveyId, $questionId, $answer),
            $fileName
        );
    }
}
