<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $modulesPath = app_path('Modules');

        foreach (File::directories($modulesPath) as $moduleDir) {
            $migrations = $moduleDir . '/Database/Migrations';
            if (is_dir($migrations)) {
                $this->loadMigrationsFrom($migrations);
            }

            $routes = $moduleDir . '/routes.php';
            if (is_file($routes)) {
                $this->loadRoutesFrom($routes);
            }

            if ($this->app->runningInConsole()) {
                $module = basename($moduleDir);

                $this->commands(
                    collect(File::glob($moduleDir . '/Console/*Command.php'))
                        ->map(fn(string $file) => "App\\Modules\\{$module}\\Console\\" . basename($file, '.php'))
                        ->all()
                );
            }
        }
    }
}
