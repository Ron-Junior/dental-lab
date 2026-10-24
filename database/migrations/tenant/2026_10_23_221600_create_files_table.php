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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->morphs('fileable');
            $table->string('name');
            $table->string('path');
            $table->string('disk')->default('s3');
            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('original_size')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
