<?php

namespace LaravelCloudTracker\Tests\Feature;

use Carbon\Carbon;
use LaravelCloudTracker\CloudCostQuery;
use LaravelCloudTracker\Facades\CloudCost;
use LaravelCloudTracker\Models\UsageEvent;
use LaravelCloudTracker\Models\UsageRollup;
use LaravelCloudTracker\Tests\Fixtures\TestModel;
use LaravelCloudTracker\Tests\TestCase;

class CloudCostQueryTest extends TestCase
{
    protected TestModel $modelA;

    protected TestModel $modelB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelA = TestModel::create(['name' => 'Org A']);
        $this->modelB = TestModel::create(['name' => 'Org B']);

        $this->seedData();
    }

    protected function seedData(): void
    {
        Carbon::setTestNow('2025-06-15 12:00:00');

        // Model A: 3 events for 'import', 2 for 'merge'
        foreach (range(1, 3) as $i) {
            $this->createEventAndRollup($this->modelA, 'import', 0.50, [
                'compute' => ['ms' => 100, 'cost' => 0.30],
                'postgres' => ['ms' => 50, 'cost' => 0.20],
            ]);
        }

        foreach (range(1, 2) as $i) {
            $this->createEventAndRollup($this->modelA, 'merge', 0.25, [
                'compute' => ['ms' => 80, 'cost' => 0.15],
                'cache' => ['quantity' => 10, 'cost' => 0.10],
            ]);
        }

        // Model B: 1 event for 'import'
        $this->createEventAndRollup($this->modelB, 'import', 1.00, [
            'compute' => ['ms' => 200, 'cost' => 0.60],
            'postgres' => ['ms' => 100, 'cost' => 0.40],
        ]);

        // Previous month event for Model A
        Carbon::setTestNow('2025-05-10 12:00:00');
        $this->createEventAndRollup($this->modelA, 'import', 0.75, [
            'compute' => ['ms' => 150, 'cost' => 0.75],
        ]);

        Carbon::setTestNow('2025-06-15 12:00:00');
    }

    protected function createEventAndRollup(TestModel $model, string $feature, float $cost, array $dimensions): void
    {
        UsageEvent::create([
            'billable_type' => $model->getMorphClass(),
            'billable_id' => $model->getKey(),
            'feature' => $feature,
            'execution_time_ms' => 100.0,
            'computed_cost' => $cost,
            'cost_dimensions' => $dimensions,
            'created_at' => Carbon::now(),
        ]);

        UsageRollup::upsert(
            [[
                'billable_type' => $model->getMorphClass(),
                'billable_id' => $model->getKey(),
                'feature' => $feature,
                'period_start' => Carbon::now()->startOfMonth()->toDateString(),
                'total_execution_ms' => 100.0,
                'total_cost' => $cost,
                'event_count' => 1,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]],
            ['billable_type', 'billable_id', 'feature', 'period_start'],
            [
                'total_execution_ms' => \Illuminate\Support\Facades\DB::raw('total_execution_ms + excluded.total_execution_ms'),
                'total_cost' => \Illuminate\Support\Facades\DB::raw('total_cost + excluded.total_cost'),
                'event_count' => \Illuminate\Support\Facades\DB::raw('event_count + 1'),
                'updated_at' => Carbon::now(),
            ],
        );
    }

    // ------------------------------------------------------------------
    // Facade access
    // ------------------------------------------------------------------

    public function test_query_is_accessible_via_facade(): void
    {
        $query = CloudCost::query();

        $this->assertInstanceOf(CloudCostQuery::class, $query);
    }

    // ------------------------------------------------------------------
    // sum()
    // ------------------------------------------------------------------

    public function test_sum_returns_total_cost_from_rollups(): void
    {
        $total = CloudCost::query()
            ->period('month', Carbon::parse('2025-06-01'))
            ->sum();

        // Model A: import 1.50 + merge 0.50 + Model B: import 1.00 = 3.00
        $this->assertEqualsWithDelta(3.00, $total, 0.001);
    }

    public function test_sum_from_events(): void
    {
        $total = CloudCost::query()
            ->period('month', Carbon::parse('2025-06-01'))
            ->fromEvents()
            ->sum();

        $this->assertEqualsWithDelta(3.00, $total, 0.001);
    }

    public function test_sum_filters_by_model_instance(): void
    {
        $total = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->sum();

        // Model A June: import 1.50 + merge 0.50 = 2.00
        $this->assertEqualsWithDelta(2.00, $total, 0.001);
    }

    public function test_sum_filters_by_model_class(): void
    {
        $total = CloudCost::query()
            ->forModel(TestModel::class)
            ->period('month', Carbon::parse('2025-06-01'))
            ->sum();

        // All TestModels in June = 3.00
        $this->assertEqualsWithDelta(3.00, $total, 0.001);
    }

    // ------------------------------------------------------------------
    // feature / features filters
    // ------------------------------------------------------------------

    public function test_filter_by_single_feature(): void
    {
        $total = CloudCost::query()
            ->feature('import')
            ->period('month', Carbon::parse('2025-06-01'))
            ->sum();

        // Model A import 1.50 + Model B import 1.00 = 2.50
        $this->assertEqualsWithDelta(2.50, $total, 0.001);
    }

    public function test_filter_by_multiple_features(): void
    {
        $total = CloudCost::query()
            ->features(['import', 'merge'])
            ->period('month', Carbon::parse('2025-06-01'))
            ->sum();

        $this->assertEqualsWithDelta(3.00, $total, 0.001);
    }

    // ------------------------------------------------------------------
    // period / dateRange
    // ------------------------------------------------------------------

    public function test_period_month_filters_correctly(): void
    {
        $may = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-05-01'))
            ->sum();

        $this->assertEqualsWithDelta(0.75, $may, 0.001);
    }

    public function test_date_range_filters_correctly(): void
    {
        $total = CloudCost::query()
            ->forModel($this->modelA)
            ->dateRange(Carbon::parse('2025-05-01'), Carbon::parse('2025-06-30'))
            ->sum();

        // May 0.75 + June 2.00 = 2.75
        $this->assertEqualsWithDelta(2.75, $total, 0.001);
    }

    // ------------------------------------------------------------------
    // sumByFeature()
    // ------------------------------------------------------------------

    public function test_sum_by_feature_from_rollups(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->sumByFeature();

        $this->assertCount(2, $results);

        $import = $results->firstWhere('feature', 'import');
        $merge = $results->firstWhere('feature', 'merge');

        $this->assertEqualsWithDelta(1.50, (float) $import->total_cost, 0.001);
        $this->assertEqualsWithDelta(0.50, (float) $merge->total_cost, 0.001);
    }

    public function test_sum_by_feature_from_events(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->fromEvents()
            ->sumByFeature();

        $this->assertCount(2, $results);

        $import = $results->firstWhere('feature', 'import');
        $this->assertEquals(3, (int) $import->event_count);
    }

    // ------------------------------------------------------------------
    // sumByDimension()
    // ------------------------------------------------------------------

    public function test_sum_by_dimension(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->sumByDimension();

        $compute = $results->firstWhere('dimension', 'compute');
        $postgres = $results->firstWhere('dimension', 'postgres');
        $cache = $results->firstWhere('dimension', 'cache');

        // compute: 3×0.30 (import) + 2×0.15 (merge) = 0.90 + 0.30 = 1.20
        $this->assertEqualsWithDelta(1.20, $compute->total_cost, 0.001);
        // postgres: 3×0.20 = 0.60
        $this->assertEqualsWithDelta(0.60, $postgres->total_cost, 0.001);
        // cache: 2×0.10 = 0.20
        $this->assertEqualsWithDelta(0.20, $cache->total_cost, 0.001);
    }

    // ------------------------------------------------------------------
    // sumByBillable()
    // ------------------------------------------------------------------

    public function test_sum_by_billable(): void
    {
        $results = CloudCost::query()
            ->period('month', Carbon::parse('2025-06-01'))
            ->sumByBillable(limit: 10);

        $this->assertCount(2, $results);

        // Model A should be first (2.00) then Model B (1.00)
        $first = $results->first();
        $this->assertEquals($this->modelA->getKey(), $first->billable_id);
        $this->assertEqualsWithDelta(2.00, (float) $first->total_cost, 0.001);
    }

    public function test_sum_by_billable_respects_limit(): void
    {
        $results = CloudCost::query()
            ->period('month', Carbon::parse('2025-06-01'))
            ->sumByBillable(limit: 1);

        $this->assertCount(1, $results);
    }

    // ------------------------------------------------------------------
    // timeSeries()
    // ------------------------------------------------------------------

    public function test_time_series_by_day_from_events(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->fromEvents()
            ->timeSeries('day');

        // All June events are on 2025-06-15
        $this->assertCount(1, $results);
        $this->assertEquals('2025-06-15', $results->first()->date);
        $this->assertEqualsWithDelta(2.00, (float) $results->first()->total_cost, 0.001);
    }

    public function test_time_series_by_month_from_rollups(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->dateRange(Carbon::parse('2025-05-01'), Carbon::parse('2025-06-30'))
            ->timeSeries('month');

        $this->assertCount(2, $results);
    }

    // ------------------------------------------------------------------
    // get()
    // ------------------------------------------------------------------

    public function test_get_returns_raw_rollup_collection(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->get();

        $this->assertCount(2, $results); // import + merge rollups
        $this->assertInstanceOf(UsageRollup::class, $results->first());
    }

    public function test_get_returns_raw_event_collection(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->fromEvents()
            ->get();

        $this->assertCount(5, $results); // 3 import + 2 merge
        $this->assertInstanceOf(UsageEvent::class, $results->first());
    }

    // ------------------------------------------------------------------
    // Source switching
    // ------------------------------------------------------------------

    public function test_default_source_is_rollups(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->get();

        $this->assertInstanceOf(UsageRollup::class, $results->first());
    }

    public function test_from_events_switches_source(): void
    {
        $results = CloudCost::query()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->fromEvents()
            ->get();

        $this->assertInstanceOf(UsageEvent::class, $results->first());
    }

    public function test_from_rollups_resets_source(): void
    {
        $results = CloudCost::query()
            ->fromEvents()
            ->fromRollups()
            ->forModel($this->modelA)
            ->period('month', Carbon::parse('2025-06-01'))
            ->get();

        $this->assertInstanceOf(UsageRollup::class, $results->first());
    }

    // ------------------------------------------------------------------
    // No filters returns all data
    // ------------------------------------------------------------------

    public function test_no_filters_returns_all_data(): void
    {
        $total = CloudCost::query()->sum();

        // All rollups across both months: June 3.00 + May 0.75 = 3.75
        $this->assertEqualsWithDelta(3.75, $total, 0.001);
    }
}
