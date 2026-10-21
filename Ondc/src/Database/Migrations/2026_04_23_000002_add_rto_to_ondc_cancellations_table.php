<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add RTO-tracking columns to existing cancellation table
        Schema::table('ondc_order_cancellations', function (Blueprint $table) {
            $table->boolean('triggers_rto')->default(false)->after('cancel_type');
            $table->string('rto_status', 30)->nullable()->after('triggers_rto');   // RTO-Initiated / RTO-Delivered / RTO-Disposed
            $table->string('rto_fulfillment_id')->nullable()->after('rto_status');
            $table->unsignedSmallInteger('retry_count')->default(0)->after('rto_fulfillment_id');
            $table->timestamp('rto_initiated_at')->nullable()->after('retry_count');
            $table->timestamp('rto_delivered_at')->nullable()->after('rto_initiated_at');
            $table->timestamp('rto_disposed_at')->nullable()->after('rto_delivered_at');
        });

        // Track RTO state-change history (audit trail)
        Schema::create('ondc_rto_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cancellation_id')->index();
            $table->string('rto_fulfillment_id');
            $table->string('event_type', 30);                  // RTO-Initiated / RTO-Delivered / RTO-Disposed
            $table->string('previous_state', 30)->nullable();
            $table->string('new_state', 30);
            $table->string('triggered_by')->nullable();         // SNP / LSP id
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
        Schema::dropIfExists('ondc_rto_events');
        Schema::table('ondc_order_cancellations', function (Blueprint $table) {
            $table->dropColumn([
                'triggers_rto',
                'rto_status',
                'rto_fulfillment_id',
                'retry_count',
                'rto_initiated_at',
                'rto_delivered_at',
                'rto_disposed_at',
            ]);
        });
    }
};
