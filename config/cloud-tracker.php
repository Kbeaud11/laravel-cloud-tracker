<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Cloud Cost Tracking
    |--------------------------------------------------------------------------
    |
    | Master switch for the entire package. When disabled, track() calls
    | still execute the callback but skip all timing, cost calculation,
    | and database writes.
    |
    */
    'enabled' => env('CLOUD_TRACKER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Allowed Environments
    |--------------------------------------------------------------------------
    |
    | Tracking will only run in these environments. Local is excluded by
    | default so development machines incur zero overhead.
    |
    */
    'environments' => ['production', 'staging'],

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud Plan
    |--------------------------------------------------------------------------
    |
    | Your current Laravel Cloud plan. This is stored for reference and
    | future quota features. It does not affect per-unit rates (those are
    | determined by your instance selections below).
    |
    | Supported: "starter", "growth", "business", "enterprise"
    |
    */
    'plan' => env('CLOUD_TRACKER_PLAN', 'growth'),

    /*
    |--------------------------------------------------------------------------
    | Rollup Timeframe
    |--------------------------------------------------------------------------
    |
    | The period used to bucket rollups. Currently only 'monthly' is
    | supported. This determines the period_start value on rollup rows.
    |
    */
    'timeframe' => 'monthly',

    /*
    |--------------------------------------------------------------------------
    | Event Logging
    |--------------------------------------------------------------------------
    |
    | When enabled, every track() call writes a row to model_usage_events.
    | Disable this if you only need rollup aggregates and want to reduce
    | write volume.
    |
    */
    'log_events' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Cost Dimension
    |--------------------------------------------------------------------------
    |
    | When no dimension is explicitly specified on a track() call, this
    | dimension is applied automatically.
    |
    */
    'default_dimension' => 'compute',

    /*
    |--------------------------------------------------------------------------
    | Cost Dimensions
    |--------------------------------------------------------------------------
    |
    | Single source of truth for all infrastructure cost rates. Each
    | dimension defines its unit type and rate. Rates are pre-populated
    | from Laravel Cloud's published pricing (US East region).
    |
    | Time-based dimensions: cost = execution_time_ms × per_ms rate
    | Count-based dimensions: cost = quantity × per_unit rate
    | Flat dimensions: cost = monthly_rate ÷ seconds_in_month × execution_time_ms / 1000
    |
    | To use a specific instance size, set the corresponding env var or
    | override the 'active' key for that dimension.
    |
    | All rates in USD. Last updated from cloud.laravel.com/docs/pricing.
    |
    */
    'costs' => [

        /*
        |----------------------------------------------------------------------
        | Compute (App Workers)
        |----------------------------------------------------------------------
        |
        | Billed per second. Select your active instance size.
        | Rates are per-second, converted to per-ms internally.
        |
        */
        'compute' => [
            'unit' => 'time',
            'active' => env('CLOUD_TRACKER_COMPUTE_INSTANCE', 'flex-1c-256m'),
            'instances' => [
                // Flex instances (Starter+)
                'flex-1c-256m'  => ['per_second' => 0.00000165],
                'flex-2c-512m'  => ['per_second' => 0.00000331],
                'flex-4c-2g'    => ['per_second' => 0.00000661],
                // Pro instances (Growth+)
                'pro-1c-1g'     => ['per_second' => 0.00000827],
                'pro-2c-4g'     => ['per_second' => 0.00001650],
                'pro-4c-8g'     => ['per_second' => 0.00003310],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Serverless Postgres
        |----------------------------------------------------------------------
        |
        | Billed at $0.106/hour per vCPU. Rate below is per-second.
        | Track heavy queries that consume significant DB CPU.
        |
        */
        'postgres' => [
            'unit' => 'time',
            'per_second' => 0.00002944,
        ],

        /*
        |----------------------------------------------------------------------
        | Queue Workers
        |----------------------------------------------------------------------
        |
        | Queue workers use the same compute pricing as app workers.
        | Select your worker instance size independently.
        |
        */
        'queue' => [
            'unit' => 'time',
            'active' => env('CLOUD_TRACKER_QUEUE_INSTANCE', 'flex-1c-256m'),
            'instances' => [
                'flex-1c-256m'  => ['per_second' => 0.00000165],
                'flex-2c-512m'  => ['per_second' => 0.00000331],
                'flex-4c-2g'    => ['per_second' => 0.00000661],
                'pro-1c-1g'     => ['per_second' => 0.00000827],
                'pro-2c-4g'     => ['per_second' => 0.00001650],
                'pro-4c-8g'     => ['per_second' => 0.00003310],
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Redis / Valkey Cache
        |----------------------------------------------------------------------
        |
        | Valkey is billed as a flat monthly rate based on memory tier.
        | For per-operation cost estimation, the package divides the
        | monthly rate by estimated operations/month.
        |
        | If you use Redis by Upstash, it is billed daily — adjust
        | accordingly.
        |
        */
        'cache' => [
            'unit' => 'flat_monthly',
            'active' => env('CLOUD_TRACKER_CACHE_TIER', '250m'),
            'tiers' => [
                '250m' => ['monthly' => 6.00],
                '1g'   => ['monthly' => 20.00],
                '2g'   => ['monthly' => 40.00],
                '5g'   => ['monthly' => 80.00],
                '10g'  => ['monthly' => 140.00],
                '25g'  => ['monthly' => 200.00],
                '50g'  => ['monthly' => 272.00],
            ],
            // Estimated operations per month for flat-rate cost amortization.
            // Adjust based on your actual usage patterns.
            'estimated_operations_per_month' => 10_000_000,
        ],

        /*
        |----------------------------------------------------------------------
        | WebSockets (Reverb)
        |----------------------------------------------------------------------
        |
        | Reverb is billed as a flat monthly rate. For per-message cost
        | estimation, the monthly rate is amortized over estimated
        | messages per month.
        |
        */
        'websocket' => [
            'unit' => 'flat_monthly',
            'monthly' => env('CLOUD_TRACKER_WEBSOCKET_MONTHLY', 5.00),
            'estimated_messages_per_month' => 1_000_000,
        ],

        /*
        |----------------------------------------------------------------------
        | Bandwidth (Data Transfer)
        |----------------------------------------------------------------------
        |
        | $0.10 per GB. Count-based — pass bytes transferred.
        |
        */
        'bandwidth' => [
            'unit' => 'count',
            'per_gb' => 0.10,
        ],

        /*
        |----------------------------------------------------------------------
        | Object Storage
        |----------------------------------------------------------------------
        |
        | $0.02/GB/month storage, $0.0005/1K operations.
        | Count-based — pass number of operations.
        |
        */
        'storage' => [
            'unit' => 'count',
            'per_1k_operations' => 0.0005,
            'per_gb_month' => 0.02,
        ],

    ],

];
