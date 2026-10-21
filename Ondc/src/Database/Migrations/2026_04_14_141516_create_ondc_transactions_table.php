<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ondc_transactions')) {
            Schema::create('ondc_transactions', function (Blueprint $table) {
                $table->id();
                $table->uuid('transaction_id'); // uuid -> index is enough; no extra `index()` needed below
                $table->string('action')->nullable();
                $table->string('domain')->default('ONDC:RET14');
                $table->string('bap_id')->nullable();
                $table->string('bap_uri')->nullable();
                $table->string('bpp_id')->nullable();
                $table->string('status')->default('pending');
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->integer('response_status_code')->nullable();
                $table->string('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('ack_received_at')->nullable();
                $table->timestamp('callback_received_at')->nullable();
                $table->timestamps();

                // indexes: remove duplicate on transaction_id
                $table->index('action');        // OK
                $table->index('status');        // OK
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ondc_transactions');
    }
};