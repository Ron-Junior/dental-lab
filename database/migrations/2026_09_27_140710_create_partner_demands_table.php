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
        Schema::create('partner_demands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_service_step_id')->constrained('service_service_step')->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->cascadeOnDelete();
            $table->tinyInteger('order');
            $table->integer('partner_commission')->nullable();
            $table->string('partner_commission_type')->nullable();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_demands');
    }
};
