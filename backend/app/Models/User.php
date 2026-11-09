<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

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
#[Hidden(['password', 'remember_token'])]
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
            'password' => 'hashed',
        ];
    }

    public function sendEmailVerificationNotification()
    {
        if (config('app.env') !== 'local') {
            parent::sendEmailVerificationNotification();

            return;
        }

        $message = (new VerifyEmail)->toMail($this);
        $this->storeDemoEmail('email_verification', $message->actionUrl, now()->addMinutes(60));
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token)
    {
        if (config('app.env') !== 'local') {
            parent::sendPasswordResetNotification($token);

            return;
        }

        $message = (new ResetPassword($token))->toMail($this);
        $this->storeDemoEmail(
            'password_reset',
            $message->actionUrl,
            now()->addMinutes((int) config('auth.passwords.users.expire', 60)),
        );
    }

    private function storeDemoEmail(string $type, ?string $actionUrl, Carbon $expiresAt): void
    {
        if ($actionUrl === null || $actionUrl === '') {
            throw new \RuntimeException('A demo email action link could not be generated.');
        }

        DemoEmail::query()
            ->where('user_id', $this->getKey())
            ->where('email_type', $type)
            ->delete();

        DemoEmail::query()->create([
            'user_id' => $this->getKey(),
            'recipient_email' => $this->email,
            'email_type' => $type,
            'action_url' => $actionUrl,
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);
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
