<?php

namespace LaravelCloudTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UsageEvent extends Model
{
    public $timestamps = false;

    protected $table = 'model_usage_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'execution_time_ms' => 'decimal:4',
            'computed_cost' => 'decimal:10',
            'cost_dimensions' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
