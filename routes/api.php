<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WebhookController;

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Email verification routes
Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode']);
Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail']);

// Password reset routes
Route::prefix('password')->group(function () {
    Route::post('/reset/code', [AuthController::class, 'sendPasswordResetCode']);
    Route::post('/reset/verify', [AuthController::class, 'verifyResetCode']);
    Route::post('/reset', [AuthController::class, 'resetPassword']);
});

// Deposit callback (signed to prevent unauthorized access)
Route::get('/deposit/callback', [DepositController::class, 'handleDepositCallback'])->name('deposit.callback');

// Paystack Webhook (Public - No Authentication)
Route::post('/paystack/webhook', [WithdrawalController::class, 'handlePaystackCallback']);

// Autopilot Webhook (Public - No Authentication)
Route::post('/autopilot/webhook', [WebhookController::class, 'handleWebhook']);

// Protected routes - requires user authentication via JWT
Route::middleware(['jwt.auth'])->group(function () {

    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']);

    // User profile & balance
    Route::get('/user/profile', [UserController::class, 'getProfile']);
    Route::get('/user/balance', function () {
        $user = auth()->user();
        return response()->json(['balance' => $user->balance]);
    });

    // Bank details
    Route::put('/user/bankdetails', [UserController::class, 'updateBankDetails']);

    // Deposits & Withdrawals
    Route::post('/deposit', [DepositController::class, 'initiateDeposit']);
    Route::post('/withdraw', [WithdrawalController::class, 'initiateWithdrawal']);
    Route::post('/withdrawal/request', [WithdrawalController::class, 'requestWithdrawal']);
    Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);

    // Transaction PIN
    Route::post('/pin/set', [UserController::class, 'setTransactionPin']);
    Route::post('/pin/update', [UserController::class, 'updateTransactionPin']);

    // Referral System
    Route::get('/user/referrals', [UserController::class, 'getReferralStats']);
    
    //User notifications
    Route::get('/notifications', [NotificationController::class, 'getNotifications']);
    Route::get('/notifications/unread', [NotificationController::class, 'getUnreadNotifications']);
    Route::post('/notifications/read', [NotificationController::class, 'markAllAsRead']);

    // ---------------- AUTOPILOT TRANSACTIONS ----------------
    Route::post('/airtime/purchase', [TransactionController::class, 'purchaseAirtime']);
    Route::post('/data/purchase', [TransactionController::class, 'purchaseData']);
    Route::post('/cable/purchase', [TransactionController::class, 'purchaseCable']);
    Route::post('/bill/pay', [TransactionController::class, 'payBill']);

    Route::get('/networks', [TransactionController::class, 'getNetworks']);
    Route::get('/airtime/types', [TransactionController::class, 'getAirtimeTypes']);
    Route::get('/data/types', [TransactionController::class, 'getDataTypes']);

    // Fetch available plans
    Route::get('/airtime/plans', [TransactionController::class, 'getAirtimePlans']);
    Route::get('/data/plans', [TransactionController::class, 'getDataPlans']);
    Route::get('/cable/plans', [TransactionController::class, 'getCablePlans']);
    Route::get('/bill/plans', [TransactionController::class, 'getBillPlans']);  

    Route::get('cable/providers', [TransactionController::class, 'getCableProviders']); // Get all cable providers
    Route::post('cable/plans', [TransactionController::class, 'getCablePlans']); // Get plans for a specific provider
    Route::post('cable/validate', [TransactionController::class, 'validateSmartCard']); // Validate smart card number
    Route::post('cable/purchase', [TransactionController::class, 'purchaseCable']); // Purchase cable subscription
    
    Route::get('/billers', [TransactionController::class, 'getBillers']); // Get all billers
    Route::post('billers/services', [TransactionController::class, 'getBillerServices']); // Get biller services
    Route::post('billers/validate-customer', [TransactionController::class, 'validateBillerCustomer']); // Validate customer account
    Route::post('billers/pay', [TransactionController::class, 'payBill']); // Pay a bill

    // Transaction History
    Route::get('/transactions', [TransactionController::class, 'getUserTransactions']);
    Route::get('/transactions/{reference}', [TransactionController::class, 'getTransactionDetails']);
});
