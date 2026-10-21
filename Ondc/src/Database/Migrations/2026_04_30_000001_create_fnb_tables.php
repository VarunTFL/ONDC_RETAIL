<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Restaurants / providers (F&B-specific catalog)
        Schema::create('ondc_fnb_catalogs', function (Blueprint $table) {
            $table->id();
            $table->string('provider_id')->unique();          // ONDC provider id
            $table->string('name');
            $table->string('cuisine')->nullable();             // North Indian, Chinese, etc.
            $table->boolean('is_veg_only')->default(false);
            $table->string('fssai_license')->nullable();       // FSSAI license number
            $table->string('city', 30)->default('std:080');
            $table->string('gps')->nullable();                 // lat,lng
            $table->json('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->json('time_serviced')->nullable();         // operational hours
            $table->string('preparation_time', 20)->default('PT30M');
            $table->boolean('is_active')->default(true);
            $table->json('tags')->nullable();
            $table->timestamps();
        });

        // F&B menu items (with parent_item_id for customizable parents)
        Schema::create('ondc_fnb_items', function (Blueprint $table) {
            $table->id();
            $table->string('ondc_item_id')->unique();
            $table->string('parent_item_id')->nullable()->index(); // links to a parent for customization groups
            $table->unsignedBigInteger('catalog_id')->index();
            $table->enum('type', ['item', 'customization'])->default('item');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('maximum_price', 12, 2)->nullable();
            $table->string('currency', 5)->default('INR');
            $table->enum('veg_status', ['veg', 'non-veg', 'egg'])->default('veg');
            $table->string('category_id')->nullable();
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('available_count')->default(99);
            $table->unsignedInteger('maximum_count')->default(99);
            $table->string('preparation_time', 20)->default('PT15M');
            $table->boolean('is_returnable')->default(false);
            $table->boolean('is_cancellable')->default(true);
            $table->json('statutory_info')->nullable();        // packaged food info
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->foreign('catalog_id')
                  ->references('id')
                  ->on('ondc_fnb_catalogs')
                  ->onDelete('cascade');
        });

        // Customization groups (e.g. "Choose your size", "Add toppings")
        Schema::create('ondc_fnb_customization_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('catalog_id')->index();
            $table->string('group_id')->unique();              // e.g. "CG1"
            $table->string('parent_item_id')->index();         // which menu item this group belongs to
            $table->string('name');
            $table->unsignedSmallInteger('min_select')->default(0);
            $table->unsignedSmallInteger('max_select')->default(1);
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('catalog_id')
                  ->references('id')
                  ->on('ondc_fnb_catalogs')
                  ->onDelete('cascade');
        });

        // Discovery search audit log
        Schema::create('ondc_fnb_discovery_searches', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id');
            $table->string('city', 30)->nullable();
            $table->json('intent')->nullable();
            $table->string('finder_fee_type', 20)->nullable();
            $table->decimal('finder_fee_amount', 6, 2)->nullable();
            $table->unsignedInteger('catalog_count')->default(0);
            $table->json('response_payload')->nullable();
            $table->timestamps();
        });

        // F&B order — stores the order with type tags + customization links
        Schema::create('ondc_fnb_orders', function (Blueprint $table) {
            $table->id();
            $table->string('ondc_order_id')->unique();
            $table->string('ondc_transaction_id')->index();
            $table->unsignedBigInteger('catalog_id');
            $table->json('items');                             // full items array with customizations
            $table->json('billing');
            $table->json('fulfillments');
            $table->json('quote');
            $table->json('payment')->nullable();
            $table->enum('state', [
                'Created',
                'Accepted',
                'In-progress',
                'Completed',
                'Cancelled',
            ])->default('Created');
            $table->enum('fulfillment_state', [
                'Pending', 'Packed', 'Order-picked-up', 'Out-for-delivery', 'Order-delivered', 'Cancelled',
            ])->default('Pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('catalog_id')
                  ->references('id')
                  ->on('ondc_fnb_catalogs')
                  ->onDelete('cascade');
        });

        // F&B inventory + OOS tracking
        Schema::create('ondc_fnb_inventory_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id')->index();
            $table->enum('event_type', ['STOCK_UPDATE', 'OUT_OF_STOCK', 'BACK_IN_STOCK', 'PRICE_CHANGE'])->default('STOCK_UPDATE');
            $table->integer('previous_count')->nullable();
            $table->integer('new_count')->nullable();
            $table->decimal('previous_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2)->nullable();
            $table->string('triggered_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('item_id')
                  ->references('id')
                  ->on('ondc_fnb_items')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ondc_fnb_inventory_events');
        Schema::dropIfExists('ondc_fnb_orders');
        Schema::dropIfExists('ondc_fnb_discovery_searches');
        Schema::dropIfExists('ondc_fnb_customization_groups');
        Schema::dropIfExists('ondc_fnb_items');
        Schema::dropIfExists('ondc_fnb_catalogs');
    }
};
