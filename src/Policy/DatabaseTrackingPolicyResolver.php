<?php

namespace LaravelCloudTracker\Policy;

use Illuminate\Database\Eloquent\Model;
use LaravelCloudTracker\Contracts\TrackingPolicyResolver;
use LaravelCloudTracker\Enums\TrackingMode;
use LaravelCloudTracker\Models\TrackingPolicy;

class DatabaseTrackingPolicyResolver implements TrackingPolicyResolver
{
    /** @var array<string, TrackingPolicy|null> Resolved policies cached for the current request. */
    protected array $cache = [];

    /**
     * {@inheritDoc}
     *
     * Evaluates the model's tracking policy (mode + feature list) to decide
     * if usage should be recorded. When no policy row exists, tracking is
     * enabled by default (equivalent to 'all' mode).
     *
     * Policy modes:
     * - all: Track every feature.
     * - none: Track nothing.
     * - allowlist: Only track features listed in tracking_features.
     * - denylist: Track everything except features listed in tracking_features.
     */
    public function shouldTrack(Model $model, string $feature): bool
    {
        $policy = $this->resolvePolicy($model);

        if ($policy === null) {
            return true;
        }

        return match ($policy->tracking_mode) {
            TrackingMode::ALL => true,
            TrackingMode::NONE => false,
            TrackingMode::ALLOWLIST => in_array($feature, $policy->tracking_features ?? [], true),
            TrackingMode::DENYLIST => ! in_array($feature, $policy->tracking_features ?? [], true),
        };
    }

    /**
     * {@inheritDoc}
     *
     * Reads the usage_multiplier column from the model_tracking_policies table.
     * Returns 1.0 when no policy exists or the column is null.
     */
    public function getMultiplier(Model $model): float
    {
        $policy = $this->resolvePolicy($model);

        if ($policy === null) {
            return 1.0;
        }

        return (float) ($policy->usage_multiplier ?? 1.0);
    }

    /**
     * {@inheritDoc}
     */
    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Resolve and cache the tracking policy for a model.
     *
     * Uses an in-memory cache keyed by morph class + primary key to avoid
     * redundant queries within the same request lifecycle.
     *
     * @param  Model  $model The billable model instance.
     * @return TrackingPolicy|null The policy record, or null if none configured.
     */
    protected function resolvePolicy(Model $model): ?TrackingPolicy
    {
        $key = $model->getMorphClass() . ':' . $model->getKey();

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = TrackingPolicy::query()
                ->where('billable_type', $model->getMorphClass())
                ->where('billable_id', $model->getKey())
                ->first();
        }

        return $this->cache[$key];
    }
}
