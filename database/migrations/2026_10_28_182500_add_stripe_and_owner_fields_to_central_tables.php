<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('owner_email')->nullable()->after('name');
            $table->timestamp('verified_at')->nullable()->after('owner_email');
            $table->string('stripe_customer_id')->nullable()->after('verified_at');
            $table->string('card_brand', 30)->nullable()->after('stripe_customer_id');
            $table->string('card_last_four', 4)->nullable()->after('card_brand');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('stripe_subscription_id')->nullable()->after('plan_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->string('stripe_price_id')->nullable()->after('billing_cycle');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['stripe_price_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['stripe_subscription_id']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'owner_email',
                'verified_at',
                'stripe_customer_id',
                'card_brand',
                'card_last_four',
            ]);
        });
    }
};
