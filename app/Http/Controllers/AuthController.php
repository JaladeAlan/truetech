<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\VerifyEmailMail;
use App\Mail\ResetPasswordEmail;

class AuthController extends Controller
{
    private function sendSuccessResponse($data, $message = 'Success', $status = 200)
    {
        return response()->json(['message' => $message, 'data' => $data], $status);
    }

    private function sendErrorResponse($message, $status = 400, $errors = [])
    {
        return response()->json(['message' => $message, 'errors' => $errors], $status);
    }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'username' => 'required|string|max:255|unique:users',
                'phone_number' => 'required|string|max:15|unique:users',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => ['required','string','min:8','confirmed','regex:/[A-Z]/','regex:/[a-z]/','regex:/[0-9]/','regex:/[@$!%*?&#]/'],
                'referral_code' => 'nullable|string|exists:users,referral_code',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Validation errors occurred', 422, $e->validator->errors());
        }

        try {
            $referredBy = null;
            if ($request->filled('referral_code')) {
                $referrer = User::where('referral_code', $request->referral_code)->first();
                if (!$referrer) {
                    return $this->sendErrorResponse('Invalid referral code', 422);
                }
                $referredBy = $referrer->id;
            }

            $verificationCode = (string) random_int(100000, 999999);

            $user = User::create([
                'name' => $request->name,
                'username' => $request->username,
                'phone_number' => $request->phone_number,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'referred_by' => $referredBy,
                'referral_code' => Str::random(8),
                'verification_code' => $verificationCode,
            ]);

            Log::info('New user registered', ['user_id' => $user->id]);

            Mail::to($user->email)->send(new VerifyEmailMail($user, $verificationCode));

            return $this->sendSuccessResponse([
                'user' => $user
            ], 'Registration successful. Please verify your email.', 201);

        } catch (\Exception $e) {
            Log::error('User registration failed', ['error' => $e->getMessage()]);
            return $this->sendErrorResponse('Registration failed. Please try again later.', 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Validation errors occurred', 422, $e->validator->errors());
        }

        $user = User::where('email', $request->email)->first();
        if (!$user) return $this->sendErrorResponse('User not found', 404);
        if (!$user->hasVerifiedEmail()) return $this->sendErrorResponse('Please verify your email before logging in.', 403);

        try {
            if (!$token = JWTAuth::attempt($request->only('email','password'))) {
                return $this->sendErrorResponse('Invalid credentials', 401);
            }
            return $this->sendSuccessResponse(['token' => $token], 'Login successful');
        } catch (JWTException $e) {
            Log::error('JWT token creation failed', ['error' => $e->getMessage()]);
            return $this->sendErrorResponse('Could not create token', 500);
        }
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->sendSuccessResponse([], 'Successfully logged out');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not log out', 500);
        }
    }

    public function refresh()
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return $this->sendSuccessResponse(['token' => $newToken], 'Token refreshed successfully');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not refresh token', 500);
        }
    }

    public function sendPasswordResetCode(Request $request)
    {
        $request->validate(['email'=>'required|email']);
        $user = User::where('email',$request->email)->first();
        if (!$user) return $this->sendErrorResponse('User not found',404);

        $resetCode = (string) random_int(100000, 999999);
        $user->password_reset_code = $resetCode;
        $user->password_reset_code_expires_at = now()->addMinutes(30);
        $user->save();

        try {
            Mail::to($user->email)->send(new ResetPasswordEmail($user,$resetCode));
        } catch (\Exception $e) {
            Log::error('Password reset email failed', ['error'=>$e->getMessage()]);
            return $this->sendErrorResponse('Failed to send password reset email.',500);
        }

        return $this->sendSuccessResponse([], 'Password reset code sent to your email.');
    }

    public function verifyResetCode(Request $request)
    {
        $request->validate(['email'=>'required|email','reset_code'=>'required|string|size:6']);
        $user = User::where('email',$request->email)->first();
        if (!$user) return $this->sendErrorResponse('User not found',404);

        if ($user->password_reset_code !== $request->reset_code || $user->password_reset_code_expires_at->isPast()) {
            return $this->sendErrorResponse('Invalid or expired reset code',400);
        }

        $user->password_reset_code = null;
        $user->password_reset_code_expires_at = null;
        $user->save();

        return $this->sendSuccessResponse([], 'Reset code verified. You can now reset your password.');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'=>'required|email',
            'password'=>['required','string','min:8','confirmed','regex:/[A-Z]/','regex:/[a-z]/','regex:/[0-9]/','regex:/[@$!%*?&#]/']
        ]);

        $user = User::where('email',$request->email)->first();
        if (!$user) return $this->sendErrorResponse('No account found with this email.',404);

        if ($user->password_reset_code !== null || $user->password_reset_code_expires_at !== null) {
            return $this->sendErrorResponse('Please verify the reset code before setting a new password.',400);
        }

        $user->password = Hash::make($request->password);
        $user->save();

        return $this->sendSuccessResponse([], 'Password has been reset successfully.');
    }

    public function verifyEmailCode(Request $request)
    {
        $request->validate(['email'=>'required|email','verification_code'=>'required|string|size:6']);
        $user = User::where('email',$request->email)->first();
        if (!$user) return $this->sendErrorResponse('User not found',404);

        if ($user->verification_code !== $request->verification_code) {
            return $this->sendErrorResponse('Invalid verification code.',400);
        }

        $user->markEmailAsVerified();
        $user->verification_code = null;
        $user->save();

        return $this->sendSuccessResponse([], 'Email verified successfully.');
    }

    public function resendVerificationEmail(Request $request)
    {
        $request->validate(['email'=>'required|email']);
        $user = User::where('email',$request->email)->first();
        if (!$user) return $this->sendErrorResponse('User not found',404);
        if ($user->hasVerifiedEmail()) return $this->sendErrorResponse('Your email is already verified.',400);

        $verificationCode = (string) random_int(100000,999999);
        $user->verification_code = $verificationCode;
        $user->save();

        try {
            Mail::to($user->email)->send(new VerifyEmailMail($user,$verificationCode));
        } catch (\Exception $e) {
            Log::error('Resend verification email failed', ['error'=>$e->getMessage()]);
            return $this->sendErrorResponse('Failed to send verification email.',500);
        }

        return $this->sendSuccessResponse([], 'A new verification code has been sent to your email.');
    }
}
