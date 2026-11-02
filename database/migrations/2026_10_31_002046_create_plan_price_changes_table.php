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
        Schema::create('plan_price_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('central_user_id')->nullable()->constrained('central_users')->nullOnDelete();
            $table->bigInteger('old_price_in_cents');
            $table->bigInteger('new_price_in_cents');
            $table->string('old_stripe_price_id')->nullable();
            $table->string('new_stripe_price_id')->nullable();
            $table->unsignedInteger('affected_subscriptions_count')->default(0);
            $table->timestamps();

            $table->index(['plan_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_price_changes');
    }
};
