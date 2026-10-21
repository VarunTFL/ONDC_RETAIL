<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;

return new class extends Migration
{
    protected $table = 'ondc_products';

    protected $guarded = [];

    public function mappings()
    {
        return $this->hasMany(
            \App\Models\OndcProductMapping::class,
            'ondc_product_id'
        );
    }
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ondc_products')) {
            Schema::create('ondc_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('product_id');    // ← INT(10) unsigned, same as products.id
                $table->timestamps();

                // $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ondc_products');
    }
};