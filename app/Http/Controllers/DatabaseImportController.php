<?php

namespace App\Http\Controllers;

use App\Services\DatabaseImport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DatabaseImportController extends Controller
{
    public function __invoke(Request $request, DatabaseImport $import): Response
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        $database = (string) DB::connection()->getDatabaseName();
        $validated = $request->validate([
            'dump' => ['required', 'file', 'extensions:sql', 'max:92160'],
            'confirmation' => ['required', 'string'],
        ]);
        if ($validated['confirmation'] !== 'RESTORE '.$database) {
            throw ValidationException::withMessages(['confirmation' => 'Type RESTORE followed by the database name exactly.']);
        }

        ignore_user_abort(true);
        set_time_limit(0);
        $locks = $request->attributes->get('database_import_locks');
        abort_unless(is_array($locks) && count($locks) === 2, 503);
        try {
            $import->import($validated['dump'], (int) $request->user()->id, $locks);
        } catch (RuntimeException $exception) {
            $this->disableSessionPersistence();

            return response()->view('maintenance.import-result', [
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $this->disableSessionPersistence();

        return response()->view('maintenance.import-result', [
            'success' => true,
            'message' => 'The database was restored. All existing sessions have been signed out.',
        ]);
    }

    private function disableSessionPersistence(): void
    {
        config(['session.driver' => 'array']);
        app('session')->forgetDrivers();
    }
}
