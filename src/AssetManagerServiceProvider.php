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

        $this->registerTools();

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('asset-manager:sync-intune')->hourly();
            $schedule->command('asset-manager:sync-licenses')->dailyAt('02:00');
        });
    }

    /**
     * Registriert die MCP/LLM-Tools des Moduls beim zentralen ToolRegistry.
     *
     * Explizite Registrierung (keine Auto-Discovery) — analog Planner. In try/catch, damit ein
     * fehlendes ToolRegistry (z. B. im Setup/Console-Boot) das Modul nicht lahmlegt.
     */
    protected function registerTools(): void
    {
        try {
            $registry = resolve(\Platform\Core\Tools\ToolRegistry::class);

            // Anker
            $registry->register(new \Platform\AssetManager\Tools\OverviewTool());

            // Mitarbeiter
            $registry->register(new \Platform\AssetManager\Tools\Employees\ListEmployeesTool());
            $registry->register(new \Platform\AssetManager\Tools\Employees\GetEmployeeTool());
            $registry->register(new \Platform\AssetManager\Tools\Employees\UpdateEmployeeTool());
            $registry->register(new \Platform\AssetManager\Tools\Employees\BulkAssignCostCenterTool());

            // Geräte + Modelle
            $registry->register(new \Platform\AssetManager\Tools\Devices\ListDevicesTool());
            $registry->register(new \Platform\AssetManager\Tools\Devices\GetDeviceTool());
            $registry->register(new \Platform\AssetManager\Tools\Devices\UpdateDeviceTool());
            $registry->register(new \Platform\AssetManager\Tools\Devices\BulkUpdateDeviceCostTool());
            $registry->register(new \Platform\AssetManager\Tools\Devices\ListDeviceModelsTool());
            $registry->register(new \Platform\AssetManager\Tools\Devices\UpsertDeviceModelTool());

            // Lizenzen
            $registry->register(new \Platform\AssetManager\Tools\Licenses\ListLicensesTool());

            // Kosten-Auswertungen (read-only)
            $registry->register(new \Platform\AssetManager\Tools\Costs\CostSummaryTool());
            $registry->register(new \Platform\AssetManager\Tools\Costs\CostByDimensionTool());
            $registry->register(new \Platform\AssetManager\Tools\Costs\TopEmployeesByCostTool());
            $registry->register(new \Platform\AssetManager\Tools\Costs\CostAllocationTool());
            $registry->register(new \Platform\AssetManager\Tools\Costs\CostAnomaliesTool());

            // Kostenpositionen
            $registry->register(new \Platform\AssetManager\Tools\CostLines\ListCostLinesTool());
            $registry->register(new \Platform\AssetManager\Tools\CostLines\CreateCostLineTool());
            $registry->register(new \Platform\AssetManager\Tools\CostLines\UpdateCostLineTool());
            $registry->register(new \Platform\AssetManager\Tools\CostLines\BulkReassignCostLineCenterTool());

            // Stammdaten
            $registry->register(new \Platform\AssetManager\Tools\MasterData\ListCostCentersTool());
            $registry->register(new \Platform\AssetManager\Tools\MasterData\CreateCostCenterTool());
            $registry->register(new \Platform\AssetManager\Tools\MasterData\ListCompaniesTool());
            $registry->register(new \Platform\AssetManager\Tools\MasterData\ListCostTypesTool());
            $registry->register(new \Platform\AssetManager\Tools\MasterData\ListVendorsTool());

            // Sync
            $registry->register(new \Platform\AssetManager\Tools\Sync\SyncStatusTool());
            $registry->register(new \Platform\AssetManager\Tools\Sync\TriggerSyncTool());
        } catch (\Throwable $e) {
            \Log::warning('AssetManager: Tool-Registrierung fehlgeschlagen', ['error' => $e->getMessage()]);
        }
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

            // Jedes Pfad-Segment EINZELN kebaben, dann mit '.' joinen — sonst kebabt Str::kebab über die
            // Trenner hinweg und erzeugt einen führenden Bindestrich (z. B. "costs.-allocation" statt
            // "costs.allocation"), was bei verschachtelter Nutzung als 500-Falle endet.
            $aliasPath = collect(preg_split('#[\\\\/]#', str_replace('.php', '', $relativePath)))
                ->map(fn ($segment) => Str::kebab($segment))
                ->implode('.');
            $alias     = $prefix . '.' . $aliasPath;

            Livewire::component($alias, $class);
        }
    }
}
