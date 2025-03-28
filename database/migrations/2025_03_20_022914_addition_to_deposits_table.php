<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->enum('payment_method', ['monnify', 'paystack', 'manual'])->default('monnify')->after('reference');
            $table->unsignedBigInteger('approved_by')->nullable()->after('status');
            $table->timestamp('approval_date')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approval_date');

            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);   
            $table->dropColumn([
                'payment_method',
                'approved_by', 'approval_date', 'rejection_reason'
            ]);
        });
    }
};
