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
        if (!Schema::hasTable('ondc_api_logs')) {
            Schema::create('ondc_api_logs', function (Blueprint $table) {
                $table->id();
                $table->string('type')->nullable(); // incoming / outgoing
                $table->uuid('transaction_id')->nullable(); // txnId from ONDC payload, if available
                $table->string('endpoint')->nullable();
                $table->text('request');
                $table->text('response')->nullable();
                $table->integer('status_code')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ondc_api_logs');
    }
};
