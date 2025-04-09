    <?php

    use Illuminate\Support\Facades\Route;
    use App\Http\Controllers\AuthController;
    use App\Http\Controllers\PurchaseController;
    use App\Http\Controllers\DepositController;
    use App\Http\Controllers\UserController;
    use App\Http\Controllers\WithdrawalController;
    use App\Http\Controllers\TransactionController;
    use App\Http\Controllers\WebhookController;
    use App\Http\Controllers\NotificationController; 
    use App\Http\Controllers\MaskawasubController; 
    use App\Http\Controllers\MonnifyController; 

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

    // Deposit callback
    Route::get('/deposit/callback', [DepositController::class, 'handleDepositCallback'])->name('deposit.callback');
   
    // Monnify deposit initialization
    Route::post('/deposit/monnify/initiate', [MonnifyController::class, 'processMonnifyDeposit']);
    Route::post('/monnify/deposit/callback', [DepositController::class, 'handleMonnifyCallback']);
    Route::get('/monnify/deposit/verify', [MonnifyController::class, 'verifyDeposit']); 

    // Webhooks (No Authentication)
    Route::post('/paystack/webhook', [WithdrawalController::class, 'handlePaystackCallback']);
    Route::post('/autopilot/webhook', [WebhookController::class, 'handleWebhook']);
    Route::post('/monnify/webhook', [MonnifyController::class, 'webhook']);

    Route::get('/validatesmartcards', [MaskawasubController::class, 'validateSmartCard']);

    // Protected routes - requires JWT authentication
    Route::middleware(['jwt.auth'])->group(function () {

        // Authentication
        Route::post('/logout', [AuthController::class, 'logout']);

        // User profile & balance
        Route::get('/user/profile', [UserController::class, 'getProfile']);
        Route::get('/user/balance', fn() => response()->json(['balance' => auth()->user()->balance]));

        // Bank details
        Route::put('/user/bankdetails', [UserController::class, 'updateBankDetails']);

        // Deposits & Withdrawals
        Route::get('/manual', [DepositController::class, 'getManualFundingDetails']);
        Route::post('/deposit', [DepositController::class, 'initiateDeposit']);
        Route::post('/withdraw', [WithdrawalController::class, 'initiateWithdrawal']);
        Route::post('/withdrawal/request', [WithdrawalController::class, 'requestWithdrawal']);
        Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);
        Route::get('/withdrawal/retry', [WithdrawalController::class, 'retryPendingWithdrawals']);

        // Transaction PIN
        Route::post('/pin/set', [UserController::class, 'setTransactionPin']);
        Route::post('/pin/update', [UserController::class, 'updateTransactionPin']);

        // Referral System
        Route::get('/user/referrals', [UserController::class, 'getReferralStats']);

        // User notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'getNotifications']);
            Route::get('/unread', [NotificationController::class, 'getUnreadNotifications']);
            Route::post('/read', [NotificationController::class, 'markAllAsRead']);
        });

        // ---------------- AUTOPILOT TRANSACTIONS ----------------
        Route::prefix('airtime')->group(function () {
            Route::post('/purchase', [TransactionController::class, 'purchaseAirtime']);
            Route::get('/types', [TransactionController::class, 'getAirtimeTypes']);
            Route::get('/plans', [TransactionController::class, 'getAirtimePlans']);
        });

        Route::prefix('data')->group(function () {
            Route::post('/purchase', [TransactionController::class, 'purchaseData']);
            Route::get('/types', [TransactionController::class, 'getDataTypes']);
            Route::get('/plans', [TransactionController::class, 'getDataPlans']);
        });

        Route::prefix('cable')->group(function () {
            Route::post('/purchase', [TransactionController::class, 'purchaseCable']);
            Route::get('/providers', [TransactionController::class, 'getCableProviders']);
            Route::get('/plans', [TransactionController::class, 'getCablePlans']);
            Route::post('/plans', [TransactionController::class, 'getCablePlans']);
            Route::post('/validate', [TransactionController::class, 'validateSmartCard']);
        });

        Route::prefix('billers')->group(function () {
            Route::get('/', [TransactionController::class, 'getBillers']);
            Route::post('/services', [TransactionController::class, 'getBillerServices']);
            Route::post('/validate-customer', [TransactionController::class, 'validateBillerCustomer']);
            Route::post('/pay', [TransactionController::class, 'payBill']);
        });

        // Transaction History
        Route::prefix('transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'getUserTransactions']);
            Route::get('/{reference}', [TransactionController::class, 'getTransactionDetails']);
        });

        // ---------------- MASKAWASUB TRANSACTIONS ----------------
    Route::prefix('maskawasub')->group(function () {
        Route::post('/data', [MaskawasubController::class, 'purchaseData']);
        Route::post('/topup', [MaskawasubController::class, 'topUp']);
        Route::post('/bill', [MaskawasubController::class, 'payBill']);
        Route::post('/cable', [MaskawasubController::class, 'cableSubscription']);
        Route::get('/user', [MaskawasubController::class, 'getUserDetails']);

        // Validation routes (GET requests)
        Route::get('/validatesmartcard', [MaskawasubController::class, 'validateSmartCard']);
        Route::get('/validatemeter', [MaskawasubController::class, 'validateMeterNumber']);
    });

    Route::middleware(['jwt.auth', \App\Http\Middleware\AdminMiddleware::class])->group(function () {
        Route::post('/admin/manual', [DepositController::class, 'manualDeposit']);
        Route::get('/admin/deposits', [DepositController::class, 'getPendingManualDeposits']);
        Route::post('/admin/deposits/approve', [DepositController::class, 'approveManualDeposit']);
        Route::post('/admin/deposits/reject', [DepositController::class, 'rejectManualDeposit']);
    });
    
    });

    
