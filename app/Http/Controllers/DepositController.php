<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposit;
use App\Models\PaystackRecipient; 
use App\Notifications\DepositConfirmed;
use App\Notifications\DepositFailedNotification;
use App\Notifications\DepositApprovedNotification;
use App\Notifications\DepositRejectedNotification;
use App\Notifications\AdminDepositNotification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\URL;

class DepositController extends Controller
{
    private function getMonnifyToken()
    {
        $apiKey = env('MONNIFY_API_KEY');
        $secretKey = env('MONNIFY_SECRET_KEY');
        $base64Auth = base64_encode("$apiKey:$secretKey");

        $response = Http::withHeaders([
            'Authorization' => "Basic $base64Auth",
        ])->post(env('MONNIFY_BASE_URL') . '/api/v1/auth/login');

        if ($response->successful()) {
            return $response->json()['responseBody']['accessToken'];
        }

        throw new \Exception('Failed to retrieve Monnify token');
    }

    public function getManualFundingDetails(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);

        $userInputAmount = (float) $request->amount;
        $transactionFee = 100;
        $totalAmount = $userInputAmount + $transactionFee;

        return response()->json([
            'bank_name' => env('MANUAL_FUNDING_BANK_NAME', 'GTBank'),
            'account_number' => env('MANUAL_FUNDING_ACCOUNT_NUMBER', '1234567890'),
            'account_name' => env('MANUAL_FUNDING_ACCOUNT_NAME', 'Your Company Name'),
            'instructions' => "Transfer exactly NGN $totalAmount (NGN $userInputAmount + NGN $transactionFee transaction fee) and upload proof of payment for approval.",
        ]);
    }


    // Initialize deposit
    public function initiateDeposit(Request $request)
    {
        try {
            if (!$token = JWTAuth::getToken()) {
                return response()->json(['error' => 'Token not provided'], 401);
            }
            $user = JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException $e) {
            return response()->json(['error' => 'Token has expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['error' => 'Token is invalid'], 400);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Token is absent'], 401);
        }

        try {
            $request->validate([
                'amount' => 'required|numeric|min:100',
                'payment_method' => 'required|in:monnify,manual,paystack',
                'payment_proof' => 'required_if:payment_method,manual|file|mimes:jpg,jpeg,png,pdf|max:2048',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }      
        $userInputAmount = (float) $request->amount;
        $transactionChargePercent = env('TRANSACTION_CHARGE_PERCENT', 2.2) / 100;
        $reference = 'DEPOSIT-' . uniqid();

        if ($request->payment_method === 'manual') {
            $transactionFee = 100;
            $totalAmount = $userInputAmount + $transactionFee;
            return $this->processManualDeposit($user, $request, $reference, $userInputAmount, $transactionFee, $totalAmount);
        }

        $totalAmount = round($userInputAmount / (1 - $transactionChargePercent), 2);
        $transactionFee = round($totalAmount - $userInputAmount, 2);
     
        if ($request->payment_method === 'paystack') {
            return $this->processPaystackDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount);
        }

        if ($request->payment_method === 'monnify') {
            return $this->processMonnifyDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount);
        }

        return response()->json(['error' => 'Invalid payment method'], 400);
    }

    private function createDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount, $status, $paymentMethod, $proofPath = null)
    {
        Log::info("createDeposit Parameters", [
            'user_id' => $user->id,
            'reference' => $reference,
            'amount' => $userInputAmount,
            'transaction_fee' => $transactionFee,
            'total_amount' => $totalAmount,
            'status' => $status,
            'payment_method' => $paymentMethod
        ]);

        return Deposit::create([
            'user_id' => $user->id,
            'reference' => $reference,
            'amount' => $userInputAmount,
            'transaction_charge' => $transactionFee,
            'total_amount' => $totalAmount,
            'status' => $status,
            'payment_method' => $paymentMethod,
            'payment_proof' => $proofPath,
        ]);
    }

    private function processManualDeposit($user, $request, $reference, $userInputAmount, $transactionFee, $totalAmount)
    {
        $proofPath = $request->file('payment_proof')?->store('deposits');

        $deposit = $this->createDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount, 'completed', 'manual', $proofPath);

        $admin = User::where('is_admin', true)->get();
        if ($admin->isEmpty()) {
            return response()->json(['error' => 'No admin available to notify'], 400);
        }
        Notification::send($admin, new AdminDepositNotification($deposit));

        return response()->json([
            'reference' => $reference,
            'amount' => $userInputAmount,
            'transaction_charge' => $transactionFee,
            'total_amount' => $totalAmount,
            'bank_name' => env('MANUAL_FUNDING_BANK_NAME', 'GTBank'),
            'account_number' => env('MANUAL_FUNDING_ACCOUNT_NUMBER', '1234567890'),
            'account_name' => env('MANUAL_FUNDING_ACCOUNT_NAME', 'Your Company Name'),
            'instructions' => 'Kindly wait for approval of account funding by admin. Contact support if it exceeds 2 hours.',
        ]);
    }

    private function processPaystackDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount)
    {
        // Convert amount to kobo
        $amountInKobo = (int)($totalAmount * 100);

        // Call Paystack API
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
        ])->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => $amountInKobo,
            'reference' => $reference,
            'callback_url' => URL::temporarySignedRoute('deposit.callback', now()->addMinutes(10), ['reference' => $reference]),
        ]);

        if ($response->successful()) {
            $deposit = $this->createDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount, 'pending', 'paystack');

            Log::info("Paystack Deposit Initiated", [
                'reference' => $reference,
                'payment_method' => $deposit->payment_method
            ]);

            return response()->json([
                'checkout_url' => $response->json()['data']['authorization_url'],
                'reference' => $reference,
                'status' => 'pending'
            ]);
        }

        Log::error("Paystack Initialization Failed", ['response' => $response->json()]);
        return response()->json(['error' => 'Failed to initialize payment with Paystack'], 500);
    }

    private function processMonnifyDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount)
    {
        // Monnify Authentication
        $authResponse = Http::withBasicAuth(env('MONNIFY_API_KEY'), env('MONNIFY_SECRET_KEY'))
            ->post(env('MONNIFY_API_URL', 'https://sandbox.monnify.com/api/v1/auth/login'))
            ->json();
    
        if (!is_array($authResponse) || !isset($authResponse['requestSuccessful']) || !$authResponse['requestSuccessful']) {
            Log::error("Monnify Authentication Failed", ['response' => $authResponse]);
            return response()->json(['error' => 'Failed to authenticates with Monnify'], 500);
        }
    
        $accessToken = $authResponse['responseBody']['accessToken'];
        Log::info("Monnify Access Token", ['accessToken' => $accessToken]);

        // Payment Initialization
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ])->post(env('MONNIFY_API_URL', 'https://sandbox.monnify.com/api/v1/merchant/transactions/init-transaction'), [
            'amount' => (int)$totalAmount,
            'customerName' => $user->name,
            'customerEmail' => $user->email,
            'paymentReference' => $reference,
            'paymentDescription' => 'Account Deposit',
            'currencyCode' => 'NGN',
            'redirectUrl' => 'https://ce36-2c0f-2a80-a78-1f10-4552-6b2f-a831-9bbf.ngrok-free.app/api/deposit/callback?reference=' . $reference,
            'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER'],
        ]);
        
        Log::info("Monnify Deposit Initiated", [
            'amount' => $totalAmount,
            'customerName' => $user->name,
            'customerEmail' => $user->email,
            'paymentReference' => $reference,
            'paymentDescription' => 'Account Deposit',
            'currencyCode' => 'NGN',
            'redirectUrl' => 'https://ce36-2c0f-2a80-a78-1f10-4552-6b2f-a831-9bbf.ngrok-free.app/api/deposit/callback?reference=' . $reference,]);

        // Log Payment Initialization Response
        Log::info("Monnify Payment Initialization Response", ['response' => $response->json()]);
    
        if ($response->successful() && $response->json()['requestSuccessful']) {
            $deposit = $this->createDeposit($user, $reference, $userInputAmount, $transactionFee, $totalAmount, 'pending', 'monnify');
    
            return response()->json([
                'checkout_url' => $response->json()['responseBody']['checkoutUrl'],
                'reference' => $reference,
                'status' => 'pending'
            ]);
        }
    
        Log::error("Monnify Payment Initialization Failed", ['response' => $response->json()]);
        return response()->json(['error' => 'Failed to initialize payment with Monnify'], 500);
    }    
    
    // Handle deposit callback
    public function handleDepositCallback(Request $request)
    {
        Log::info("Deposit callback accessed", [
            'method' => $request->method(),
            'request_data' => $request->all()
        ]);
    
        // Retrieve the reference from query parameters or request payload
        $reference = $request->query('reference') ?? $request->input('reference');
    
        if (!$reference) {
            return response()->json(['error' => 'Reference not provided'], 400);
        }
    
        $deposit = Deposit::where('reference', $reference)->first();
        if (!$deposit) {
            return response()->json(['error' => 'Deposit record not found'], 404);
        }
    
        $paymentMethod = $deposit->payment_method;
    
        if ($paymentMethod === 'monnify') {
            return $this->handleMonnifyDeposit($reference, $deposit);
        } elseif ($paymentMethod === 'paystack') {
            return $this->handlePaystackDeposit($reference, $deposit);
        }    
        return response()->json(['error' => 'Unsupported payment method'], 400);
    }
    
    
    private function handleMonnifyDeposit($reference, $deposit)
    {
        $monnifyUrl = 'https://api.monnify.com/api/v1/transactions/' . $reference;
    
        $authResponse = Http::withBasicAuth(env('MONNIFY_API_KEY'), env('MONNIFY_SECRET_KEY'))
            ->post('https://api.monnify.com/api/v1/auth/login')
            ->json();
    
        if (!$authResponse || !$authResponse['requestSuccessful']) {
            Log::error("Monnify authentication failed", ['response' => $authResponse]);
            return response()->json(['error' => 'Failed to authenticate with Monnify'], 500);
        }
    
        $accessToken = $authResponse['responseBody']['accessToken'];
        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $accessToken])
            ->get($monnifyUrl);
    
        if ($response->successful() && $response['responseBody']['paymentStatus'] === 'PAID') {
            return $this->finalizeDeposit($deposit);
        }
    
        $deposit->update(['status' => 'failed']);
        return response()->json(['error' => 'Deposit verification failed'], 400);
    }
    
    private function handlePaystackDeposit($reference, $deposit)
    {
        $paystackSecretKey = env('PAYSTACK_SECRET_KEY');
        
        if (!$paystackSecretKey) {
            Log::error("Paystack Secret Key not set");
            return response()->json(['error' => 'Payment verification unavailable'], 500);
        }
    
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $paystackSecretKey,
        ])->get('https://api.paystack.co/transaction/verify/' . $reference);
    
        if ($response->failed()) {
            Log::error("Paystack verification failed", ['response' => $response->json()]);
            $deposit->update(['status' => 'failed']);
            return response()->json(['error' => 'Verification request failed'], 500);
        }
    
        $responseData = $response->json();
    
        // Validate response structure
        if (!isset($responseData['data']) || $responseData['data']['status'] !== 'success') {
            Log::error("Paystack deposit verification failed", ['response' => $responseData]);
            $deposit->update(['status' => 'failed']);
            return response()->json(['error' => 'Deposit verification failed'], 400);
        }
    
        return $this->finalizeDeposit($deposit);
    }  
    
    private function finalizeDeposit($deposit)
    {
        $user = $deposit->user;
        if (!$user) return response()->json(['error' => 'User not found'], 404);
    
        try {
            DB::transaction(function () use ($user, $deposit) {
                $user->increment('balance', (float)$deposit->amount);
                $deposit->update(['status' => 'completed']);
                $user->notify(new DepositConfirmed($deposit->amount));
            });
            return response()->json(['message' => 'Deposit successful', 'amount' => $deposit->amount]);
        } catch (\Exception $e) {
            Log::error("Database error on deposit", ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to update deposit'], 500);
        }
    }   
    
    // // Transfer deposit to third-party account
    // private function transferToThirdParty($amount)
    // {
    //     $recipientCode = $this->getOrCreateRecipient();
    //     if (!$recipientCode) {
    //         Log::error("Recipient code not found, transfer cannot be completed");
    //         return;
    //     }

    //     $response = Http::withHeaders([
    //         'Authorization' => "Bearer " . env('PAYSTACK_SECRET_KEY'),
    //         'Content-Type' => 'application/json',
    //     ])->post('https://api.paystack.co/transfer', [
    //         'source' => 'balance',
    //         'amount' => $amount * 100,
    //         'recipient' => $recipientCode,
    //         'reason' => 'User Deposit Transfer',
    //     ])->json();

    //     if (!$response['status']) {
    //         Log::error("Failed to transfer funds", ['response' => $response]);
    //     } else {
    //         Log::info("Transfer successful", ['amount' => $amount, 'recipient_code' => $recipientCode]);
    //     }
    // }

    // Get or create transfer recipient
    private function getOrCreateRecipient()
    {
        $recipient = PaystackRecipient::first();
        if ($recipient) return $recipient->recipient_code;

        $response = Http::withHeaders([
            'Authorization' => "Bearer " . env('PAYSTACK_SECRET_KEY'),
        ])->post('https://api.paystack.co/transferrecipient', [
            'type' => 'nuban',
            'name' => "Ayodeji Alalade",
            'account_number' => "9001678114",
            'bank_code' => "058",
            'currency' => 'NGN',
        ])->json();

        if ($response['status']) {
            $recipientCode = $response['data']['recipient_code'];
            PaystackRecipient::create([
                'recipient_code' => $recipientCode,
                'account_number' => "9001678114",
                'bank_code' => "058",
            ]);
            return $recipientCode;
        }

        return null;    
    }

    public function getPendingManualDeposits()
    {
        $pendingDeposits = Deposit::where('status', 'completed')
            ->where('payment_method', 'manual')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $pendingDeposits
        ]);
    }

    public function approveManualDeposit(Request $request)
    {
        $request->validate([
            'reference' => 'required|string|exists:deposits,reference',
        ]);

        $deposit = Deposit::where('reference', $request->reference)
            ->where('payment_method', 'manual')
            ->first();

        if (!$deposit) {
            return response()->json(['error' => 'Deposit not found or not a manual funding request'], 404);
        }

        if ($deposit->status !== 'completed') {
            return response()->json(['error' => 'Deposit is not awaiting approval'], 400);
        }

        DB::transaction(function () use ($deposit) {
            $user = User::find($deposit->user_id);

            if (!$user) {
                throw new \Exception('User not found');
            }

            // Credit the user
            $user->increment('balance', $deposit->amount);

            // Mark the deposit as approved
            $deposit->update([
                'status' => 'approved',
                'approval_date' => now(),
                'approved_by' => auth()->id(),
            ]);

            $user->notify(new DepositApprovedNotification($deposit->amount));
        });

        return response()->json(['message' => 'Deposit approved successfully']);
    }
    public function rejectManualDeposit(Request $request)
    {
        $request->validate([
            'reference' => 'required|string|exists:deposits,reference',
            'reason' => 'required|string|min:5',
        ]);

        $deposit = Deposit::where('reference', $request->reference)
            ->where('payment_method', 'manual')
            ->first();

        if (!$deposit) {
            return response()->json(['error' => 'Deposit not found or not a manual funding request'], 404);
        }

        if ($deposit->status !== 'completed') {
            return response()->json(['error' => 'Deposit is not awaiting approval'], 400);
        }

        DB::transaction(function () use ($deposit, $request) {
            $user = $deposit->user;

            $deposit->update([
                'status' => 'rejected',
                'rejection_reason' => $request->reason,
                'rejected_by' => auth()->id(),
            ]);

            $user->notify(new DepositRejectedNotification($deposit->amount, $deposit->rejection_reason));
        });

        return response()->json(['message' => 'Deposit rejected successfully']);
    }


}
