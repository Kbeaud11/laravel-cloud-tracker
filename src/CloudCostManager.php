<?php

namespace LaravelCloudTracker;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LaravelCloudTracker\Contracts\CostCalculator as CostCalculatorContract;
use LaravelCloudTracker\Contracts\TrackingPolicyResolver;
use LaravelCloudTracker\Models\UsageEvent;
use LaravelCloudTracker\Models\UsageRollup;

class CloudCostManager
{
    protected ?Model $model = null;

    protected ?string $feature = null;

    protected bool $forced = false;

    /** @var array<string, array<string, mixed>> Keyed by dimension name. */
    protected array $dimensions = [];

    /** @var array<string, mixed> Optional metadata to store with the event. */
    protected array $metadata = [];

    public function __construct(
        protected TrackingPolicyResolver $policyResolver,
        protected CostCalculatorContract $costCalculator,
    ) {}

    /**
     * Set the billable model to track usage against.
     *
     * @param Model $model Any Eloquent model using the TracksCloudCost trait.
     *
     * @return static
     */
    public function for(Model $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Set the feature name being tracked.
     *
     * @param string $feature A descriptive identifier (e.g. 'smart_segment_rebuild', 'merge').
     *
     * @return static
     */
    public function feature(string $feature): static
    {
        $this->feature = $feature;

        return $this;
    }

    /**
     * Add a cost dimension to this tracking call.
     *
     * Multiple dimensions can be chained. Pass an optional quantity for count-based
     * or flat-monthly dimensions (e.g. redis operations, websocket messages).
     *
     * @param string $name The dimension name as defined in cloud-tracker.costs config.
     * @param int|float $quantity For count-based or flat-monthly dimensions.
     *
     * @return static
     */
    public function dimension(string $name, int|float $quantity = 0): static
    {
        $this->dimensions[$name] = ['quantity' => $quantity];

        return $this;
    }

    /**
     * Bypass the tracking policy for this call.
     *
     * The callback still executes normally. Policy checks are skipped but
     * environment restrictions are still respected (local stays disabled).
     * Intended for admin/internal overrides.
     *
     * @return static
     */
    public function force(): static
    {
        $this->forced = true;

        return $this;
    }

    /**
     * Attach arbitrary metadata to the usage event.
     *
     * Stored as JSON on the event row. Useful for context like job IDs,
     * request URIs, or user identifiers.
     *
     * @param array<string, mixed> $metadata Key-value pairs.
     *
     * @return static
     */
    public function withMetadata(array $metadata): static
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    /**
     * Execute the callback and track its cost.
     *
     * Measures wall-clock execution time using hrtime, calculates cost across
     * all configured dimensions, writes to the events table (if enabled),
     * and atomically upserts the monthly rollup.
     *
     * When tracking is disabled (by policy, environment, or master config),
     * the callback still executes but no timing, calculation, or DB writes occur.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The operation to time and track.
     *
     * @return TReturn The callback's return value, always passed through.
     *
     * @throws InvalidArgumentException When model or feature is not set.
     */
    public function track(callable $callback): mixed
    {
        $this->validate();

        if (! $this->shouldTrack()) {
            return $this->executeAndReset($callback);
        }

        $startTime = hrtime(true);
        $result = $callback();
        $executionTimeMs = (hrtime(true) - $startTime) / 1_000_000;

        $this->record($executionTimeMs);
        $this->reset();

        return $result;
    }

    /**
     * Determine if this call should actually be tracked.
     *
     * Checks in order: master enabled flag, environment allowlist, then
     * policy resolution (unless force() was called).
     *
     * @return bool
     */
    protected function shouldTrack(): bool
    {
        if (! config('cloud-tracker.enabled', true)) {
            return false;
        }

        $allowedEnvironments = config('cloud-tracker.environments', ['production', 'staging']);

        if (! in_array(app()->environment(), $allowedEnvironments, true)) {
            return false;
        }

        if ($this->forced) {
            return true;
        }

        return $this->policyResolver->shouldTrack($this->model, $this->feature);
    }

    /**
     * Record usage: calculate costs, write event row, upsert rollup.
     *
     * @param float $executionTimeMs Measured execution time in milliseconds.
     *
     * @return void
     */
    protected function record(float $executionTimeMs): void
    {
        if (empty($this->dimensions)) {
            $defaultDimension = config('cloud-tracker.default_dimension', 'compute');
            $this->dimensions[$defaultDimension] = ['quantity' => 0];
        }

        $multiplier = $this->policyResolver->getMultiplier($this->model);
        $result = $this->costCalculator->calculate($executionTimeMs, $this->dimensions, $multiplier);

        if (config('cloud-tracker.log_events', true)) {
            UsageEvent::create([
                'billable_type' => $this->model->getMorphClass(),
                'billable_id' => $this->model->getKey(),
                'feature' => $this->feature,
                'execution_time_ms' => $executionTimeMs,
                'computed_cost' => $result['total_cost'],
                'cost_dimensions' => $result['dimensions'],
                'metadata' => ! empty($this->metadata) ? $this->metadata : null,
            ]);
        }

        $this->upsertRollup($executionTimeMs, $result['total_cost']);
    }

    /**
     * Atomically upsert the monthly rollup row.
     *
     * Increments totals using raw SQL expressions to avoid read-then-write
     * race conditions on concurrent requests.
     *
     * @param float $executionTimeMs Execution time to add to the rollup total.
     * @param float $cost Cost to add to the rollup total.
     *
     * @return void
     */
    protected function upsertRollup(float $executionTimeMs, float $cost): void
    {
        $periodStart = Carbon::now()->startOfMonth()->toDateString();
        $now = Carbon::now();
        $driver = DB::getDriverName();

        $attributes = [
            'billable_type' => $this->model->getMorphClass(),
            'billable_id' => $this->model->getKey(),
            'feature' => $this->feature,
            'period_start' => $periodStart,
        ];

        // Use raw SQL for atomic increment — syntax differs by driver.
        $wrap = $driver === 'sqlite'
            ? fn (string $col) => "excluded.{$col}"
            : fn (string $col) => "VALUES({$col})";

        UsageRollup::upsert(
            [
                array_merge($attributes, [
                    'total_execution_ms' => $executionTimeMs,
                    'total_cost' => $cost,
                    'event_count' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]),
            ],
            ['billable_type', 'billable_id', 'feature', 'period_start'],
            [
                'total_execution_ms' => DB::raw("total_execution_ms + {$wrap('total_execution_ms')}"),
                'total_cost' => DB::raw("total_cost + {$wrap('total_cost')}"),
                'event_count' => DB::raw('event_count + 1'),
                'updated_at' => $now,
            ],
        );
    }

    /**
     * Validate that required fields are set before tracking.
     *
     * @return void
     *
     * @throws InvalidArgumentException When model or feature is missing.
     */
    protected function validate(): void
    {
        if ($this->model === null) {
            throw new InvalidArgumentException(
                'CloudCost: A billable model is required. Use ->for($model) before ->track().'
            );
        }

        if ($this->feature === null || $this->feature === '') {
            throw new InvalidArgumentException(
                'CloudCost: A feature name is required. Use ->feature("name") before ->track().'
            );
        }
    }

    /**
     * Execute callback and reset state without recording anything.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback
     *
     * @return TReturn
     */
    protected function executeAndReset(callable $callback): mixed
    {
        $result = $callback();
        $this->reset();

        return $result;
    }

    /**
     * Reset the builder state for the next fluent chain.
     *
     * @return void
     */
    protected function reset(): void
    {
        $this->model = null;
        $this->feature = null;
        $this->forced = false;
        $this->dimensions = [];
        $this->metadata = [];
    }
}
