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
        if (!Schema::hasTable('ondc_product_mappings')) {
            Schema::create('ondc_product_mappings', function (Blueprint $table) {
                $table->id();
                $table->string('ondc_item_id');      // "I1", "I2", ...
                $table->unsignedBigInteger('ondc_product_id'); // ondc_products.id
                $table->unsignedBigInteger('product_id');
                $table->string('product_sku')->nullable();
                $table->timestamps();

                $table->unique('ondc_item_id');      // one ONDC item → one product
                $table->index('ondc_product_id');
                $table->index('product_id');
                $table->index('product_sku');
            });

            // 2. ondc_item_id in order_items (optional, for audit)
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('ondc_item_id')->nullable()->after('sku');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ondc_product_mappings');
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('ondc_item_id');
        });
    }
};
