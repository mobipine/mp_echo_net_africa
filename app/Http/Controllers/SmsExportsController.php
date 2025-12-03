<?php

namespace App\Http\Controllers;

use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ExportSmsRecords;
use Filament\Notifications\Notification;

class SmsExportsController extends Controller
{
    public function index($scope)
    {
        
        $diskName = 'public';
        // 1. The path MUST include the directory relative to the 'public' disk root (storage/app/public).
        $directory = 'exports';
        $filenameOnly = "sms_records_{$scope}_" . now()->format('Y_m_d_H_i_s') . ".xlsx";
        $fullFilePath = $directory . '/' . $filenameOnly; // exports/sms_records_...xlsx
        $userId = auth()->id();

        // Queue the export, passing all necessary details
        Excel::queue(
            // Pass the disk and file path to the export constructor
            new ExportSmsRecords($scope, $userId, $diskName, $fullFilePath),
            $fullFilePath, // <-- This is the path relative to the disk
            $diskName
        );
        
        // Notification for immediate feedback
        Notification::make()
        ->title('Export started')
        ->body('Your Excel export has started. You will be notified when it is ready.')
        ->success()
        ->send();

        // Return immediate success response
        return redirect('admin/sms-reports');
    }
}