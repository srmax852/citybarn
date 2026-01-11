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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->decimal('price', 10, 2);
            $table->string('barcode')->nullable();
            $table->string('dep_id')->nullable();
            $table->string('sub_id')->nullable();
            $table->string('shopify_product_id')->nullable();
            $table->timestamps();

            $table->foreign('dep_id')->references('dep_id')->on('departments')->onDelete('set null');
            $table->foreign('sub_id')->references('sub_id')->on('sub_departments')->onDelete('set null');
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
