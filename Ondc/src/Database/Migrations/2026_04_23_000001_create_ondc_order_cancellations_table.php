<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ondc_order_cancellations', function (Blueprint $table) {
            $table->id();
            $table->string('ondc_order_id')->index();
            $table->string('ondc_transaction_id')->index();
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->string('cancellation_reason_id', 10);
            $table->string('cancelled_by');                        // "buyerNP.com" or "sellerNP.com"
            $table->enum('initiated_by', ['BUYER', 'SELLER'])->default('BUYER');
            $table->enum('cancel_type', ['FULL', 'PARTIAL'])->default('FULL');
            $table->string('fulfillment_id')->nullable();          // if cancelling a specific fulfillment
            $table->enum('status', [
                'INITIATED',
                'PROCESSING',
                'CANCELLED',
                'REJECTED',
            ])->default('INITIATED');
            $table->string('precancel_fulfillment_state')->nullable(); // Pending/Packed/Out-for-delivery
            $table->timestamp('precancel_state_updated_at')->nullable();
            $table->json('cancel_request_payload')->nullable();    // raw /cancel request
            $table->json('on_cancel_response_payload')->nullable();// raw /on_cancel response
            $table->text('buyer_note')->nullable();
            $table->text('seller_note')->nullable();
            $table->timestamps();

            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('set null');
        });

        Schema::create('ondc_cancellation_fees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cancellation_id')->index();
            $table->string('fulfillment_id')->nullable();
            $table->string('fee_type', 50);       // "cancellation" | "packing" | "delivery" | "misc"
            $table->string('currency', 5)->default('INR');
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('percentage', 6, 2)->nullable();
            $table->timestamps();

            $table->foreign('cancellation_id')
                  ->references('id')
                  ->on('ondc_order_cancellations')
                  ->onDelete('cascade');
        });

        Schema::create('ondc_cancellation_reasons', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->text('reason');
            $table->boolean('triggers_rto')->default(false);
            $table->string('who_can_use');                       // BNP, SNP, LSP, etc.
            $table->string('cost_attributed_to');
            $table->boolean('applicable_for_part_cancel')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ondc_cancellation_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->string('ondc_order_id')->nullable()->index();
            $table->string('fulfillment_state', 50);              // Pending, Packed, Order-picked-up, Out-for-delivery
            $table->string('applicable_reason_codes')->nullable(); // comma-separated: "001,003"
            $table->decimal('cancellation_fee_percentage', 6, 2)->default(0);
            $table->string('cancellation_fee_currency', 5)->default('INR');
            $table->decimal('cancellation_fee_amount', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('order_id')
                  ->references('id')
                  ->on('orders')
                  ->onDelete('cascade');
        });

        Schema::create('ondc_cancel_quote_trails', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cancellation_id')->index();
            $table->string('type', 50);            // "item" | "delivery" | "tax" | "misc" | "refund"
            $table->string('ref_id')->nullable();  // fulfillment_id or item_id
            $table->string('currency', 5)->default('INR');
            $table->decimal('value', 12, 2)->default(0);
            $table->string('subtype')->nullable();
            $table->timestamps();

            $table->foreign('cancellation_id')
                  ->references('id')
                  ->on('ondc_order_cancellations')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ondc_cancel_quote_trails');
        Schema::dropIfExists('ondc_cancellation_terms');
        Schema::dropIfExists('ondc_cancellation_fees');
        Schema::dropIfExists('ondc_order_cancellations');
        Schema::dropIfExists('ondc_cancellation_reasons');
    }
};
