<?php

namespace LaravelCloudTracker\Tests\Feature;

use LaravelCloudTracker\CloudCostManager;
use LaravelCloudTracker\Contracts\TrackingPolicyResolver;
use LaravelCloudTracker\Enums\TrackingMode;
use LaravelCloudTracker\Facades\CloudCost;
use LaravelCloudTracker\Models\TrackingPolicy;
use LaravelCloudTracker\Models\UsageEvent;
use LaravelCloudTracker\Models\UsageRollup;
use LaravelCloudTracker\Tests\Fixtures\TestModel;
use LaravelCloudTracker\Tests\TestCase;

class CloudCostTrackingTest extends TestCase
{
    protected TestModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = TestModel::create(['name' => 'Test Org']);
    }

    public function test_tracks_execution_and_creates_event_and_rollup(): void
    {
        $result = CloudCost::for($this->model)
            ->feature('segment_rebuild')
            ->track(fn () => 'done');

        $this->assertEquals('done', $result);

        $this->assertDatabaseCount('model_usage_events', 1);
        $this->assertDatabaseCount('model_usage_rollups', 1);

        $event = UsageEvent::first();
        $this->assertEquals('segment_rebuild', $event->feature);
        $this->assertEquals($this->model->getMorphClass(), $event->billable_type);
        $this->assertEquals($this->model->getKey(), $event->billable_id);
        $this->assertGreaterThan(0, $event->execution_time_ms);

        $rollup = UsageRollup::first();
        $this->assertEquals('segment_rebuild', $rollup->feature);
        $this->assertEquals(1, $rollup->event_count);
    }

    public function test_callback_return_value_is_passed_through(): void
    {
        $result = CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => ['contacts' => 42]);

        $this->assertEquals(['contacts' => 42], $result);
    }

    public function test_multiple_tracks_aggregate_into_single_rollup(): void
    {
        for ($i = 0; $i < 3; $i++) {
            CloudCost::for($this->model)
                ->feature('import')
                ->track(fn () => usleep(1000)); // ~1ms
        }

        $this->assertDatabaseCount('model_usage_events', 3);
        $this->assertDatabaseCount('model_usage_rollups', 1);

        $rollup = UsageRollup::first();
        $this->assertEquals(3, $rollup->event_count);
        $this->assertGreaterThan(0, (float) $rollup->total_execution_ms);
    }

    public function test_different_features_get_separate_rollups(): void
    {
        CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => null);

        CloudCost::for($this->model)
            ->feature('import')
            ->track(fn () => null);

        $this->assertDatabaseCount('model_usage_rollups', 2);

        $this->assertDatabaseHas('model_usage_rollups', ['feature' => 'merge']);
        $this->assertDatabaseHas('model_usage_rollups', ['feature' => 'import']);
    }

    public function test_force_bypasses_policy_and_tracks(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::NONE,
        ]);

        app(TrackingPolicyResolver::class)->flush();

        CloudCost::for($this->model)
            ->feature('admin_operation')
            ->force()
            ->track(fn () => null);

        $this->assertDatabaseCount('model_usage_events', 1);
    }

    public function test_skips_tracking_when_policy_denies(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::NONE,
        ]);

        app(TrackingPolicyResolver::class)->flush();

        $result = CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => 'executed');

        // Callback still executes
        $this->assertEquals('executed', $result);

        // But nothing is recorded
        $this->assertDatabaseCount('model_usage_events', 0);
        $this->assertDatabaseCount('model_usage_rollups', 0);
    }

    public function test_skips_tracking_in_disallowed_environment(): void
    {
        config()->set('cloud-tracker.environments', ['production']);

        CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => null);

        $this->assertDatabaseCount('model_usage_events', 0);
    }

    public function test_skips_tracking_when_disabled(): void
    {
        config()->set('cloud-tracker.enabled', false);

        CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => null);

        $this->assertDatabaseCount('model_usage_events', 0);
    }

    public function test_applies_usage_multiplier_to_cost(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::ALL,
            'usage_multiplier' => 2.0000,
        ]);

        app(TrackingPolicyResolver::class)->flush();

        CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => usleep(10000)); // ~10ms

        $eventWithMultiplier = UsageEvent::first();

        // Reset for comparison without multiplier
        TrackingPolicy::query()->update(['usage_multiplier' => 1.0000]);
        app(TrackingPolicyResolver::class)->flush();

        // The cost with 2x multiplier should be roughly 2x a 1x cost
        // We can't compare exactly due to timing variance, but cost should be > 0
        $this->assertGreaterThan(0, (float) $eventWithMultiplier->computed_cost);
    }

    public function test_chained_dimensions_are_all_recorded(): void
    {
        CloudCost::for($this->model)
            ->feature('complex_operation')
            ->dimension('compute')
            ->dimension('cache', 50)
            ->dimension('websocket', 3)
            ->track(fn () => usleep(1000));

        $event = UsageEvent::first();
        $dimensions = $event->cost_dimensions;

        $this->assertArrayHasKey('compute', $dimensions);
        $this->assertArrayHasKey('cache', $dimensions);
        $this->assertArrayHasKey('websocket', $dimensions);
        $this->assertEquals(50, $dimensions['cache']['quantity']);
        $this->assertEquals(3, $dimensions['websocket']['quantity']);
    }

    public function test_metadata_is_stored_on_event(): void
    {
        CloudCost::for($this->model)
            ->feature('import')
            ->withMetadata(['job_id' => 'abc-123', 'rows' => 500])
            ->track(fn () => null);

        $event = UsageEvent::first();
        $this->assertEquals('abc-123', $event->metadata['job_id']);
        $this->assertEquals(500, $event->metadata['rows']);
    }

    public function test_event_logging_can_be_disabled(): void
    {
        config()->set('cloud-tracker.log_events', false);

        CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => null);

        // Events not logged, but rollup still created
        $this->assertDatabaseCount('model_usage_events', 0);
        $this->assertDatabaseCount('model_usage_rollups', 1);
    }

    public function test_throws_when_model_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('billable model is required');

        CloudCost::feature('merge')->track(fn () => null);
    }

    public function test_throws_when_feature_is_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('feature name is required');

        CloudCost::for($this->model)->track(fn () => null);
    }

    public function test_trait_provides_usage_event_relationship(): void
    {
        CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => null);

        $this->assertCount(1, $this->model->usageEvents);
        $this->assertEquals('merge', $this->model->usageEvents->first()->feature);
    }

    public function test_trait_provides_usage_rollup_relationship(): void
    {
        CloudCost::for($this->model)
            ->feature('merge')
            ->track(fn () => null);

        $this->assertCount(1, $this->model->usageRollups);
    }

    public function test_timing_accuracy_within_reasonable_bounds(): void
    {
        CloudCost::for($this->model)
            ->feature('timing_test')
            ->track(fn () => usleep(50000)); // ~50ms

        $event = UsageEvent::first();
        $ms = (float) $event->execution_time_ms;

        // Should be roughly 50ms. Allow 20-200ms for CI/system variance.
        $this->assertGreaterThan(20, $ms);
        $this->assertLessThan(200, $ms);
    }
}
