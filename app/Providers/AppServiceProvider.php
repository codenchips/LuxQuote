<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectLine;
use App\Models\User;
use App\Observers\ProjectAreaObserver;
use App\Observers\ProjectLineObserver;
use App\Observers\ProjectObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Project::observe(ProjectObserver::class);
        ProjectArea::observe(ProjectAreaObserver::class);
        ProjectLine::observe(ProjectLineObserver::class);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        Gate::define('view-products', fn (User $user) => true);
        Gate::define('import-products', fn (User $user) => $user->role === UserRole::Admin);
        Gate::define('view-users', fn (User $user) => $user->role === UserRole::Admin);
        Gate::define('create-users', fn (User $user) => $user->role === UserRole::Admin);
        Gate::define('edit-users', fn (User $user) => $user->role === UserRole::Admin);
        Gate::define('delete-users', fn (User $user) => $user->role === UserRole::Admin);
    }
}
