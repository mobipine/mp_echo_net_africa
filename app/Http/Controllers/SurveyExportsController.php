<?php

namespace App\Http\Controllers;

use App\Exports\ExportSurveyProgress;
use Maatwebsite\Excel\Facades\Excel;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;

class SurveyExportsController extends Controller
{
  public function export(Request $request, $scope)
{
    $filters = $request->only(['survey_id', 'group_id', 'county_id']);
    $diskName = 'public';
    $directory = 'exports';
    $filenameOnly = "survey_progress_{$scope}_" . now()->format('Y_m_d_H_i_s') . ".xlsx";
    $fullFilePath = $directory . '/' . $filenameOnly;
    $userId = auth()->id();

    Excel::queue(
        new ExportSurveyProgress($scope, $filters, $userId, $diskName, $fullFilePath),
        $fullFilePath,
        $diskName
    );

    Notification::make()
        ->title('Export started')
        ->body('Your survey export has started. You will be notified when it is ready.')
        ->success()
        ->send();

    return redirect()->back();
}

}
