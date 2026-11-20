<?php

namespace App\Http\Middleware;

use App\Services\ScheduledMaintenance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceScheduledMaintenance
{
    public function __construct(private ScheduledMaintenance $maintenance) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = $this->maintenance;
        $settings = $maintenance->settings();
        $cutoff = $maintenance->cutoff($settings);
        $signedOut = false;
        if ($request->user() && $cutoff !== null && $request->session()->get('maintenance_generation') !== $cutoff) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $signedOut = true;
        }

        if ($request->routeIs('maintenance.status')) {
            $request->attributes->set('maintenance_signed_out', $signedOut);

            return $next($request);
        }

        if ($request->routeIs('maintenance.notice')) {
            return $next($request);
        }

        if ($signedOut || $maintenance->active($settings)) {
            if ($request->expectsJson() || $request->header('X-Livewire')) {
                return response()->json(['maintenance' => true, 'signed_out' => $signedOut], 423);
            }

            return redirect()->route('maintenance.notice');
        }

        return $next($request);
    }
}
