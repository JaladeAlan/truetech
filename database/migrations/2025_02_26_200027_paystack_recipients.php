<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('paystack_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('recipient_code')->unique();
            $table->string('account_number');
            $table->string('bank_code');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('paystack_recipients');
    }
};
