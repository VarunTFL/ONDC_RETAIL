<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ondc_return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('ondc_order_id')->index();
            $table->string('ondc_transaction_id')->index();
            $table->unsignedInteger('order_id')->nullable()->index();
            $table->string('return_request_id')->unique();        // ONDC return_request.id (UUID)
            $table->string('item_id');
            $table->string('parent_item_id')->nullable();
            $table->unsignedInteger('item_quantity')->default(1);
            $table->string('reason_id', 10);
            $table->text('reason_desc')->nullable();
            $table->json('images')->nullable();                    // buyer-uploaded evidence
            $table->string('ttl_approval', 20)->default('PT24H');
            $table->string('ttl_reverseqc', 20)->default('P3D');
            $table->enum('status', [
                'REQUESTED',
                'APPROVED',
                'REJECTED',
                'PICKUP_INITIATED',
                'QC_IN_PROGRESS',
                'QC_PASSED',
                'QC_FAILED',
                'REFUND_PROCESSED',
                'COMPLETED',
                'CLOSED',
            ])->default('REQUESTED');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->unsignedBigInteger('cancellation_id')->nullable()->index();
            $table->json('request_payload')->nullable();
            $table->timestamps();

            $table->foreign('cancellation_id')
                  ->references('id')
                  ->on('ondc_order_cancellations')
                  ->onDelete('set null');
        });

        Schema::create('ondc_reverse_qc_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_request_id')->index();
            $table->enum('qc_status', [
                'INITIATED',
                'PICKUP_DONE',
                'IN_PROGRESS',
                'PASSED',
                'FAILED',
                'PARTIAL_PASS',
            ])->default('INITIATED');
            $table->timestamp('qc_initiated_at')->nullable();
            $table->timestamp('qc_completed_at')->nullable();
            $table->string('pickup_proof_code', 100)->nullable();    // PCC
            $table->string('delivery_proof_code', 100)->nullable();  // DCC
            $table->text('qc_notes')->nullable();
            $table->text('failure_reason')->nullable();
            $table->decimal('approved_refund', 12, 2)->nullable();
            $table->string('inspector')->nullable();
            $table->json('evidence_urls')->nullable();
            $table->json('checklist')->nullable();                   // qc checklist results
            $table->timestamps();

            $table->foreign('return_request_id')
                  ->references('id')
                  ->on('ondc_return_requests')
                  ->onDelete('cascade');
        });

        Schema::create('ondc_reverse_qc_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_request_id')->index();
            $table->string('event_type', 30);          // RETURN_REQUESTED|APPROVED|REJECTED|PICKED_UP|QC_STARTED|QC_PASSED|QC_FAILED|REFUNDED
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->string('triggered_by')->nullable();
            $table->json('payload')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('return_request_id')
                  ->references('id')
                  ->on('ondc_return_requests')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ondc_reverse_qc_events');
        Schema::dropIfExists('ondc_reverse_qc_results');
        Schema::dropIfExists('ondc_return_requests');
    }
};
