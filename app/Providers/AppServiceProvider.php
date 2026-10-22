<?php

namespace App\Providers;

use App\Modules\Identity\Models\StaffUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use App\Modules\Identity\Models\AccessToken;
use Laravel\Sanctum\Sanctum;

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
        // Mass assignment safety net: assigning a non-fillable attribute throws instead of being silently
        // dropped (outside production). Business-controlled columns are set with forceFill() on purpose.
        Model::preventSilentlyDiscardingAttributes(app()->environment('production') === false);

        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi) {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
        // Any ability whose name equals a permission slug ("reports.view") is answered from the
        // user's effective permissions. We only ever return true or null (never false), so Policies
        // for resource-level rules ("candidate sees only their own attempt") still get their say.
        Gate::before(function (Authenticatable $user, string $ability) {
            return ($user instanceof StaffUser && $user->hasPermission($ability)) ? true : null;
        });

        RateLimiter::for('staff-login', function (Request $request) {
            $username = mb_strtolower(trim((string) $request->input('username')));

            return [
                Limit::perMinute(30)->by('ip:' . $request->ip()),
                Limit::perMinute(5)->by('user:' . $username . '|' . $request->ip()),
            ];
        });
        Sanctum::usePersonalAccessTokenModel(AccessToken::class);

        RateLimiter::for('device-register', fn(Request $request) => Limit::perHour(20)->by($request->ip()));

        RateLimiter::for('device-status', fn(Request $request) => Limit::perMinute(30)->by($request->ip()));

        RateLimiter::for('device-challenge', fn(Request $request) => [
            Limit::perMinute(60)->by('ip:' . $request->ip()),
            Limit::perMinute(10)->by('device:' . $request->input('device_id')),
        ]);
    }
}
