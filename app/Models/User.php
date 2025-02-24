<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Str;
use Carbon\Carbon;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'balance',
        'account_number',
        'phone_number',
        'bank_code',
        'account_name',
        'bank_name',
        'uid',
        'verification_code',
        'verification_code_expiry',
        'password_reset_code',
        'password_reset_code_expires_at',
        'referral_code',
        'referred_by', 
        'transaction_pin'
    ];

    protected $hidden = [
        'password',
        'account_number',
        'remember_token',
        'bank_code',
        'transaction_pin'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'verification_code_expiry' => 'datetime',
        'password_reset_code_expires_at' => 'datetime',
        'balance' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            // Generate unique UID
            do {
                $user->uid = 'USR-' . strtoupper(Str::random(6));
            } while (self::where('uid', $user->uid)->exists());

            // Generate a unique referral code
            do {
                $user->referral_code = Str::upper(Str::random(8));
            } while (self::where('referral_code', $user->referral_code)->exists());
        });
    }

    public function setTransactionPinAttribute($value)
    {
        $this->attributes['transaction_pin'] = bcrypt($value);
    }

    public function verifyTransactionPin($pin)
    {
        return \Hash::check($pin, $this->transaction_pin);
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // Relationship: User who referred this user
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    // Relationship: Users referred by this user
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    // Relationship: Purchases made by the user
    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    // Generate and send email verification code
    public function sendEmailVerificationCode()
    {
        $this->verification_code = random_int(100000, 999999);
        $this->verification_code_expiry = now()->addMinutes(30);
        $this->save();

        try {
            \Mail::to($this->email)->send(new \App\Mail\VerifyEmailMail($this->verification_code));
        } catch (\Exception $e) {
            \Log::error("Failed to send verification email: " . $e->getMessage());
        }
    }

    // Verify email with a code
    public function verifyEmail($code)
    {
        if ($this->verification_code === $code && now()->lessThanOrEqualTo($this->verification_code_expiry)) {
            $this->email_verified_at = now();
            $this->verification_code = null;
            $this->verification_code_expiry = null;
            $this->save();
            return true;
        }

        return false;
    }

    // Deposit balance
    public function deposit($amount)
    {
        $this->balance += $amount;
        $this->save();
    }

    // Withdraw balance
    public function withdraw($amount)
    {
        if ($this->balance >= $amount) {
            $this->balance -= $amount;
            $this->save();
            return true;
        }

        return false;
    }
}
