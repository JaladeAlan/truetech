<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transaction_type', // e.g., airtime, data, cable, bill-payment
        'provider',         // e.g., MTN, DSTV, PHCN
        'account_number',   // Phone number, SmartCard ID, or Customer ID
        'amount',
        'status',           // success, failed, pending
        'reference',
        'message',          // Stores webhook response messages
        'metadata',         // Additional response data
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
