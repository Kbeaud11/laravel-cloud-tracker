<?php

namespace LaravelCloudTracker\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TrackingPolicyResolver
{
    /**
     * Determine whether a feature should be tracked for a given model.
     *
     * @param  Model   $model   The billable model instance.
     * @param  string  $feature The feature identifier (e.g. 'smart_segment_rebuild').
     * @return bool True if usage should be recorded for this model+feature pair.
     */
    public function shouldTrack(Model $model, string $feature): bool;

    /**
     * Get the usage multiplier for a model.
     *
     * Applied to all cost calculations for this model. Allows per-entity
     * pricing adjustments (enterprise discounts, at-cost billing, etc.).
     *
     * @param  Model  $model The billable model instance.
     * @return float The usage multiplier (1.0 = standard rate).
     */
    public function getMultiplier(Model $model): float;

    /**
     * Flush any cached policy data.
     *
     * Called between tests or after bulk policy updates.
     *
     * @return void
     */
    public function flush(): void;
}
