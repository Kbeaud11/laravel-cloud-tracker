<?php

namespace LaravelCloudTracker\Tests\Unit;

use InvalidArgumentException;
use LaravelCloudTracker\CostCalculator;
use LaravelCloudTracker\Tests\TestCase;

class CostCalculationTest extends TestCase
{
    protected CostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new CostCalculator;
    }

    public function test_time_based_compute_cost(): void
    {
        // flex-1c-256m: $0.00000165/sec = $0.00000000165/ms
        $result = $this->calculator->calculate(1000.0, ['compute' => []], 1.0);

        $computeCost = $result['dimensions']['compute'];
        $this->assertEquals(1000.0, $computeCost['ms']);

        // 1000ms = 1s × $0.00000165/s = $0.00000165
        $expectedCost = 0.00000165;
        $this->assertEqualsWithDelta($expectedCost, $computeCost['cost'], 0.0000001);
        $this->assertEqualsWithDelta($expectedCost, $result['total_cost'], 0.0000001);
    }

    public function test_time_based_postgres_cost(): void
    {
        // postgres: $0.00002944/sec
        $result = $this->calculator->calculate(500.0, ['postgres' => []], 1.0);

        // 500ms = 0.5s × $0.00002944/s = $0.00001472
        $expected = 0.00001472;
        $this->assertEqualsWithDelta($expected, $result['dimensions']['postgres']['cost'], 0.0000001);
    }

    public function test_count_based_bandwidth_cost(): void
    {
        // bandwidth: $0.10/GB
        $result = $this->calculator->calculate(0.0, ['bandwidth' => ['quantity' => 5]], 1.0);

        // 5 GB × $0.10 = $0.50
        $this->assertEqualsWithDelta(0.50, $result['dimensions']['bandwidth']['cost'], 0.01);
        $this->assertEquals(5, $result['dimensions']['bandwidth']['quantity']);
    }

    public function test_count_based_storage_ops_cost(): void
    {
        // storage: $0.0005/1K operations
        $result = $this->calculator->calculate(0.0, ['storage' => ['quantity' => 10000]], 1.0);

        // 10000 ops / 1000 × $0.0005 = $0.005
        $this->assertEqualsWithDelta(0.005, $result['dimensions']['storage']['cost'], 0.0001);
    }

    public function test_flat_monthly_cache_cost(): void
    {
        // cache 250m: $6/mo, estimated 10M ops/mo → $0.0000006/op
        $result = $this->calculator->calculate(100.0, ['cache' => ['quantity' => 100]], 1.0);

        $perOp = 6.00 / 10_000_000;
        $expected = 100 * $perOp;
        $this->assertEqualsWithDelta($expected, $result['dimensions']['cache']['cost'], 0.000001);
    }

    public function test_flat_monthly_websocket_cost(): void
    {
        // websocket: $5/mo, estimated 1M msgs/mo → $0.000005/msg
        $result = $this->calculator->calculate(50.0, ['websocket' => ['quantity' => 200]], 1.0);

        $perMsg = 5.00 / 1_000_000;
        $expected = 200 * $perMsg;
        $this->assertEqualsWithDelta($expected, $result['dimensions']['websocket']['cost'], 0.000001);
    }

    public function test_multiplier_scales_total_cost(): void
    {
        $result = $this->calculator->calculate(1000.0, ['compute' => []], 2.5);

        $baseCost = 0.00000165; // 1s of flex-1c-256m
        $expected = $baseCost * 2.5;
        $this->assertEqualsWithDelta($expected, $result['total_cost'], 0.0000001);
    }

    public function test_multiple_dimensions_sum_correctly(): void
    {
        $result = $this->calculator->calculate(1000.0, [
            'compute' => [],
            'postgres' => [],
            'bandwidth' => ['quantity' => 1],
        ], 1.0);

        $this->assertCount(3, $result['dimensions']);

        $computeCost = $result['dimensions']['compute']['cost'];
        $postgresCost = $result['dimensions']['postgres']['cost'];
        $bandwidthCost = $result['dimensions']['bandwidth']['cost'];

        $expectedTotal = $computeCost + $postgresCost + $bandwidthCost;
        $this->assertEqualsWithDelta($expectedTotal, $result['total_cost'], 0.0000001);
    }

    public function test_throws_for_unknown_dimension(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("not defined in cloud-tracker config");

        $this->calculator->calculate(100.0, ['nonexistent' => []], 1.0);
    }

    public function test_zero_execution_time_produces_zero_time_cost(): void
    {
        $result = $this->calculator->calculate(0.0, ['compute' => []], 1.0);

        $this->assertEquals(0.0, $result['dimensions']['compute']['cost']);
        $this->assertEquals(0.0, $result['total_cost']);
    }

    public function test_zero_quantity_produces_zero_count_cost(): void
    {
        $result = $this->calculator->calculate(0.0, ['bandwidth' => ['quantity' => 0]], 1.0);

        $this->assertEquals(0.0, $result['dimensions']['bandwidth']['cost']);
    }
}
