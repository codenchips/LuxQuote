<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackup;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseBackupController extends Controller
{
    public function __invoke(Request $request, DatabaseBackup $backup): BinaryFileResponse
    {
        abort_unless($request->user()?->isAdministrator(), 403);

        return response()->download($backup->createDump(), 'luxquote-database-'.now()->format('Y-m-d-His').'.sql', [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend();
    }
}
