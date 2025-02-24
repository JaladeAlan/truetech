<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Update user's bank details using Paystack API
     */
    public function updateBankDetails(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'account_number' => 'required|numeric|digits_between:10,12',
            'bank_name' => 'required|string',
        ]);

        try {
            // Fetch bank codes from Paystack (cached for efficiency)
            $banks = Cache::remember('paystack_banks', now()->addHours(12), function () {
                $response = Http::withToken(config('services.paystack.secret_key'))
                    ->get('https://api.paystack.co/bank');

                return $response->successful() ? $response->json()['data'] : [];
            });

            $bank = collect($banks)->firstWhere('name', $request->bank_name);

            if (!$bank) {
                return response()->json(['error' => 'Invalid bank name provided'], 400);
            }

            $bankCode = $bank['code'];

            // Resolve account number with Paystack
            $resolveResponse = Http::withToken(config('services.paystack.secret_key'))
                ->get('https://api.paystack.co/bank/resolve', [
                    'account_number' => $request->account_number,
                    'bank_code' => $bankCode,
                ]);

            if (!$resolveResponse->successful()) {
                return response()->json([
                    'error' => 'Failed to resolve account number',
                    'details' => $resolveResponse->json(),
                ], 400);
            }

            $resolvedData = $resolveResponse->json();
            if (!isset($resolvedData['data']['account_name'])) {
                return response()->json(['error' => 'Invalid account details returned'], 400);
            }

            $accountName = $resolvedData['data']['account_name'];

            Log::info('Updating bank details:', [
                'user_id' => $user->id,
                'account_number' => $request->account_number,
                'bank_code' => $bankCode,
                'bank_name' => $request->bank_name,
                'account_name' => $accountName,
            ]);

            // Update the user's bank details
            $user->update([
                'account_number' => $request->account_number,
                'bank_code' => $bankCode,
                'bank_name' => $request->bank_name,
                'account_name' => $accountName,
            ]);

            return response()->json(['message' => 'Bank details updated successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error updating bank details: ' . $e->getMessage());

            return response()->json([
                'error' => 'An error occurred while updating bank details',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user profile details
     */
    public function getProfile()
    {
        $user = JWTAuth::parseToken()->authenticate();

        return response()->json([
            'name' => $user->name,
            'username' =>$user->username,
            'phoneno'=>$user->phone_number,
            'email' => $user->email,
            'balance' => $user->balance,
            'referral_code' => $user->referral_code,
            'referral_count' => $user->referrals()->count(),
        ]);
    }

    /**
     * Get referral statistics for the user
     */
    public function getReferralStats()
    {
        $user = JWTAuth::parseToken()->authenticate();

        $referrals = $user->referrals()->select('name', 'email', 'created_at')->get();

        return response()->json([
            'referral_count' => $referrals->count(),
            'referrals' => $referrals,
        ]);
    }
    public function setTransactionPin(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
    
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'pin' => 'required|digits:4',
            'confirm_pin' => 'required|digits:4|same:pin',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Incorrect account password'], 400);
        }
    
        $user->transaction_pin = $request->pin;
        $user->save();
    
        return response()->json(['message' => 'Transaction PIN set successfully'], 200);
    }
    
    public function updateTransactionPin(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
    
        $validator = Validator::make($request->all(), [
            'current_pin' => 'required|digits:4',
            'new_pin' => 'required|digits:4',
            'confirm_new_pin' => 'required|digits:4|same:new_pin',
        ]);
    
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        if (!$user->verifyTransactionPin($request->current_pin)) {
            return response()->json(['error' => 'Incorrect current PIN'], 400);
        }
    
        $user->transaction_pin = $request->new_pin;
        $user->save();
    
        return response()->json(['message' => 'Transaction PIN updated successfully'], 200);
    }
    
}
