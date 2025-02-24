<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Links to users table
            $table->string('transaction_type'); // e.g., airtime, data, TV, bills
            $table->string('provider')->nullable(); // e.g., MTN, DSTV, PHCN
            $table->string('account_number')->nullable(); // Phone number, decoder ID, meter number, etc.
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending, successful, failed
            $table->string('reference')->unique(); // Unique transaction reference
            $table->json('metadata')->nullable(); // Stores extra info (e.g., plan type, duration)
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
};
