<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liquidation tracking on cancellations
        Schema::table('ondc_order_cancellations', function (Blueprint $table) {
            $table->string('disposition_type', 30)->nullable()->after('rto_disposed_at');  // RESTOCKED | LIQUIDATED | DISPOSED | PARTIAL_LIQUIDATED | INTERIM_LIQUIDATING
            $table->decimal('liquidation_value', 12, 2)->nullable()->after('disposition_type');
            $table->string('liquidation_channel', 100)->nullable()->after('liquidation_value'); // AUCTION | DISCOUNT_SALE | WRITE_OFF | B2B_BULK
            $table->text('liquidation_notes')->nullable()->after('liquidation_channel');
            $table->timestamp('liquidated_at')->nullable()->after('liquidation_notes');
        });

        // Per-item cancellation + disposition tracking
        Schema::create('ondc_cancelled_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cancellation_id')->index();
            $table->string('item_id');                               // ONDC item id
            $table->string('fulfillment_id')->nullable();
            $table->unsignedInteger('original_quantity')->default(0);
            $table->unsignedInteger('cancelled_quantity')->default(0);
            $table->unsignedInteger('remaining_quantity')->default(0);
            $table->decimal('item_price', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->string('disposition', 30)->default('PENDING');   // PENDING | RESTOCKED | LIQUIDATED | DISPOSED | INTERIM
            $table->decimal('liquidation_value', 12, 2)->nullable();
            $table->string('liquidation_channel', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('disposed_at')->nullable();
            $table->timestamps();

            $table->foreign('cancellation_id')
                  ->references('id')
                  ->on('ondc_order_cancellations')
                  ->onDelete('cascade');
        });

        // Audit trail for liquidation state changes
        Schema::create('ondc_liquidation_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cancellation_id')->index();
            $table->unsignedBigInteger('cancelled_item_id')->nullable();
            $table->string('event_type', 30);                        // REQUESTED | INTERIM | APPROVED | COMPLETED | REJECTED
            $table->string('disposition', 30);
            $table->string('channel', 100)->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('triggered_by')->nullable();
            $table->json('payload')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('cancellation_id')
                  ->references('id')
                  ->on('ondc_order_cancellations')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ondc_liquidation_events');
        Schema::dropIfExists('ondc_cancelled_items');
        Schema::table('ondc_order_cancellations', function (Blueprint $table) {
            $table->dropColumn([
                'disposition_type',
                'liquidation_value',
                'liquidation_channel',
                'liquidation_notes',
                'liquidated_at',
            ]);
        });
    }
};
