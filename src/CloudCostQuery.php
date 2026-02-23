<?php

declare(strict_types=1);

namespace LaravelCloudTracker;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LaravelCloudTracker\Models\UsageEvent;
use LaravelCloudTracker\Models\UsageRollup;

class CloudCostQuery
{
    protected ?string $billableType = null;

    protected ?int $billableId = null;

    /** @var string[] */
    protected array $featureFilters = [];

    protected ?Carbon $dateStart = null;

    protected ?Carbon $dateEnd = null;

    protected string $source = 'rollups';

    /**
     * Filter by billable type or specific billable instance.
     *
     * Pass a class string to filter by type, or a model instance to filter
     * by a specific billable entity.
     *
     * @param Model|string $model A model instance or fully-qualified class name.
     *
     * @return static
     */
    public function forModel(Model|string $model): static
    {
        if ($model instanceof Model) {
            $this->billableType = $model->getMorphClass();
            $this->billableId = (int) $model->getKey();
        } else {
            $this->billableType = (new $model)->getMorphClass();
            $this->billableId = null;
        }

        return $this;
    }

    /**
     * Filter by a single feature.
     *
     * @param string $feature The feature identifier.
     *
     * @return static
     */
    public function feature(string $feature): static
    {
        $this->featureFilters = [$feature];

        return $this;
    }

    /**
     * Filter by multiple features.
     *
     * @param string[] $features Feature identifiers.
     *
     * @return static
     */
    public function features(array $features): static
    {
        $this->featureFilters = $features;

        return $this;
    }

    /**
     * Filter by a named period relative to an optional date.
     *
     * Supported periods: 'month', 'quarter', 'year'.
     *
     * @param string $period The period type.
     * @param Carbon|null $date The reference date (defaults to now).
     *
     * @return static
     */
    public function period(string $period, ?Carbon $date = null): static
    {
        $date = $date ?? Carbon::now();

        [$this->dateStart, $this->dateEnd] = match ($period) {
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            'quarter' => [$date->copy()->startOfQuarter(), $date->copy()->endOfQuarter()],
            'year' => [$date->copy()->startOfYear(), $date->copy()->endOfYear()],
        };

        return $this;
    }

    /**
     * Filter by a custom date range.
     *
     * @param Carbon $start Start of the range (inclusive).
     * @param Carbon $end End of the range (inclusive).
     *
     * @return static
     */
    public function dateRange(Carbon $start, Carbon $end): static
    {
        $this->dateStart = $start;
        $this->dateEnd = $end;

        return $this;
    }

    /**
     * Query the rollups table (default, faster for aggregates).
     *
     * @return static
     */
    public function fromRollups(): static
    {
        $this->source = 'rollups';

        return $this;
    }

    /**
     * Query the events table (granular, per-event data).
     *
     * @return static
     */
    public function fromEvents(): static
    {
        $this->source = 'events';

        return $this;
    }

    /**
     * Get the total cost as a float.
     *
     * @return float
     */
    public function sum(): float
    {
        $query = $this->buildQuery();

        $column = $this->source === 'rollups' ? 'total_cost' : 'computed_cost';

        return (float) $query->sum($column);
    }

    /**
     * Get cost totals grouped by feature.
     *
     * @return Collection<int, object{feature: string, total_cost: float, event_count: int}>
     */
    public function sumByFeature(): Collection
    {
        $query = $this->buildQuery();

        if ($this->source === 'rollups') {
            return $query
                ->select('feature')
                ->selectRaw('SUM(total_cost) as total_cost')
                ->selectRaw('SUM(event_count) as event_count')
                ->groupBy('feature')
                ->orderByDesc('total_cost')
                ->get();
        }

        return $query
            ->select('feature')
            ->selectRaw('SUM(computed_cost) as total_cost')
            ->selectRaw('COUNT(*) as event_count')
            ->groupBy('feature')
            ->orderByDesc('total_cost')
            ->get();
    }

    /**
     * Aggregate costs per dimension key from the events cost_dimensions JSON.
     *
     * Always reads from the events table regardless of source setting,
     * since rollups do not store per-dimension breakdowns.
     *
     * @return Collection<int, object{dimension: string, total_cost: float}>
     */
    public function sumByDimension(): Collection
    {
        $previousSource = $this->source;
        $this->source = 'events';
        $events = $this->buildQuery()->get();
        $this->source = $previousSource;

        $totals = [];

        foreach ($events as $event) {
            $dimensions = $event->cost_dimensions;

            if (! is_array($dimensions)) {
                continue;
            }

            foreach ($dimensions as $dimension => $data) {
                $cost = is_array($data) ? ($data['cost'] ?? 0.0) : (float) $data;
                $totals[$dimension] = ($totals[$dimension] ?? 0.0) + $cost;
            }
        }

        arsort($totals);

        return collect($totals)->map(fn (float $cost, string $dimension) => (object) [
            'dimension' => $dimension,
            'total_cost' => $cost,
        ])->values();
    }

    /**
     * Get top N billables by total cost.
     *
     * @param int $limit Maximum number of results.
     *
     * @return Collection<int, object{billable_id: int, billable_type: string, total_cost: float}>
     */
    public function sumByBillable(int $limit = 20): Collection
    {
        $query = $this->buildQuery();

        if ($this->source === 'rollups') {
            return $query
                ->select('billable_id', 'billable_type')
                ->selectRaw('SUM(total_cost) as total_cost')
                ->groupBy('billable_id', 'billable_type')
                ->orderByDesc('total_cost')
                ->limit($limit)
                ->get();
        }

        return $query
            ->select('billable_id', 'billable_type')
            ->selectRaw('SUM(computed_cost) as total_cost')
            ->groupBy('billable_id', 'billable_type')
            ->orderByDesc('total_cost')
            ->limit($limit)
            ->get();
    }

    /**
     * Get cost totals grouped by time interval.
     *
     * @param string $interval Grouping interval: 'day', 'week', or 'month'.
     *
     * @return Collection<int, object{date: string, total_cost: float}>
     */
    public function timeSeries(string $interval = 'day'): Collection
    {
        if ($this->source === 'rollups') {
            return $this->timeSeriesFromRollups($interval);
        }

        return $this->timeSeriesFromEvents($interval);
    }

    /**
     * Get the raw collection of rollup or event records.
     *
     * @return Collection
     */
    public function get(): Collection
    {
        return $this->buildQuery()->get();
    }

    /**
     * Build the base Eloquent query with all applied filters.
     *
     * @return Builder
     */
    protected function buildQuery(): Builder
    {
        $query = $this->source === 'rollups'
            ? UsageRollup::query()
            : UsageEvent::query();

        if ($this->billableType !== null) {
            $query->where('billable_type', $this->billableType);
        }

        if ($this->billableId !== null) {
            $query->where('billable_id', $this->billableId);
        }

        if (count($this->featureFilters) === 1) {
            $query->where('feature', $this->featureFilters[0]);
        } elseif (count($this->featureFilters) > 1) {
            $query->whereIn('feature', $this->featureFilters);
        }

        if ($this->dateStart !== null && $this->dateEnd !== null) {
            if ($this->source === 'rollups') {
                $query->whereBetween('period_start', [
                    $this->dateStart->toDateString(),
                    $this->dateEnd->toDateString(),
                ]);
            } else {
                $query->whereBetween('created_at', [$this->dateStart, $this->dateEnd]);
            }
        }

        return $query;
    }

    /**
     * Build time series from the rollups table.
     *
     * @param string $interval Grouping interval.
     *
     * @return Collection
     */
    protected function timeSeriesFromRollups(string $interval): Collection
    {
        $query = $this->buildQuery();
        $dateExpr = $this->dateGroupExpression('period_start', $interval);

        return $query
            ->selectRaw("{$dateExpr} as date")
            ->selectRaw('SUM(total_cost) as total_cost')
            ->groupByRaw($dateExpr)
            ->orderByRaw($dateExpr)
            ->get();
    }

    /**
     * Build time series from the events table.
     *
     * @param string $interval Grouping interval.
     *
     * @return Collection
     */
    protected function timeSeriesFromEvents(string $interval): Collection
    {
        $query = $this->buildQuery();
        $dateExpr = $this->dateGroupExpression('created_at', $interval);

        return $query
            ->selectRaw("{$dateExpr} as date")
            ->selectRaw('SUM(computed_cost) as total_cost')
            ->groupByRaw($dateExpr)
            ->orderByRaw($dateExpr)
            ->get();
    }

    /**
     * Get the SQL expression to group a date column by the given interval.
     *
     * @param string $column The date/timestamp column name.
     * @param string $interval The grouping interval (day, week, month).
     *
     * @return string Raw SQL expression.
     */
    protected function dateGroupExpression(string $column, string $interval): string
    {
        $driver = DB::getDriverName();

        return match ($interval) {
            'day' => match ($driver) {
                'sqlite' => "DATE({$column})",
                default => "DATE({$column})",
            },
            'week' => match ($driver) {
                'sqlite' => "DATE({$column}, 'weekday 0', '-6 days')",
                'pgsql' => "DATE_TRUNC('week', {$column})::date",
                default => "DATE(DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY))",
            },
            'month' => match ($driver) {
                'sqlite' => "strftime('%Y-%m-01', {$column})",
                'pgsql' => "DATE_TRUNC('month', {$column})::date",
                default => "DATE_FORMAT({$column}, '%Y-%m-01')",
            },
        };
    }
}
