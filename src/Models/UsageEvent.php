<?php

namespace LaravelCloudTracker\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Scope to a specific billable type or instance.
     *
     * @param Builder $query
     * @param Model|string $billable A model instance or morph class string.
     *
     * @return void
     */
    public function scopeForBillable(Builder $query, Model|string $billable): void
    {
        if ($billable instanceof Model) {
            $query->where('billable_type', $billable->getMorphClass())
                ->where('billable_id', $billable->getKey());
        } else {
            $query->where('billable_type', (new $billable)->getMorphClass());
        }
    }

    /**
     * Scope to one or more features.
     *
     * @param Builder $query
     * @param string|string[] $feature A single feature name or array of names.
     *
     * @return void
     */
    public function scopeForFeature(Builder $query, string|array $feature): void
    {
        if (is_array($feature)) {
            $query->whereIn('feature', $feature);
        } else {
            $query->where('feature', $feature);
        }
    }

    /**
     * Scope to a named period (month, quarter, year) relative to a date.
     *
     * @param Builder $query
     * @param string $period The period type: 'month', 'quarter', or 'year'.
     * @param Carbon|null $date The reference date (defaults to now).
     *
     * @return void
     */
    public function scopeInPeriod(Builder $query, string $period, ?Carbon $date = null): void
    {
        $date = $date ?? Carbon::now();

        [$start, $end] = match ($period) {
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            'quarter' => [$date->copy()->startOfQuarter(), $date->copy()->endOfQuarter()],
            'year' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
        };

        $query->whereBetween('created_at', [$start, $end]);
    }

    /**
     * Scope to a custom date range.
     *
     * @param Builder $query
     * @param Carbon $start Start of the range (inclusive).
     * @param Carbon $end End of the range (inclusive).
     *
     * @return void
     */
    public function scopeInDateRange(Builder $query, Carbon $start, Carbon $end): void
    {
        $query->whereBetween('created_at', [$start, $end]);
    }
}
