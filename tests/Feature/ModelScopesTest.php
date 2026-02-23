<?php

namespace LaravelCloudTracker\Tests\Feature;

use Carbon\Carbon;
use LaravelCloudTracker\Models\UsageEvent;
use LaravelCloudTracker\Models\UsageRollup;
use LaravelCloudTracker\Tests\Fixtures\TestModel;
use LaravelCloudTracker\Tests\TestCase;

class ModelScopesTest extends TestCase
{
    protected TestModel $modelA;

    protected TestModel $modelB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2025-06-15 12:00:00');

        $this->modelA = TestModel::create(['name' => 'Org A']);
        $this->modelB = TestModel::create(['name' => 'Org B']);

        // Rollups
        UsageRollup::create([
            'billable_type' => $this->modelA->getMorphClass(),
            'billable_id' => $this->modelA->getKey(),
            'feature' => 'import',
            'period_start' => '2025-06-01',
            'total_execution_ms' => 100,
            'total_cost' => 1.50,
            'event_count' => 3,
        ]);

        UsageRollup::create([
            'billable_type' => $this->modelB->getMorphClass(),
            'billable_id' => $this->modelB->getKey(),
            'feature' => 'merge',
            'period_start' => '2025-05-01',
            'total_execution_ms' => 50,
            'total_cost' => 0.75,
            'event_count' => 1,
        ]);

        // Events
        UsageEvent::create([
            'billable_type' => $this->modelA->getMorphClass(),
            'billable_id' => $this->modelA->getKey(),
            'feature' => 'import',
            'execution_time_ms' => 100,
            'computed_cost' => 0.50,
            'cost_dimensions' => ['compute' => ['cost' => 0.50]],
            'created_at' => '2025-06-15 12:00:00',
        ]);

        UsageEvent::create([
            'billable_type' => $this->modelB->getMorphClass(),
            'billable_id' => $this->modelB->getKey(),
            'feature' => 'merge',
            'execution_time_ms' => 50,
            'computed_cost' => 0.75,
            'cost_dimensions' => ['compute' => ['cost' => 0.75]],
            'created_at' => '2025-05-10 12:00:00',
        ]);
    }

    // ------------------------------------------------------------------
    // UsageRollup scopes
    // ------------------------------------------------------------------

    public function test_rollup_scope_for_billable_instance(): void
    {
        $results = UsageRollup::forBillable($this->modelA)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('import', $results->first()->feature);
    }

    public function test_rollup_scope_for_billable_class(): void
    {
        $results = UsageRollup::forBillable(TestModel::class)->get();

        $this->assertCount(2, $results);
    }

    public function test_rollup_scope_for_feature_string(): void
    {
        $results = UsageRollup::forFeature('import')->get();

        $this->assertCount(1, $results);
    }

    public function test_rollup_scope_for_feature_array(): void
    {
        $results = UsageRollup::forFeature(['import', 'merge'])->get();

        $this->assertCount(2, $results);
    }

    public function test_rollup_scope_in_period(): void
    {
        $results = UsageRollup::inPeriod('month', Carbon::parse('2025-06-01'))->get();

        $this->assertCount(1, $results);
        $this->assertEquals('import', $results->first()->feature);
    }

    public function test_rollup_scope_in_date_range(): void
    {
        $results = UsageRollup::inDateRange(
            Carbon::parse('2025-05-01'),
            Carbon::parse('2025-06-30'),
        )->get();

        $this->assertCount(2, $results);
    }

    // ------------------------------------------------------------------
    // UsageEvent scopes
    // ------------------------------------------------------------------

    public function test_event_scope_for_billable_instance(): void
    {
        $results = UsageEvent::forBillable($this->modelA)->get();

        $this->assertCount(1, $results);
        $this->assertEquals('import', $results->first()->feature);
    }

    public function test_event_scope_for_billable_class(): void
    {
        $results = UsageEvent::forBillable(TestModel::class)->get();

        $this->assertCount(2, $results);
    }

    public function test_event_scope_for_feature_string(): void
    {
        $results = UsageEvent::forFeature('merge')->get();

        $this->assertCount(1, $results);
    }

    public function test_event_scope_for_feature_array(): void
    {
        $results = UsageEvent::forFeature(['import', 'merge'])->get();

        $this->assertCount(2, $results);
    }

    public function test_event_scope_in_period(): void
    {
        $results = UsageEvent::inPeriod('month', Carbon::parse('2025-05-01'))->get();

        $this->assertCount(1, $results);
        $this->assertEquals('merge', $results->first()->feature);
    }

    public function test_event_scope_in_date_range(): void
    {
        $results = UsageEvent::inDateRange(
            Carbon::parse('2025-05-01'),
            Carbon::parse('2025-06-30'),
        )->get();

        $this->assertCount(2, $results);
    }

    // ------------------------------------------------------------------
    // Combined scopes
    // ------------------------------------------------------------------

    public function test_scopes_can_be_chained(): void
    {
        $results = UsageRollup::forBillable($this->modelA)
            ->forFeature('import')
            ->inPeriod('month', Carbon::parse('2025-06-01'))
            ->get();

        $this->assertCount(1, $results);
        $this->assertEqualsWithDelta(1.50, (float) $results->first()->total_cost, 0.001);
    }
}
