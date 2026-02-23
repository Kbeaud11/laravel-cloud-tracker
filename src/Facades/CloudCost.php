<?php

namespace LaravelCloudTracker\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelCloudTracker\CloudCostManager;
use LaravelCloudTracker\CloudCostQuery;

/**
 * @method static CloudCostManager for(\Illuminate\Database\Eloquent\Model $model)
 * @method static CloudCostManager feature(string $feature)
 * @method static CloudCostManager dimension(string $name, int|float $quantity = 0)
 * @method static CloudCostManager force()
 * @method static CloudCostManager withMetadata(array $metadata)
 * @method static mixed track(callable $callback)
 * @method static CloudCostQuery query()
 *
 * @see CloudCostManager
 */
class CloudCost extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return CloudCostManager::class;
    }
}
