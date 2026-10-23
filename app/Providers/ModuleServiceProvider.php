<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Descobre os módulos em app/Modules e regista o que cada um expõe:
 *  - {Modulo}ServiceProvider.php (opcional) para bindings próprios;
 *  - routes/api.php, montado em /api/v1 com o middleware "api";
 *  - routes/web.php, montado com o middleware "web".
 */
class ModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ($this->modules() as $module) {
            $provider = "App\\Modules\\{$module}\\{$module}ServiceProvider";

            if (class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach ($this->modules() as $module) {
            $path = app_path("Modules/{$module}/routes");

            if (is_file("{$path}/api.php")) {
                Route::middleware('api')->prefix('api/v1')->group("{$path}/api.php");
            }

            if (is_file("{$path}/web.php")) {
                Route::middleware('web')->group("{$path}/web.php");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function modules(): array
    {
        $directories = glob(app_path('Modules/*'), GLOB_ONLYDIR) ?: [];

        return array_map('basename', $directories);
    }
}
