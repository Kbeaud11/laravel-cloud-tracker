<?php

namespace LaravelCloudTracker\Tests\Feature;

use LaravelCloudTracker\Contracts\TrackingPolicyResolver;
use LaravelCloudTracker\Enums\TrackingMode;
use LaravelCloudTracker\Models\TrackingPolicy;
use LaravelCloudTracker\Tests\Fixtures\TestModel;
use LaravelCloudTracker\Tests\TestCase;

class TrackingPolicyTest extends TestCase
{
    protected TestModel $model;

    protected TrackingPolicyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = TestModel::create(['name' => 'Test Org']);
        $this->resolver = app(TrackingPolicyResolver::class);
    }

    public function test_tracks_everything_when_no_policy_exists(): void
    {
        $this->assertTrue($this->resolver->shouldTrack($this->model, 'any_feature'));
        $this->assertTrue($this->resolver->shouldTrack($this->model, 'another_feature'));
    }

    public function test_tracks_everything_in_all_mode(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::ALL,
        ]);

        $this->resolver->flush();

        $this->assertTrue($this->resolver->shouldTrack($this->model, 'any_feature'));
    }

    public function test_tracks_nothing_in_none_mode(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::NONE,
        ]);

        $this->resolver->flush();

        $this->assertFalse($this->resolver->shouldTrack($this->model, 'any_feature'));
        $this->assertFalse($this->resolver->shouldTrack($this->model, 'merge'));
    }

    public function test_allowlist_only_tracks_listed_features(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::ALLOWLIST,
            'tracking_features' => ['merge', 'segment_rebuild'],
        ]);

        $this->resolver->flush();

        $this->assertTrue($this->resolver->shouldTrack($this->model, 'merge'));
        $this->assertTrue($this->resolver->shouldTrack($this->model, 'segment_rebuild'));
        $this->assertFalse($this->resolver->shouldTrack($this->model, 'import'));
    }

    public function test_denylist_tracks_everything_except_listed(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::DENYLIST,
            'tracking_features' => ['debug', 'test_feature'],
        ]);

        $this->resolver->flush();

        $this->assertTrue($this->resolver->shouldTrack($this->model, 'merge'));
        $this->assertTrue($this->resolver->shouldTrack($this->model, 'import'));
        $this->assertFalse($this->resolver->shouldTrack($this->model, 'debug'));
        $this->assertFalse($this->resolver->shouldTrack($this->model, 'test_feature'));
    }

    public function test_returns_default_multiplier_when_no_policy(): void
    {
        $this->assertEquals(1.0, $this->resolver->getMultiplier($this->model));
    }

    public function test_returns_custom_multiplier_from_policy(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::ALL,
            'usage_multiplier' => 0.5000,
        ]);

        $this->resolver->flush();

        $this->assertEquals(0.5, $this->resolver->getMultiplier($this->model));
    }

    public function test_policy_is_cached_per_request(): void
    {
        TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::NONE,
        ]);

        $this->resolver->flush();

        // First call loads from DB
        $this->assertFalse($this->resolver->shouldTrack($this->model, 'feature_a'));

        // Delete the policy row — cached version should still return false
        TrackingPolicy::query()->delete();

        $this->assertFalse($this->resolver->shouldTrack($this->model, 'feature_a'));

        // After flush, should re-query and find no policy (defaults to true)
        $this->resolver->flush();

        $this->assertTrue($this->resolver->shouldTrack($this->model, 'feature_a'));
    }

    public function test_trait_provides_tracking_policy_relationship(): void
    {
        $policy = TrackingPolicy::create([
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'tracking_mode' => TrackingMode::ALL,
            'usage_multiplier' => 1.5000,
        ]);

        $this->assertNotNull($this->model->trackingPolicy);
        $this->assertEquals(TrackingMode::ALL, $this->model->trackingPolicy->tracking_mode);
        $this->assertEquals('1.5000', $this->model->trackingPolicy->usage_multiplier);
    }
}
