<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->bigInteger('price_in_cents')->nullable()->after('plan_id');
            $table->string('stripe_price_id')->nullable()->after('price_in_cents');
            $table->foreignUuid('scheduled_plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->bigInteger('scheduled_price_in_cents')->nullable();
            $table->string('scheduled_stripe_price_id')->nullable();
            $table->timestamp('price_change_effective_at')->nullable()->index();
        });

        DB::table('subscriptions')
            ->whereNull('price_in_cents')
            ->update([
                'price_in_cents' => DB::raw('(select plans.price_in_cents from plans where plans.id = subscriptions.plan_id)'),
                'stripe_price_id' => DB::raw('(select plans.stripe_price_id from plans where plans.id = subscriptions.plan_id)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $hasScheduledPlan = Schema::hasColumn('subscriptions', 'scheduled_plan_id');

        Schema::table('subscriptions', function (Blueprint $table) use ($hasScheduledPlan) {
            if ($hasScheduledPlan) {
                $table->dropForeign(['scheduled_plan_id']);
            }

            $table->dropIndex(['price_change_effective_at']);

            $columns = [
                'price_in_cents',
                'stripe_price_id',
                'scheduled_price_in_cents',
                'scheduled_stripe_price_id',
                'price_change_effective_at',
            ];

            if ($hasScheduledPlan) {
                $columns[] = 'scheduled_plan_id';
            }

            $table->dropColumn($columns);
        });
    }
};
