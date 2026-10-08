<?php

use Database\Seeders\ProductCategorySeeder;
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();

            $table->string('name', 150);
            $table->string('unit_of_measure', 10);

            $table->decimal('current_stock', 10, 2)->default(0.00);
            $table->decimal('min_stock', 10, 2)->default(0.00);
            
            $table->boolean('has_batches')->default(false);
            $table->boolean('is_active')->default(true);
            
            $table->index(['category_id', 'name']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
