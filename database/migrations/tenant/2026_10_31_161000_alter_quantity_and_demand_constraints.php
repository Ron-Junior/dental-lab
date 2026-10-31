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
        Schema::table('request_services', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->default(1)->change();
        });

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('partner_demands', function (Blueprint $table) {
                $table->dropForeign(['service_service_step_id']);
                $table->foreign('service_service_step_id')
                    ->references('id')
                    ->on('service_service_step')
                    ->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('partner_demands', function (Blueprint $table) {
                $table->dropForeign(['service_service_step_id']);
                $table->foreign('service_service_step_id')
                    ->references('id')
                    ->on('service_service_step')
                    ->cascadeOnDelete();
            });
        }

        Schema::table('request_services', function (Blueprint $table) {
            $table->tinyInteger('quantity')->nullable()->change();
        });
    }
};

