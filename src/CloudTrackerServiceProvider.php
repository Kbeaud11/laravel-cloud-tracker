<?php

namespace LaravelCloudTracker;

use Illuminate\Support\ServiceProvider;
use LaravelCloudTracker\Contracts\CostCalculator as CostCalculatorContract;
use LaravelCloudTracker\Contracts\TrackingPolicyResolver as TrackingPolicyResolverContract;
use LaravelCloudTracker\Policy\DatabaseTrackingPolicyResolver;

class CloudTrackerServiceProvider extends ServiceProvider
{
    /**
     * Register package services into the container.
     *
     * Binds the policy resolver and cost calculator contracts to their
     * default implementations. Users can override these bindings in
     * their own service providers to customize behavior.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/cloud-tracker.php', 'cloud-tracker');

        $this->app->singleton(TrackingPolicyResolverContract::class, DatabaseTrackingPolicyResolver::class);
        $this->app->singleton(CostCalculatorContract::class, CostCalculator::class);

        $this->app->singleton(CloudCostManager::class, function ($app) {
            return new CloudCostManager(
                $app->make(TrackingPolicyResolverContract::class),
                $app->make(CostCalculatorContract::class),
            );
        });
    }

    /**
     * Bootstrap package resources.
     *
     * Publishes config and migration files. The migration uses a static
     * filename (no timestamp prefix) so it can be re-published cleanly.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/cloud-tracker.php' => config_path('cloud-tracker.php'),
            ], 'cloud-tracker-config');

            $this->publishes([
                __DIR__ . '/../database/migrations/create_cloud_tracker_tables.php' => database_path(
                    'migrations/' . date('Y_m_d_His') . '_create_cloud_tracker_tables.php'
                ),
            ], 'cloud-tracker-migrations');
        }
    }
}
