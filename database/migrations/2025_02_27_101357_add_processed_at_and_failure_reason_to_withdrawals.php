<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending')->change();
            $table->timestamp('processed_at')->nullable()->after('status');
            $table->text('failure_reason')->nullable()->after('processed_at');
        });
    }

    public function down()
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('status')->default('pending')->change(); // Revert to string
            $table->dropColumn('processed_at');
            $table->dropColumn('failure_reason');
        });
    }
};
