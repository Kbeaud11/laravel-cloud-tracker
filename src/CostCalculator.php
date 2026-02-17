<?php

namespace LaravelCloudTracker;

use InvalidArgumentException;
use LaravelCloudTracker\Contracts\CostCalculator as CostCalculatorContract;

class CostCalculator implements CostCalculatorContract
{
    /**
     * {@inheritDoc}
     *
     * Iterates over each requested dimension, resolves the rate from config,
     * and computes cost based on the dimension's unit type (time, count, or flat_monthly).
     *
     * @throws InvalidArgumentException When a dimension is not defined in config.
     */
    public function calculate(float $executionTimeMs, array $dimensions, float $multiplier = 1.0): array
    {
        $costs = config('cloud-tracker.costs', []);
        $breakdown = [];
        $totalCost = 0.0;

        foreach ($dimensions as $dimensionName => $params) {
            $dimensionConfig = $costs[$dimensionName] ?? null;

            if ($dimensionConfig === null) {
                throw new InvalidArgumentException(
                    "Cost dimension '{$dimensionName}' is not defined in cloud-tracker config."
                );
            }

            $cost = $this->calculateDimension($dimensionConfig, $executionTimeMs, $params);
            $breakdown[$dimensionName] = $cost;
            $totalCost += $cost['cost'];
        }

        $totalCost *= $multiplier;

        return [
            'dimensions' => $breakdown,
            'total_cost' => $totalCost,
        ];
    }

    /**
     * Calculate cost for a single dimension, dispatching by unit type.
     *
     * @param array $config Dimension config from cloud-tracker.costs.{name}.
     * @param float $executionTimeMs Execution time in milliseconds.
     * @param array $params Optional params (e.g. 'quantity' for count-based).
     *
     * @return array Per-dimension cost breakdown.
     *
     * @throws InvalidArgumentException When the unit type is unknown.
     */
    protected function calculateDimension(array $config, float $executionTimeMs, array $params): array
    {
        $unit = $config['unit'] ?? 'time';

        return match ($unit) {
            'time' => $this->calculateTimeBased($config, $executionTimeMs),
            'count' => $this->calculateCountBased($config, $params),
            'flat_monthly' => $this->calculateFlatMonthly($config, $executionTimeMs, $params),
            default => throw new InvalidArgumentException("Unknown cost unit type: {$unit}"),
        };
    }

    /**
     * Calculate cost for a time-based dimension (compute, postgres, queue).
     *
     * Supports flat per_second rates and instance-selection from an 'instances' map.
     *
     * @param array $config Dimension config with 'per_second' or 'instances' + 'active'.
     * @param float $executionTimeMs Execution time in milliseconds.
     *
     * @return array{ms: float, cost: float}
     *
     * @throws InvalidArgumentException When the active instance is not found.
     */
    protected function calculateTimeBased(array $config, float $executionTimeMs): array
    {
        $perSecond = $this->resolvePerSecondRate($config);
        $perMs = $perSecond / 1000;

        return [
            'ms' => $executionTimeMs,
            'cost' => $executionTimeMs * $perMs,
        ];
    }

    /**
     * Calculate cost for a count-based dimension (bandwidth, storage ops).
     *
     * Finds the first per_* rate key and multiplies by quantity.
     * For per_1k_* rates, quantity is divided by 1000 first.
     *
     * @param array $config Dimension config with at least one per_* rate key.
     * @param array $params Must include 'quantity'.
     *
     * @return array{quantity: int|float, cost: float}
     */
    protected function calculateCountBased(array $config, array $params): array
    {
        $quantity = $params['quantity'] ?? 0;
        $cost = 0.0;

        foreach ($config as $key => $value) {
            if (! is_numeric($value) || $key === 'unit') {
                continue;
            }

            if (str_starts_with($key, 'per_')) {
                $cost = str_contains($key, '1k')
                    ? ($quantity / 1000) * $value
                    : $quantity * $value;
                break;
            }
        }

        return [
            'quantity' => $quantity,
            'cost' => $cost,
        ];
    }

    /**
     * Calculate cost for a flat-monthly dimension (cache, websockets).
     *
     * Amortizes the monthly rate over estimated usage volume to derive a per-unit cost.
     *
     * @param array $config Dimension config with 'monthly' or 'tiers' + 'active'.
     * @param float $executionTimeMs Execution time in milliseconds.
     * @param array $params Optional 'quantity' for message/operation counts.
     *
     * @return array{ms: float, quantity: int|float, cost: float}
     */
    protected function calculateFlatMonthly(array $config, float $executionTimeMs, array $params): array
    {
        $monthly = $this->resolveMonthlyRate($config);
        $quantity = $params['quantity'] ?? 0;

        $estimatedOps = $config['estimated_operations_per_month']
            ?? $config['estimated_messages_per_month']
            ?? 1_000_000;

        $perUnit = $estimatedOps > 0 ? $monthly / $estimatedOps : 0.0;

        return [
            'ms' => $executionTimeMs,
            'quantity' => $quantity,
            'cost' => $quantity > 0 ? $quantity * $perUnit : 0.0,
        ];
    }

    /**
     * Resolve per-second rate from a flat key or an instance-selection map.
     *
     * @param array $config The dimension config.
     *
     * @return float Per-second rate in USD.
     *
     * @throws InvalidArgumentException When the active instance is not found.
     */
    protected function resolvePerSecondRate(array $config): float
    {
        if (isset($config['per_second'])) {
            return (float) $config['per_second'];
        }

        if (isset($config['instances'], $config['active'])) {
            $instance = $config['instances'][$config['active']] ?? null;

            if ($instance === null) {
                throw new InvalidArgumentException(
                    "Instance '{$config['active']}' not found in cost dimension config."
                );
            }

            return (float) $instance['per_second'];
        }

        return 0.0;
    }

    /**
     * Resolve monthly rate from a flat key or a tier-selection map.
     *
     * @param array $config The dimension config.
     *
     * @return float Monthly rate in USD.
     */
    protected function resolveMonthlyRate(array $config): float
    {
        if (isset($config['monthly'])) {
            return (float) $config['monthly'];
        }

        if (isset($config['tiers'], $config['active'])) {
            $tier = $config['tiers'][$config['active']] ?? null;

            return $tier ? (float) $tier['monthly'] : 0.0;
        }

        return 0.0;
    }
}
