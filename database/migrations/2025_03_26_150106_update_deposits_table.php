<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->change();
            $table->unsignedBigInteger('rejected_by')->nullable()->after('rejection_reason');
            $table->foreign('rejected_by')->references('id')->on('users')->onDelete('set null');
            $table->string('payment_proof')->nullable()->after('payment_method'); // Add payment_proof
        });
    }

    public function down()
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_by', 'payment_proof']);
        });
    }
};
