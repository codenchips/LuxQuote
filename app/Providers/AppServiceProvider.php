<?php

namespace App\Providers;

use App\Enums\PermissionKey;
use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\User;
use App\Observers\ProjectAreaObserver;
use App\Observers\ProjectLineObserver;
use App\Observers\ProjectObserver;
use App\Services\SalesforceService;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SalesforceService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

        if (app()->environment('production')) {
            URL::forceRootUrl(config('app.url'));
            URL::forceScheme('https');
        }

        Event::listen(Login::class, function (Login $event): void {
            if (Schema::hasColumn('users', 'last_login_at')) {
                $event->user->updateQuietly([
                    'last_login_at' => now(),
                ]);
            }

            ActivityLog::create([
                'user_id' => $event->user->id,
                'project_id' => null,
                'action_type' => 'user.login',
                'user_email_snapshot' => $event->user->email,
                'project_name_snapshot' => null,
                'payload' => [
                    'login_context' => $this->loginContext(request()),
                ],
            ]);
        });

        Project::observe(ProjectObserver::class);
        ProjectArea::observe(ProjectAreaObserver::class);
        ProjectLine::observe(ProjectLineObserver::class);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        Gate::before(fn (User $user): ?bool => $user->isAdministrator() ? true : null);

        foreach (PermissionKey::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }

        foreach ($this->legacyPermissionAliases() as $legacyGate => $permission) {
            Gate::define($legacyGate, fn (User $user): bool => $user->hasPermission($permission));
        }
    }

    /**
     * @return array<string, PermissionKey>
     */
    private function legacyPermissionAliases(): array
    {
        return [
            'view-products' => PermissionKey::ProductsView,
            'import-products' => PermissionKey::ProductsImport,
            'view-users' => PermissionKey::UsersView,
            'create-users' => PermissionKey::UsersCreate,
            'edit-users' => PermissionKey::UsersUpdate,
            'delete-users' => PermissionKey::UsersDelete,
            'view-activity-logs' => PermissionKey::ActivityLogView,
            'view-salesforce' => PermissionKey::SalesforceView,
        ];
    }

    /**
     * @return array{display: string, browser: string, platform: string, fingerprint: string, ip_address: ?string, accept_language: ?string, user_agent: ?string}
     */
    private function loginContext(Request $request): array
    {
        $userAgent = $request->userAgent();
        $browser = $this->browserName($userAgent);
        $platform = $this->platformName($request, $userAgent);
        $fingerprint = strtoupper(substr(hash('sha256', implode('|', [
            (string) $request->ip(),
            (string) $userAgent,
            (string) $request->header('accept-language'),
            (string) $request->header('sec-ch-ua-platform'),
        ])), 0, 6));

        return [
            'display' => "{$browser} on {$platform} · #{$fingerprint}",
            'browser' => $browser,
            'platform' => $platform,
            'fingerprint' => $fingerprint,
            'ip_address' => $request->ip(),
            'accept_language' => $request->header('accept-language'),
            'user_agent' => $userAgent,
        ];
    }

    private function browserName(?string $userAgent): string
    {
        $userAgent = $userAgent ?? '';

        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'Firefox/'), str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };
    }

    private function platformName(Request $request, ?string $userAgent): string
    {
        $clientHint = trim((string) $request->header('sec-ch-ua-platform'), '" ');

        if ($clientHint !== '') {
            return Str::of($clientHint)->title()->toString();
        }

        $userAgent = $userAgent ?? '';

        return match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X'), str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown device',
        };
    }
}
