<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Transaction;

class WebhookController extends Controller
{
    /**
     * Handle Autopilot Webhook
     */
    public function handleWebhook(Request $request)
    {
        // Log the webhook payload for debugging
        Log::info('Autopilot Webhook Received:', $request->all());

        // Validate the required fields
        $data = $request->validate([
            'reference' => 'required|string',
            'status'    => 'required|string', // success, failed, pending
            'message'   => 'nullable|string',
        ]);

        // Find the transaction using the reference
        $transaction = Transaction::where('reference', $data['reference'])->first();

        if (!$transaction) {
            return response()->json(['error' => 'Transaction not found'], 404);
        }

        // Update transaction status
        $transaction->status = $data['status'];
        $transaction->message = $data['message'] ?? null;
        $transaction->save();

        return response()->json(['message' => 'Webhook processed successfully'], 200);
    }
}
