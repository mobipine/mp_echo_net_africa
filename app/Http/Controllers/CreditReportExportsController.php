<?php

namespace App\Http\Controllers;

use App\Models\CreditReportExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditReportExportsController extends Controller
{
    public function download(Request $request, CreditReportExport $creditReportExport): RedirectResponse|StreamedResponse
    {
        if (! $request->user()) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        abort_unless((int) $creditReportExport->user_id === (int) $request->user()?->id, 403);
        abort_unless($creditReportExport->isDownloadable(), 404);

        $disk = Storage::disk($creditReportExport->disk);
        abort_unless($disk->exists($creditReportExport->file_path), 404);

        return $disk->download($creditReportExport->file_path, $creditReportExport->file_name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
