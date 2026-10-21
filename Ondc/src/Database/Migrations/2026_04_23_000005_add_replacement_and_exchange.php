<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend return_requests with replacement / exchange fields
        Schema::table('ondc_return_requests', function (Blueprint $table) {
            $table->string('flow_type', 20)->default('RETURN')->after('reason_id');
            // RETURN | REPLACEMENT | EXCHANGE

            $table->string('replacement_item_id')->nullable()->after('flow_type');
            $table->string('exchange_item_id')->nullable()->after('replacement_item_id');
            $table->json('exchange_item_specs')->nullable()->after('exchange_item_id');
            // {brand, model, ram, ram_unit, storage, storage_unit, ...}

            $table->string('condition_id', 10)->nullable()->after('exchange_item_specs');
            $table->text('condition_desc')->nullable()->after('condition_id');

            $table->decimal('exchange_price_difference', 12, 2)->default(0)->after('condition_desc');
            // positive = buyer owes more; negative = seller refunds difference
            $table->string('settlement_status', 30)->nullable()->after('exchange_price_difference');
            // PENDING | COLLECTED | REFUNDED | NOT_REQUIRED
        });

        // New forward fulfillment for the replacement/exchange item
        Schema::create('ondc_replacement_fulfillments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_request_id')->index();
            $table->string('replacement_fulfillment_id')->unique();
            $table->enum('flow_type', ['REPLACEMENT', 'EXCHANGE']);
            $table->string('item_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->enum('state', [
                'Pending',
                'Packed',
                'Order-picked-up',
                'Out-for-delivery',
                'Order-delivered',
                'Cancelled',
            ])->default('Pending');
            $table->timestamp('packed_at')->nullable();
            $table->timestamp('picked_at')->nullable();
            $table->timestamp('out_for_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('exchange_specs')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('agent_phone', 20)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('return_request_id')
                  ->references('id')
                  ->on('ondc_return_requests')
                  ->onDelete('cascade');
        });

        // Audit trail
        Schema::create('ondc_replacement_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('replacement_fulfillment_id')->index();
            $table->string('event_type', 30);     // STARTED | PACKED | PICKED | OUT_FOR_DELIVERY | DELIVERED | CANCELLED | PAYMENT_COLLECTED
            $table->string('previous_state', 30)->nullable();
            $table->string('new_state', 30);
            $table->string('triggered_by')->nullable();
            $table->json('payload')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('replacement_fulfillment_id', 'rep_evt_fk')
                  ->references('id')
                  ->on('ondc_replacement_fulfillments')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ondc_replacement_events');
        Schema::dropIfExists('ondc_replacement_fulfillments');
        Schema::table('ondc_return_requests', function (Blueprint $table) {
            $table->dropColumn([
                'flow_type',
                'replacement_item_id',
                'exchange_item_id',
                'exchange_item_specs',
                'condition_id',
                'condition_desc',
                'exchange_price_difference',
                'settlement_status',
            ]);
        });
    }
};
