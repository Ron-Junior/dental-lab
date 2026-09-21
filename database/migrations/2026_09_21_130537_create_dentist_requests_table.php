<?php

use App\Models\Dentist;
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
        Schema::create('dentist_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Dentist::class)->constrained()->cascadeOnDelete();
            $table->string('code', 5)->unique();
            $table->date('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dentist_requests');
    }
};
