<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposit;
use App\Models\PaystackRecipient;
use App\Notifications\DepositConfirmed;
use App\Notifications\DepositApprovedNotification;
use App\Notifications\DepositRejectedNotification;
use App\Notifications\AdminDepositNotification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tymon\JWTAuth\Exceptions\JWTException;

class DepositController extends Controller
{
    private function sendResponse($data = [], $message = 'Success', $status = 200)
    {
        return response()->json(['message' => $message, 'data' => $data], $status);
    }

    private function sendError($message = 'Error', $status = 400, $errors = [])
    {
        return response()->json(['message' => $message, 'errors' => $errors], $status);
    }

    private function authUser()
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (JWTException $e) {
            abort(401, 'Invalid or expired token');
        }
    }

    // ======== Deposit Creation ========
    private function createDeposit($user, $reference, $amount, $fee, $total, $status, $method, $proof = null, $txnRef = null)
    {
        return Deposit::create([
            'user_id' => $user->id,
            'reference' => $reference,
            'transaction_reference' => $txnRef,
            'amount' => $amount,
            'transaction_charge' => $fee,
            'total_amount' => $total,
            'status' => $status,
            'payment_method' => $method,
            'payment_proof' => $proof,
        ]);
    }

    private function finalizeDeposit($deposit)
    {
        $user = $deposit->user;
        if (!$user) return $this->sendError('User not found', 404);

        try {
            DB::transaction(function () use ($deposit, $user) {
                $user->increment('balance', $deposit->amount);
                $deposit->update(['status' => 'completed']);
                $user->notify(new DepositConfirmed($deposit->amount));
            });

            return $this->sendResponse(['amount' => $deposit->amount], 'Deposit successful');
        } catch (\Exception $e) {
            Log::error("Deposit finalize error: " . $e->getMessage());
            return $this->sendError('Failed to finalize deposit', 500);
        }
    }

    // ======== Deposit Initialization ========
    public function initiateDeposit(Request $request)
    {
        $user = $this->authUser();

        $request->validate([
            'amount' => 'required|numeric|min:100',
            'payment_method' => 'required|in:manual,paystack,monnify',
            'payment_proof' => 'required_if:payment_method,manual|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        $reference = 'DEPOSIT-' . uniqid();
        $amount = (float)$request->amount;
        $feePercent = env('TRANSACTION_CHARGE_PERCENT', 2.2) / 100;

        return match ($request->payment_method) {
            'manual' => $this->processManualDeposit($user, $request, $reference, $amount),
            'paystack' => $this->processPaystackDeposit($user, $reference, $amount, $feePercent),
            'monnify' => $this->processMonnifyDeposit($user, $reference, $amount, $feePercent),
        };
    }

    // ======== Manual Deposit ========
    private function processManualDeposit($user, $request, $reference, $amount)
    {
        $fee = 100;
        $total = $amount + $fee;
        $proofPath = $request->file('payment_proof')?->store('deposits');

        $deposit = $this->createDeposit($user, $reference, $amount, $fee, $total, 'completed', 'manual', $proofPath);

        Notification::send(User::where('is_admin', true)->get(), new AdminDepositNotification($deposit));

        return $this->sendResponse([
            'reference' => $reference,
            'amount' => $amount,
            'transaction_fee' => $fee,
            'total_amount' => $total,
            'instructions' => 'Wait for admin approval.'
        ]);
    }

    // ======== Paystack Deposit ========
    private function processPaystackDeposit($user, $reference, $amount, $feePercent)
    {
        $total = round($amount / (1 - $feePercent), 2);
        $fee = round($total - $amount, 2);
        $amountKobo = (int)($total * 100);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.paystack.secret_key')
        ])->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => $amountKobo,
            'reference' => $reference,
            'callback_url' => URL::temporarySignedRoute('deposit.callback', now()->addMinutes(10), ['reference' => $reference]),
        ]);

        if (!$response->successful()) {
            Log::error('Paystack init failed', ['response' => $response->json()]);
            return $this->sendError('Failed to initialize Paystack payment', 500);
        }

        $this->createDeposit($user, $reference, $amount, $fee, $total, 'pending', 'paystack');

        return $this->sendResponse([
            'checkout_url' => $response['data']['authorization_url'],
            'reference' => $reference,
            'status' => 'pending'
        ]);
    }

    // ======== Monnify Deposit ========
    private function processMonnifyDeposit($user, $reference, $amount, $feePercent)
    {
        $total = round($amount / (1 - $feePercent), 2);
        $fee = round($total - $amount, 2);

        try {
            $auth = Http::withBasicAuth(config('services.monnify.api_key'), config('services.monnify.secret_key'))
                ->post(config('services.monnify.base_url') . '/api/v1/auth/login');

            if (!$auth->ok() || !$auth['requestSuccessful']) {
                Log::error('Monnify auth failed', ['response' => $auth->json()]);
                return $this->sendError('Failed to authenticate with Monnify', 500);
            }

            $accessToken = $auth['responseBody']['accessToken'];
            $initUrl = config('services.monnify.base_url') . '/api/v1/merchant/transactions/init-transaction';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json'
            ])->post($initUrl, [
                'amount' => (int)$total,
                'customerName' => $user->name,
                'customerEmail' => $user->email,
                'paymentReference' => $reference,
                'paymentDescription' => 'Account Deposit',
                'currencyCode' => 'NGN',
                'contractCode' => config('services.monnify.contract_code'),
                'redirectUrl' => url('/api/payment/success'),
                'transactionNotificationUrl' => url('/api/monnify/deposit/callback'),
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER']
            ]);

            $body = $response->json();

            if (!$response->successful() || empty($body['responseBody']['transactionReference'])) {
                Log::error('Monnify init failed', ['response' => $body]);
                return $this->sendError('Failed to initialize Monnify payment', 500);
            }

            $txnRef = $body['responseBody']['transactionReference'];
            $checkoutUrl = $body['responseBody']['checkoutUrl'] ?? null;

            $this->createDeposit($user, $reference, $amount, $fee, $total, 'pending', 'monnify', null, $txnRef);

            return $this->sendResponse(['checkout_url' => $checkoutUrl, 'reference' => $reference, 'status' => 'pending']);

        } catch (\Exception $e) {
            Log::error('Monnify deposit error', ['exception' => $e->getMessage()]);
            return $this->sendError('An error occurred while processing Monnify deposit', 500);
        }
    }

    // ======== Deposit Callback ========
    public function handleDepositCallback(Request $request)
    {
        $reference = $request->query('reference') ?? $request->input('reference');
        if (!$reference) return $this->sendError('Reference not provided', 400);

        $deposit = Deposit::where('reference', $reference)->first();
        if (!$deposit) return $this->sendError('Deposit not found', 404);

        return match ($deposit->payment_method) {
            'paystack' => $this->verifyPaystackDeposit($deposit),
            'monnify' => $this->verifyMonnifyDeposit($deposit),
            default => $this->sendError('Unsupported payment method', 400)
        };
    }

    private function verifyPaystackDeposit($deposit)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . config('services.paystack.secret_key')
        ])->get('https://api.paystack.co/transaction/verify/' . $deposit->reference);

        $body = $response->json();

        if (!$response->successful() || $body['data']['status'] !== 'success') {
            $deposit->update(['status' => 'failed']);
            return $this->sendError('Paystack verification failed', 400);
        }

        return $this->finalizeDeposit($deposit);
    }

    private function verifyMonnifyDeposit($deposit)
    {
        try {
            $auth = Http::withBasicAuth(config('services.monnify.api_key'), config('services.monnify.secret_key'))
                ->post(config('services.monnify.base_url') . '/api/v1/auth/login');

            if (!$auth->ok() || !$auth['requestSuccessful']) {
                return $this->sendError('Monnify authentication failed', 500);
            }

            $accessToken = $auth['responseBody']['accessToken'];
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $accessToken])
                ->get(config('services.monnify.base_url') . "/api/v2/transactions/{$deposit->transaction_reference}");

            $body = $response->json();
            $status = $body['responseBody']['paymentStatus'] ?? null;

            return match ($status) {
                'PAID' => $this->finalizeDeposit($deposit),
                'PENDING' => response()->json(['message' => 'Payment is pending'], 202),
                'FAILED', 'REVERSED' => $deposit->update(['status' => 'failed']) ? $this->sendError('Payment failed or reversed') : $this->sendError('Payment failed'),
                default => $this->sendError('Transaction verification failed')
            };
        } catch (\Exception $e) {
            Log::error('Monnify verification error', ['exception' => $e->getMessage()]);
            return $this->sendError('Error verifying Monnify transaction', 500);
        }
    }

    // ======== Manual Approval/Rejection ========
    public function approveManualDeposit(Request $request)
    {
        $request->validate(['reference' => 'required|string|exists:deposits,reference']);
        $deposit = Deposit::where('reference', $request->reference)->where('payment_method', 'manual')->firstOrFail();

        if ($deposit->status !== 'completed') return $this->sendError('Deposit is not awaiting approval', 400);

        DB::transaction(function () use ($deposit) {
            $deposit->user->increment('balance', $deposit->amount);
            $deposit->update([
                'status' => 'approved',
                'approval_date' => now(),
                'approved_by' => auth()->id(),
            ]);
            $deposit->user->notify(new DepositApprovedNotification($deposit->amount));
        });

        return $this->sendResponse([], 'Deposit approved successfully');
    }

    public function rejectManualDeposit(Request $request)
    {
        $request->validate([
            'reference' => 'required|string|exists:deposits,reference',
            'reason' => 'required|string|min:5'
        ]);

        $deposit = Deposit::where('reference', $request->reference)->where('payment_method', 'manual')->firstOrFail();
        if ($deposit->status !== 'completed') return $this->sendError('Deposit is not awaiting approval', 400);

        DB::transaction(function () use ($deposit, $request) {
            $deposit->update([
                'status' => 'rejected',
                'rejection_reason' => $request->reason,
                'rejected_by' => auth()->id(),
            ]);
            $deposit->user->notify(new DepositRejectedNotification($deposit->amount, $deposit->rejection_reason));
        });

        return $this->sendResponse([], 'Deposit rejected successfully');
    }
}
