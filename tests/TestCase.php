<?php

namespace LaravelCloudTracker\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelCloudTracker\CloudTrackerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            CloudTrackerServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'CloudCost' => \LaravelCloudTracker\Facades\CloudCost::class,
        ];
    }

    /**
     * Set up the test environment.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigrations();
        $this->createTestTable();
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cloud-tracker.enabled', true);
        $app['config']->set('cloud-tracker.environments', ['testing']);
        $app['config']->set('cloud-tracker.log_events', true);

        $app['config']->set('cloud-tracker.costs', [
            'compute' => [
                'unit' => 'time',
                'active' => 'flex-1c-256m',
                'instances' => [
                    'flex-1c-256m' => ['per_second' => 0.00000165],
                ],
            ],
            'postgres' => [
                'unit' => 'time',
                'per_second' => 0.00002944,
            ],
            'cache' => [
                'unit' => 'flat_monthly',
                'active' => '250m',
                'tiers' => [
                    '250m' => ['monthly' => 6.00],
                ],
                'estimated_operations_per_month' => 10_000_000,
            ],
            'websocket' => [
                'unit' => 'flat_monthly',
                'monthly' => 5.00,
                'estimated_messages_per_month' => 1_000_000,
            ],
            'bandwidth' => [
                'unit' => 'count',
                'per_gb' => 0.10,
            ],
            'storage' => [
                'unit' => 'count',
                'per_1k_operations' => 0.0005,
            ],
        ]);
    }

    /**
     * Run the package migration against the in-memory SQLite database.
     *
     * @return void
     */
    protected function runMigrations(): void
    {
        $migration = include __DIR__ . '/../database/migrations/create_cloud_tracker_tables.php';

        $migration->up();
    }

    /**
     * Create a dummy 'test_models' table for the billable test model.
     *
     * @return void
     */
    protected function createTestTable(): void
    {
        Schema::create('test_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->timestamps();
        });
    }
}
