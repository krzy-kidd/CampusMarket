<?php

namespace App\Models;

use App\Notifications\EmailVerificationOtp;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use Throwable;

#[Fillable([
    'student_id_number',
    'first_name',
    'last_name',
    'email',
    'password',
    'college',
    'course',
    'year_level',
    'section',
    'account_status',
])]
#[Hidden([
    'password',
    'remember_token',
    'email_verification_code_hash',
    'email_verification_code_expires_at',
    'email_verification_code_attempts',
])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_code_expires_at' => 'datetime',
            'email_verification_code_attempts' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function sendEmailVerificationNotification(): void
    {
        if ($this->hasVerifiedEmail()) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->forceFill([
            'email_verification_code_hash' => Hash::make($code),
            'email_verification_code_expires_at' => now()->addMinutes(10),
            'email_verification_code_attempts' => 0,
        ])->save();

        try {
            $this->notify(new EmailVerificationOtp($code));
        } catch (Throwable $exception) {
            $this->forceFill([
                'email_verification_code_hash' => null,
                'email_verification_code_expires_at' => null,
                'email_verification_code_attempts' => 0,
            ])->save();

            throw $exception;
        }
    }

    public function admin(): HasOne
    {
        return $this->hasOne(Admin::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    public function verificationAttempts(): HasMany
    {
        return $this->hasMany(VerificationAttempt::class);
    }

    public function isVerifiedForCurrentAcademicYear(): bool
    {
        $academicYearId = AcademicYear::query()
            ->where('is_current', true)
            ->value('id');

        return $this->hasVerifiedEmail()
            && $this->account_status === 'active'
            && $academicYearId !== null
            && $this->verifications()
                ->where('academic_year_id', $academicYearId)
                ->where('submission_status', 'approved')
                ->exists();
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'seller_id');
    }

    public function buyerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'buyer_id');
    }

    public function sellerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'seller_id');
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Transaction::class, 'buyer_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Transaction::class, 'seller_id');
    }

    public function transactionEvents(): HasMany
    {
        return $this->hasMany(TransactionEvent::class, 'actor_id');
    }

    public function writtenReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function receivedReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewed_user_id');
    }

    public function campusNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
