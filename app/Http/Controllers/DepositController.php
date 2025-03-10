<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposit;
use App\Models\PaystackRecipient; 
use App\Notifications\DepositConfirmed;
use App\Notifications\DepositFailedNotification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\URL;

class DepositController extends Controller
{
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

        $request->validate([
            'amount' => 'required|numeric|min:100',
        ]);
    
        $userInputAmount = (float) $request->amount;
        $transactionChargePercent = 2.2 / 100; // 2.2% transaction fee
    
        // Calculate the total amount user needs to pay
        $totalAmount = round($userInputAmount / (1 - $transactionChargePercent), 2);
        $transactionFee = round($totalAmount - $userInputAmount, 2);
        
        $reference = 'DEPOSIT-' . uniqid();
    
        $monnifyUrl = 'https://api.monnify.com/api/v1/transactions/init-transaction';
        
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->getMonnifyToken(),
            'Content-Type' => 'application/json',
        ])->post($monnifyUrl, [
            'amount' => $totalAmount,
            'customerEmail' => $user->email,
            'paymentReference' => $reference,
            'paymentDescription' => "Deposit for {$user->name}",
            'currencyCode' => 'NGN',
            'contractCode' => env('MONNIFY_CONTRACT_CODE'),
            'redirectUrl' => route('deposit.callback', ['reference' => $reference]),
            'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER'],
            'incomeSplitConfig' => [
                [
                    'subAccountCode' => env('MONNIFY_SUBACCOUNT_CODE'),
                    'feeBearer' => false,
                    'splitAmount' => $userInputAmount,
                ],
                [
                    'subAccountCode' => env('MONNIFY_PLATFORM_ACCOUNT'),
                    'feeBearer' => true,
                    'splitAmount' => $transactionFee,
                ]
            ]
        ]);

        Log::info('Monnify Response', ['response' => $response->json()]);
    
        if ($response->successful()) {
            Log::info("Deposit initiated successfully", [
                'reference' => $reference,
                'user_id' => $user->id,
                'amount' => $userInputAmount,
                'transaction_charge' => $transactionFee,
                'total_amount_paid' => $totalAmount
            ]); 
    
            $data = $response->json()['responseBody'];
            
            Deposit::create([
                'user_id' => $user->id,
                'reference' => $reference,
                'amount' => $userInputAmount,
                'transaction_charge' => $transactionFee,
                'total_amount' => $totalAmount,
                'status' => 'pending'
            ]);
    
            return response()->json([
                'payment_url' => $data['checkoutUrl'],
                'reference' => $reference,
                'amount' => $userInputAmount,
                'transaction_charge' => $transactionFee,
                'total_amount' => $totalAmount,
            ]);
            Log::info('Deposit Created', [
                'user_id' => $user->id, 'reference' => $reference, 'amount' => $userInputAmount
            ]);
        } else {
            Log::error('Failed to initiate deposit', ['response' => $response->json(), 'user_id' => $user->id]);
            return response()->json(['error' => 'Failed to initiate deposit'], 500);
        }
    }
    
    // Handle deposit callback
    public function handleDepositCallback(Request $request)
    {
        Log::info("Deposit callback accessed", ['method' => $request->method()]);
    
        $reference = $request->query('paymentReference');
        $monnifyUrl = 'https://api.monnify.com/api/v1/transactions/' . $reference;
    
        // Authenticate with Monnify to get bearer token
        $authResponse = Http::withBasicAuth(env('MONNIFY_API_KEY'), env('MONNIFY_SECRET_KEY'))
            ->post('https://api.monnify.com/api/v1/auth/login')
            ->json();
    
        if (!$authResponse || !$authResponse['requestSuccessful']) {
            Log::error("Monnify authentication failed", ['response' => $authResponse]);
            return response()->json(['error' => 'Failed to authenticate with Monnify'], 500);
        }
    
        $accessToken = $authResponse['responseBody']['accessToken'];
    
        // Verify transaction status
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get($monnifyUrl);
    
        Log::info("Monnify response for deposit verification", ['response' => $response->json(), 'reference' => $reference]);
    
        if ($response->successful() && $response['responseBody']['paymentStatus'] === 'PAID') {
            $amount = $response['responseBody']['amountPaid'];
            $userEmail = $response['responseBody']['customer']['email'];
    
            $user = User::where('email', $userEmail)->first();
            if (!$user) return response()->json(['error' => 'User not found'], 404);
    
            $deposit = Deposit::where('reference', $reference)->first();
            if (!$deposit) return response()->json(['error' => 'Deposit record not found'], 404);
    
            try {
                DB::transaction(function () use ($user, $amount, $deposit) {
                    Log::info("Before incrementing balance", [
                        'user_id' => $user->id,
                        'current_balance' => $user->balance,
                        'deposit_amount' => $deposit->amount
                    ]);
    
                    $user->increment('balance', (float) $deposit->amount);
                    Log::info("After incrementing balance", [
                        'user_id' => $user->id,
                        'new_balance' => $user->fresh()->balance
                    ]);
    
                    $deposit->update(['status' => 'completed']);
    
                    try {
                        Log::info("Sending notification to user", ['user_id' => $user->id, 'amount' => $amount]);
    
                        $notification = new DepositConfirmed($amount);
                        $user->notify($notification);
    
                        Log::info("Notification sent", ['notification_data' => $notification->toDatabase($user)]);
                    } catch (\Exception $e) {
                        Log::error("Failed to send deposit notification", [
                            'error' => $e->getMessage(),
                            'user_id' => $user->id,
                            'amount' => $amount,
                            'deposit_reference' => $deposit->reference,
                        ]);
                    }
    
                    Log::info("Deposit successful", ['user_id' => $user->id, 'amount' => $amount]);
                });
    
                return response()->json(['message' => 'Deposit successful', 'amount' => $deposit->amount]);
            } catch (\Exception $e) {
                Log::error("Database error on deposit", ['error' => $e->getMessage(), 'user_id' => $user->id]);
                return response()->json(['error' => 'Failed to update deposit'], 500);
            }
        } else {
            Log::error("Deposit verification failed", ['reference' => $reference, 'response' => $response->json()]);
    
            $deposit = Deposit::where('reference', $reference)->first();
            if ($deposit) {
                $deposit->update(['status' => 'failed']);
            }
    
            if ($deposit && $deposit->user_id) {
                $user = User::find($deposit->user_id);
                if ($user) {
                    try {
                        Log::info("Sending deposit failed notification", ['user_id' => $user->id, 'deposit_reference' => $deposit->reference]);
    
                        $failedNotification = new DepositFailedNotification($deposit);
                        $user->notify($failedNotification);
    
                        Log::info("Deposit failed notification sent", ['notification_data' => $failedNotification->toDatabase($user)]);
                    } catch (\Exception $e) {
                        Log::error("Failed to send deposit failed notification", [
                            'error' => $e->getMessage(),
                            'user_id' => $user->id,
                            'deposit_reference' => $deposit->reference,
                        ]);
                    }
                }
            }
    
            return response()->json(['error' => 'Deposit verification failed'], 400);
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
}
