<?php

namespace App\Http\Controllers;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ExportSmsRecords;

class SmsExportsController extends Controller
{
    public function index($scope)
    {
        // sanitize scope
        $allowedScopes = ['failed', 'sent','SendingFailed','DeliveredToTerminal','SenderName Blacklisted','AbsentSubscriber','DeliveryImpossible','DeliveredToNetwork'];
        if (!in_array($scope, $allowedScopes)) {
            abort(404, "Invalid export scope.");
        }

        // Map scope to database filter
        $mapping = [
            'failed'    => 'failed',
            'sent'      => 'sent',
            'SendingFailed' => 'SendingFailed',
            'DeliveredToTerminal' => 'DeliveredToTerminal',
            'SenderName Blacklisted' => 'SenderName Blacklisted',
            'AbsentSubscriber' => 'AbsentSubscriber',
            'DeliveryImpossible' => 'DeliveryImpossible',
            'DeliveredToNetwork' => 'DeliveredToNetwork'
        ];

        $status = $mapping[$scope];

        return Excel::download(
            new ExportSmsRecords($status),
            "sms_records_{$scope}_" . now()->format('Y_m_d_H_i_s') . ".xlsx"
        );
    }
}
