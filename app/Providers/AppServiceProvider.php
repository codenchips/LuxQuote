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
                'payload' => null,
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
}
