<?php

namespace LaravelCloudTracker\Traits;

use LaravelCloudTracker\Models\TrackingPolicy;
use LaravelCloudTracker\Models\UsageEvent;
use LaravelCloudTracker\Models\UsageRollup;

/**
 * Makes an Eloquent model billable for cloud cost tracking.
 *
 * Add this trait to any model that should have usage tracked against it.
 * Provides relationships to the tracking policy, usage events, and rollups.
 */
trait TracksCloudCost
{
    /**
     * Get the model's tracking policy configuration.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphOne<TrackingPolicy>
     */
    public function trackingPolicy()
    {
        return $this->morphOne(TrackingPolicy::class, 'billable');
    }

    /**
     * Get all usage events recorded against this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<UsageEvent>
     */
    public function usageEvents()
    {
        return $this->morphMany(UsageEvent::class, 'billable');
    }

    /**
     * Get all usage rollups for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<UsageRollup>
     */
    public function usageRollups()
    {
        return $this->morphMany(UsageRollup::class, 'billable');
    }
}
