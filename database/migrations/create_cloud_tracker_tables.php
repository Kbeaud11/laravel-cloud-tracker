<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_tracking_policies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('billable');
            $table->string('tracking_mode')->default('all');
            $table->json('tracking_features')->nullable();
            $table->decimal('usage_multiplier', 8, 4)->default(1.0000);
            $table->timestamps();

            $table->unique(['billable_type', 'billable_id']);
        });

        Schema::create('model_usage_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('billable');
            $table->string('feature');
            $table->decimal('execution_time_ms', 16, 4);
            $table->decimal('computed_cost', 20, 10)->default(0);
            $table->json('cost_dimensions');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['billable_type', 'billable_id', 'feature']);
            $table->index('created_at');
        });

        Schema::create('model_usage_rollups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('billable');
            $table->string('feature');
            $table->date('period_start');
            $table->decimal('total_execution_ms', 20, 4)->default(0);
            $table->decimal('total_cost', 20, 10)->default(0);
            $table->unsignedBigInteger('event_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['billable_type', 'billable_id', 'feature', 'period_start'],
                'model_usage_rollups_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_usage_rollups');
        Schema::dropIfExists('model_usage_events');
        Schema::dropIfExists('model_tracking_policies');
    }
};
