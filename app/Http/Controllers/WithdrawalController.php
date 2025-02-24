<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalConfirmed;
use App\Notifications\WithdrawalFailedNotification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class WithdrawalController extends Controller
{
    // Request Withdrawal
    public function requestWithdrawal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        if ($user->balance < $request->amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        if (!$user->account_number || !$user->bank_code) {
            return response()->json(['error' => 'Bank details are missing'], 400);
        }

        try {
            $referenceCode = 'WD-' . now()->format('Ymd-His') . '-' . Str::random(6);

            // Prevent duplicate withdrawal requests
            if (Withdrawal::where('reference', $referenceCode)->exists()) {
                return response()->json(['error' => 'Duplicate withdrawal request detected'], 400);
            }

            DB::transaction(function () use ($user, $request, $referenceCode) {
                $user->decrement('balance', $request->amount);

                Withdrawal::create([
                    'user_id' => $user->id,
                    'amount' => $request->amount,
                    'status' => 'pending',
                    'reference' => $referenceCode,
                ]);
            });

            Log::info('Withdrawal initiated', [
                'user_id' => $user->id,
                'account_number' => $user->account_number,
                'bank_code' => $user->bank_code,
            ]);

            // Check if test mode is enabled
            if (config('services.paystack.test_mode')) {
                return $this->simulateTestWithdrawal($referenceCode);
            } else {
                $this->initiatePaystackTransfer($user, $request->amount, $referenceCode);
            }

            return response()->json(['message' => 'Withdrawal request initiated', 'reference' => $referenceCode], 200);
        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'amount' => $request->amount,
            ]);
            return response()->json(['error' => 'Failed to initiate withdrawal'], 500);
        }
    }

    // Simulate Paystack transfer in test mode
    protected function simulateTestWithdrawal($referenceCode)
    {
        Log::info('Simulating test mode withdrawal', ['reference' => $referenceCode]);

        // Directly pass an array to handlePaystackCallback
        $this->handlePaystackCallback(new Request([
            'data' => [
                'reference' => $referenceCode,
                'status' => 'success',
            ]
        ]));

        return response()->json([
            'message' => 'Test mode withdrawal successful',
            'reference' => $referenceCode,
            'status' => 'success',
        ], 200);
    }

    // Initiate the transfer with Paystack
    protected function initiatePaystackTransfer($user, $amount, $referenceCode)
    {
        Log::info('Initiating transfer with Paystack', [
            'account_number' => $user->account_number,
            'bank_code' => $user->bank_code,
            'amount' => $amount,
            'reference' => $referenceCode,
        ]);

        // Check if recipient exists
        $recipientCode = $this->getOrCreatePaystackRecipient($user);

        Log::info('Paystack recipient code:', ['recipient_code' => $recipientCode]);

        // Initiate transfer
        $transferResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transfer', [
                'source' => 'balance',
                'amount' => $amount * 100, // Convert to kobo
                'recipient' => $recipientCode,
                'reference' => $referenceCode,
            ]);

        if (!$transferResponse->successful()) {
            Log::error('Transfer initiation failed', [
                'response' => $transferResponse->json(),
            ]);
            throw new \Exception('Transfer initiation failed: ' . $transferResponse->json()['message']);
        }

        Log::info('Paystack transfer successful', ['response' => $transferResponse->json()]);
    }

    // Get or Create Paystack Transfer Recipient
    protected function getOrCreatePaystackRecipient($user)
    {
        // Check for existing recipient
        $recipientResponse = Http::withToken(config('services.paystack.secret_key'))
            ->get("https://api.paystack.co/transferrecipient?account_number={$user->account_number}&bank_code={$user->bank_code}");

        if ($recipientResponse->successful() && !empty($recipientResponse->json()['data'])) {
            return $recipientResponse->json()['data'][0]['recipient_code'];
        }

        // Create a new recipient if not found
        return $this->createPaystackRecipient($user);
    }

    // Create Paystack Transfer Recipient
    protected function createPaystackRecipient($user)
    {
        $recipientResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type' => 'nuban',
                'name' => $user->name,
                'account_number' => $user->account_number,
                'bank_code' => $user->bank_code,
                'currency' => 'NGN',
            ]);

        if (!$recipientResponse->successful()) {
            Log::error('Failed to create transfer recipient', [
                'response' => $recipientResponse->json(),
            ]);
            throw new \Exception('Failed to create transfer recipient: ' . $recipientResponse->json()['message']);
        }

        return $recipientResponse->json()['data']['recipient_code'];
    }

    // Handle Paystack Webhook for Transfer Updates
    public function handlePaystackCallback(Request $request)
    {
        // Skip signature verification in test mode
        if (!config('services.paystack.test_mode')) {
            $payload = $request->getContent();
            $signature = $request->header('x-paystack-signature');

            if (!hash_equals(hash_hmac('sha512', $payload, config('services.paystack.webhook_secret')), $signature)) {
                Log::warning('Invalid Paystack webhook signature', [
                    'expected' => hash_hmac('sha512', $payload, config('services.paystack.webhook_secret')),
                    'received' => $signature,
                ]);
                abort(403, 'Unauthorized webhook');
            }
        }

        $reference = $request->input('data.reference');
        $status = $request->input('data.status');

        $withdrawal = Withdrawal::where('reference', $reference)->first();

        if (!$withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        DB::transaction(function () use ($withdrawal, $status) {
            if ($status === 'success') {
                $withdrawal->status = 'completed';
                $withdrawal->user->notify(new WithdrawalConfirmed($withdrawal));
            } elseif ($status === 'failed') {
                $withdrawal->user->increment('balance', $withdrawal->amount);
                $withdrawal->status = 'failed';
                $withdrawal->user->notify(new WithdrawalFailedNotification($withdrawal));
            }

            $withdrawal->save();
        });

        return response()->json(['status' => 'Callback handled']);
    }

    // Get Withdrawal Status
    public function getWithdrawalStatus($reference)
    {
        try {
            $withdrawal = Withdrawal::where('reference', $reference)->firstOrFail();

            return response()->json([
                'status' => $withdrawal->status,
                'amount' => $withdrawal->amount,
                'requested_at' => $withdrawal->created_at,
                'completed_at' => $withdrawal->updated_at,
            ], 200);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }
    }
}
