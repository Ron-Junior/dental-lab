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
        Schema::create('service_service_step', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order');
            $table->integer('partner_commission')->nullable();
            $table->string('partner_commission_type')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'service_step_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_service_step');
    }
};
