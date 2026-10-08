<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('product_id')
                  ->constrained('products')
                  ->onDelete('cascade');
            
            $table->string('batch_number', 50);
            $table->decimal('initial_quantity', 10, 2);
            $table->decimal('current_quantity', 10, 2);
            $table->decimal('cost_price', 10, 2)->nullable();
            
            $table->date('expiration_date');
            
            $table->timestamps();

            $table->index(['product_id', 'current_quantity']);
            $table->index('expiration_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_batches');
    }
};
