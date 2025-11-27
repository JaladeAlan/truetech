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
    protected $user;

    public function __construct()
    {
        // Automatically authenticate user via JWT
        $this->middleware(function ($request, $next) {
            $this->user = JWTAuth::parseToken()->authenticate();
            return $next($request);
        });
    }

    /**
     * Update user's bank details using Paystack API
     */
    public function updateBankDetails(Request $request)
    {
        $request->validate([
            'account_number' => 'required|numeric|digits_between:10,12',
            'bank_name' => 'required|string',
        ]);

        try {
            // Fetch bank codes (cached)
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

            // Resolve account number
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
            $accountName = $resolvedData['data']['account_name'] ?? null;

            if (!$accountName) {
                return response()->json(['error' => 'Invalid account details returned'], 400);
            }

            // Mask account number for logging
            Log::info('Updating bank details:', [
                'user_id' => $this->user->id,
                'account_number' => '****' . substr($request->account_number, -4),
                'bank_code' => $bankCode,
                'bank_name' => $request->bank_name,
                'account_name' => $accountName,
            ]);

            // Update user bank details
            $this->user->update([
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
        return response()->json([
            'name' => $this->user->name,
            'username' => $this->user->username,
            'phoneno' => $this->user->phone_number,
            'email' => $this->user->email,
            'balance' => $this->user->balance,
            'referral_code' => $this->user->referral_code,
            'referral_count' => $this->user->referrals()->count(),
        ]);
    }

    /**
     * Get referral statistics with optional pagination
     */
    public function getReferralStats(Request $request)
    {
        $perPage = $request->query('per_page', 10);

        $referrals = $this->user->referrals()
            ->select('name', 'email', 'created_at')
            ->paginate($perPage);

        return response()->json([
            'referral_count' => $referrals->total(),
            'referrals' => $referrals->items(),
            'current_page' => $referrals->currentPage(),
            'last_page' => $referrals->lastPage(),
        ]);
    }

    /**
     * Set transaction PIN (hashed)
     */
    public function setTransactionPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
            'pin' => 'required|digits:4',
            'confirm_pin' => 'required|digits:4|same:pin',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check($request->password, $this->user->password)) {
            return response()->json(['error' => 'Incorrect account password'], 400);
        }

        $this->user->transaction_pin = Hash::make($request->pin);
        $this->user->save();

        return response()->json(['message' => 'Transaction PIN set successfully'], 200);
    }

    /**
     * Update transaction PIN
     */
    public function updateTransactionPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_pin' => 'required|digits:4',
            'new_pin' => 'required|digits:4',
            'confirm_new_pin' => 'required|digits:4|same:new_pin',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (!Hash::check($request->current_pin, $this->user->transaction_pin)) {
            return response()->json(['error' => 'Incorrect current PIN'], 400);
        }

        $this->user->transaction_pin = Hash::make($request->new_pin);
        $this->user->save();

        return response()->json(['message' => 'Transaction PIN updated successfully'], 200);
    }
}
