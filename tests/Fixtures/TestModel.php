<?php

namespace LaravelCloudTracker\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LaravelCloudTracker\Traits\TracksCloudCost;

class TestModel extends Model
{
    use TracksCloudCost;

    protected $table = 'test_models';

    protected $guarded = [];
}
