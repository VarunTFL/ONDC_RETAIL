<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('ondc_api_logs', 'transaction_id')) {
			Schema::table('ondc_api_logs', function (Blueprint $table) {
				$table->char('transaction_id', 36)->nullable()->after('type');
			});
		}
    }

    public function down(): void
    {
        Schema::table('ondc_api_logs', function (Blueprint $table) {
            $table->dropIndex(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};