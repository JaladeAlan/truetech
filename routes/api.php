<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;

// Public routes
Route::post('/register', [AuthController::class, 'register']); // User registration
Route::post('/login', [AuthController::class, 'login']); // Login route (JWT)

// Email verification routes
Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode']);
Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail']);

// Password reset routes using code
Route::prefix('password')->group(function () {
    Route::post('/reset/code', [AuthController::class, 'sendPasswordResetCode']); // Send reset code to email
    Route::post('/reset/verify', [AuthController::class, 'verifyResetCode']); // Verify reset code
    Route::post('/reset', [AuthController::class, 'resetPassword']); // Reset password with code
});

// Deposit callback route (signed to prevent unauthorized access)
Route::get('/deposit/callback', [DepositController::class, 'handleDepositCallback'])->name('deposit.callback');

// Paystack Webhook (Public - No Authentication)
Route::post('/paystack/webhook', [WithdrawalController::class, 'handlePaystackCallback']);

// Protected routes - requires user authentication via JWT
// Protected routes - requires user authentication via JWT
Route::middleware(['jwt.auth'])->group(function () {

    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']); // User logout

    // User profile & balance
    Route::get('/user/profile', [UserController::class, 'getProfile']); // Get user profile
    Route::get('/user/balance', function () {
        $user = auth()->user();
        return response()->json(['balance' => $user->balance]);
    });

    // Bank details
    Route::put('/user/bankdetails', [UserController::class, 'updateBankDetails']); // Update bank details

    // Deposits & Withdrawals
    Route::post('/deposit', [DepositController::class, 'initiateDeposit']); // Deposit funds
    Route::post('/withdraw', [WithdrawalController::class, 'initiateWithdrawal']); // Withdraw funds
    Route::post('/withdrawal/request', [WithdrawalController::class, 'requestWithdrawal']); // Request withdrawal
    Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']); // Withdrawal status

    // Transaction PIN
    Route::post('/pin/set', [UserController::class, 'setTransactionPin']);
    Route::post('/pin/update', [UserController::class, 'updateTransactionPin']);

    // Referral System
    Route::get('/user/referrals', [UserController::class, 'getReferralStats']); // Get referral stats
});
