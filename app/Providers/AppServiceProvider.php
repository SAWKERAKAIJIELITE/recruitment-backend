<?php

namespace App\Providers;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Contracts\Auth\Authenticatable;

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
        // Any ability whose name equals a permission slug ("reports.view") is answered from the
        // user's effective permissions. We only ever return true or null (never false), so Policies
        // for resource-level rules ("candidate sees only their own attempt") still get their say.
        Gate::before(function (Authenticatable $user, string $ability) {
            return ($user instanceof StaffUser && $user->hasPermission($ability)) ? true : null;
        });

        RateLimiter::for('staff-login', function (Request $request) {
            $username = Str::lower((string) $request->input('username'));

            return [
                Limit::perMinute(7,5)->by('ip:' . $request->ip()),
                Limit::perMinute(5)->by('user:' . $username . '|' . $request->ip()),
            ];
        // return Limit::perMinute(5)->by(Str::lower((string) $request->input('username')) . '|' . $request->ip());
        });
    }
}
