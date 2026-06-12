<?php

namespace Platform\AssetManager;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Platform\AssetManager\Models\AssetDevice;
use Platform\AssetManager\Models\AssetItem;
use Platform\AssetManager\Policies\AssetDevicePolicy;
use Platform\AssetManager\Policies\AssetItemPolicy;
use Platform\Core\PlatformCore;
use Platform\Core\Routing\ModuleRouter;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AssetManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/asset-manager.php', 'asset-manager');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Platform\AssetManager\Console\Commands\SyncIntuneDevicesCommand::class,
                \Platform\AssetManager\Console\Commands\SyncLicensesCommand::class,
                \Platform\AssetManager\Console\Commands\BackfillEmployeesCommand::class,
                \Platform\AssetManager\Console\Commands\ImportCostExcelCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        if (
            config()->has('asset-manager.routing') &&
            config()->has('asset-manager.navigation') &&
            Schema::hasTable('modules')
        ) {
            PlatformCore::registerModule([
                'key'        => 'asset-manager',
                'title'      => 'Asset Manager',
                'routing'    => config('asset-manager.routing'),
                'guard'      => config('asset-manager.guard'),
                'navigation' => config('asset-manager.navigation'),
                'sidebar'    => config('asset-manager.sidebar'),
            ]);
        }

        if (PlatformCore::getModule('asset-manager')) {
            ModuleRouter::group('asset-manager', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
            });

            ModuleRouter::apiGroup('asset-manager', function () {
                $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
            });
        }

        Gate::policy(AssetDevice::class, AssetDevicePolicy::class);
        Gate::policy(AssetItem::class, AssetItemPolicy::class);

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/asset-manager.php' => config_path('asset-manager.php'),
        ], 'config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'asset-manager');

        $this->registerLivewireComponents();

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('asset-manager:sync-intune')->hourly();
            $schedule->command('asset-manager:sync-licenses')->dailyAt('02:00');
        });
    }

    protected function registerLivewireComponents(): void
    {
        $basePath      = __DIR__ . '/Livewire';
        $baseNamespace = 'Platform\\AssetManager\\Livewire';
        $prefix        = 'asset-manager';

        if (!is_dir($basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPath    = str_replace(['/', '.php'], ['\\', ''], $relativePath);
            $class        = $baseNamespace . '\\' . $classPath;

            if (!class_exists($class)) {
                continue;
            }

            $aliasPath = str_replace(['\\', '/'], '.', Str::kebab(str_replace('.php', '', $relativePath)));
            $alias     = $prefix . '.' . $aliasPath;

            Livewire::component($alias, $class);
        }
    }
}
