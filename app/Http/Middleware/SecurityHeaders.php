<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        foreach ((array) config('security.headers.values', []) as $name => $value) {
            if (is_string($name) && is_string($value) && filled($name) && filled($value)) {
                $response->headers->set($name, $value);
            }
        }

        if ($request->isSecure() && config('security.hsts.enabled', false)) {
            $response->headers->set('Strict-Transport-Security', $this->hstsValue());
        } else {
            $response->headers->remove('Strict-Transport-Security');
        }

        return $response;
    }

    private function hstsValue(): string
    {
        $value = 'max-age='.max(1, (int) config('security.hsts.max_age', 31536000));

        if (config('security.hsts.include_subdomains', false)) {
            $value .= '; includeSubDomains';
        }

        if (config('security.hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }
}
