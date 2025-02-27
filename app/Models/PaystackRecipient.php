<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaystackRecipient extends Model
{
    use HasFactory;

    protected $fillable = ['recipient_code', 'account_number', 'bank_code'];
}
