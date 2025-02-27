<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->decimal('transaction_charge', 10, 2)->default(50)->after('amount');
            $table->decimal('total_amount', 10, 2)->after('transaction_charge');
        });
    }

    public function down()
    {
        Schema::table('deposits', function (Blueprint $table) {
            $table->dropColumn('transaction_charge');
            $table->dropColumn('total_amount');
        });
    }
};
