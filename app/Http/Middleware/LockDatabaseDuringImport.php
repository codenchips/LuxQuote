<?php

namespace App\Http\Middleware;

use App\Services\DatabaseImportLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LockDatabaseDuringImport
{
    public function __construct(private DatabaseImportLock $importLock) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $exclusive = $request->routeIs('admin.tools.database-import');
        $locks = $this->importLock->acquire($exclusive);
        if ($locks === null) {
            return response('The database is busy. Please try again shortly.', 503)->header('Retry-After', '10');
        }
        if ($exclusive) {
            $request->attributes->set('database_import_locks', $locks);
        }
        try {
            return $next($request);
        } finally {
            $this->importLock->release($locks);
        }
    }
}
