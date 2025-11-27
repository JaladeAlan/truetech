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
            // Uncomment if you want to require transaction PIN
            // 'transaction_pin' => 'required|digits:4',
        ]);

        // Optional transaction PIN verification
        // if (!$user->verifyTransactionPin($request->transaction_pin)) {
        //     return response()->json(['error' => 'Invalid transaction PIN'], 400);
        // }

        if ($user->balance < $request->amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        if (!$user->account_number || !$user->bank_code) {
            return response()->json(['error' => 'Bank details are missing'], 400);
        }

        try {
            $referenceCode = $this->generateReference();

            Log::info('User balance before withdrawal', [
                'user_id' => $user->id,
                'balance' => $user->balance,
                'withdrawal_amount' => $request->amount,
            ]);

            DB::transaction(function () use ($user, $request, $referenceCode) {
                $user->decrement('balance', $request->amount);

                Withdrawal::create([
                    'user_id' => $user->id,
                    'amount' => $request->amount,
                    'status' => 'pending',
                    'reference' => $referenceCode,
                ]);
            });

            Log::info('User balance after withdrawal', [
                'user_id' => $user->id,
                'balance' => $user->fresh()->balance,
            ]);

            if (config('services.paystack.test_mode')) {
                return $this->simulateTestWithdrawal($referenceCode);
            }

            $this->initiatePaystackTransfer($user, $request->amount, $referenceCode);

            return response()->json([
                'message' => 'Withdrawal request initiated',
                'reference' => $referenceCode
            ], 200);

        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'amount' => $request->amount,
            ]);
            return response()->json(['error' => 'Failed to initiate withdrawal'], 500);
        }
    }

    // Generate unique withdrawal reference
    protected function generateReference()
    {
        $dateTime = now()->format('Ymd-His');
        $randomString = Str::upper(Str::random(6));
        return 'WD-' . $dateTime . '-' . $randomString;
    }

    // Test mode withdrawal simulation
    protected function simulateTestWithdrawal($referenceCode)
    {
        Log::info('Simulating test mode withdrawal', ['reference' => $referenceCode]);

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

    // Initiate Paystack transfer
    protected function initiatePaystackTransfer($user, $amount, $referenceCode)
    {
        Log::info('Initiating transfer with Paystack', [
            'account_number' => $user->account_number,
            'bank_code' => $user->bank_code,
            'amount' => $amount,
            'reference' => $referenceCode,
        ]);

        $recipientCode = $this->getOrCreatePaystackRecipient($user);

        $transferResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transfer', [
                'source' => 'balance',
                'amount' => $amount * 100,
                'recipient' => $recipientCode,
                'reference' => $referenceCode,
            ]);

        if (!$transferResponse->successful()) {
            throw new \Exception('Transfer initiation failed: ' . $transferResponse->json()['message']);
        }
    }

    // Get existing recipient code or create one
    protected function getOrCreatePaystackRecipient($user)
    {
        return $user->recipient_code ?? $this->createPaystackRecipient($user);
    }

    // Create a new Paystack recipient
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
            throw new \Exception('Failed to create transfer recipient: ' . $recipientResponse->json()['message']);
        }

        $recipientCode = $recipientResponse->json()['data']['recipient_code'];
        $user->update(['recipient_code' => $recipientCode]);

        return $recipientCode;
    }

    // Handle Paystack webhook callback
    public function handlePaystackCallback(Request $request)
    {
        if (!config('services.paystack.test_mode')) {
            $payload = file_get_contents('php://input'); 
            $signature = $request->header('x-paystack-signature');

            if (!hash_equals(hash_hmac('sha512', $payload, config('services.paystack.webhook_secret')), $signature)) {
                Log::warning('Invalid Paystack webhook signature');
                abort(403, 'Unauthorized webhook');
            }
        }

        $data = $request->input('data', []);
        $reference = $data['reference'] ?? null;
        $status = $data['status'] ?? null;

        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (!$withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        DB::transaction(function () use ($withdrawal, $status) {
            if ($withdrawal->status === 'completed') return;

            if ($status === 'success') {
                $withdrawal->update(['status' => 'completed']);
                $this->sendWithdrawalNotification($withdrawal, true);
            } elseif ($status === 'failed') {
                $withdrawal->user->increment('balance', $withdrawal->amount);
                $withdrawal->update(['status' => 'failed']);
                $this->sendWithdrawalNotification($withdrawal, false);
            } else {
                Log::warning('Unexpected Paystack withdrawal status', ['status' => $status]);
            }
        });

        return response()->json(['status' => 'Callback handled']);
    }

    // Send withdrawal notifications
    protected function sendWithdrawalNotification($withdrawal, $success = true)
    {
        try {
            $notification = $success
                ? new WithdrawalConfirmed($withdrawal)
                : new WithdrawalFailedNotification($withdrawal);

            $withdrawal->user->notify($notification);

            Log::info('Withdrawal notification sent', [
                'user_id' => $withdrawal->user->id,
                'amount' => $withdrawal->amount,
                'reference' => $withdrawal->reference,
                'success' => $success
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send withdrawal notification', [
                'error' => $e->getMessage(),
                'user_id' => $withdrawal->user->id,
                'reference' => $withdrawal->reference
            ]);
        }
    }

    // Get withdrawal status
    public function getWithdrawalStatus($reference)
    {
        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (!$withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        return response()->json([
            'status' => $withdrawal->status,
            'amount' => $withdrawal->amount,
            'requested_at' => $withdrawal->created_at,
            'completed_at' => $withdrawal->updated_at,
        ], 200);
    }

    // Retry pending withdrawals
    public function retryPendingWithdrawals()
    {
        $pendingWithdrawals = Withdrawal::where('status', 'pending')->get();

        if ($pendingWithdrawals->isEmpty()) {
            return response()->json(['message' => 'No pending withdrawals to retry'], 200);
        }

        foreach ($pendingWithdrawals as $withdrawal) {
            if (!$withdrawal->reference) continue;
            $existingWithdrawal = Withdrawal::where('reference', $withdrawal->reference)->first();
            if (!$existingWithdrawal) continue;

            try {
                $this->initiatePaystackTransfer($withdrawal->user, $withdrawal->amount, $withdrawal->reference);
            } catch (\Exception $e) {
                Log::error('Failed to retry withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference' => $withdrawal->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json(['message' => 'Pending withdrawals retried'], 200);
    }
}
