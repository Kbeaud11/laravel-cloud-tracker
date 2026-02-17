<?php

namespace LaravelCloudTracker\Contracts;

interface CostCalculator
{
    /**
     * Calculate the cost breakdown for a tracked operation.
     *
     * @param float $executionTimeMs Wall-clock execution time in milliseconds.
     * @param array $dimensions Keyed by dimension name, values contain optional params like 'quantity'.
     * @param float $multiplier Usage multiplier from the model's tracking policy.
     *
     * @return array{dimensions: array, total_cost: float}
     */
    public function calculate(float $executionTimeMs, array $dimensions, float $multiplier = 1.0): array;
}
