<?php

namespace LaravelCloudTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LaravelCloudTracker\Enums\TrackingMode;

class TrackingPolicy extends Model
{
    protected $table = 'model_tracking_policies';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tracking_mode' => TrackingMode::class,
            'tracking_features' => 'array',
            'usage_multiplier' => 'decimal:4',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
