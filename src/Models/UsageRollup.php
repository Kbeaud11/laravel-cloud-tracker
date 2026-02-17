<?php

namespace LaravelCloudTracker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class UsageRollup extends Model
{
    protected $table = 'model_usage_rollups';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_execution_ms' => 'decimal:4',
            'total_cost' => 'decimal:10',
            'event_count' => 'integer',
            'period_start' => 'date',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }
}
