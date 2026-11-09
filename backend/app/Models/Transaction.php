<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Transaction extends Model
{
    public const ACTIVE_STATUSES = [
        'pending_agreement',
        'meetup_arranged',
        'disputed',
    ];

    protected $attributes = [
        'transaction_status' => 'pending_agreement',
    ];

    protected $fillable = [
        'listing_id',
        'buyer_id',
        'seller_id',
        'agreed_price',
        'idempotency_key',
        'transaction_status',
        'meetup_date',
        'meetup_time',
        'meetup_location',
        'buyer_meetup_confirmed_at',
        'seller_meetup_confirmed_at',
        'buyer_completion_confirmed_at',
        'seller_completion_confirmed_at',
        'seller_unlock_requested_at',
        'buyer_unlock_response_deadline',
        'cancellation_reason',
        'cancellation_requested_by',
        'cancellation_requested_at',
        'cancellation_request_reason',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'agreed_price' => 'decimal:2',
            'meetup_date' => 'date',
            'meetup_time' => 'datetime:H:i:s',
            'buyer_meetup_confirmed_at' => 'datetime',
            'seller_meetup_confirmed_at' => 'datetime',
            'buyer_completion_confirmed_at' => 'datetime',
            'seller_completion_confirmed_at' => 'datetime',
            'seller_unlock_requested_at' => 'datetime',
            'buyer_unlock_response_deadline' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Transaction $transaction): void {
            $transaction->active_listing_id = in_array(
                $transaction->transaction_status,
                self::ACTIVE_STATUSES,
                true,
            ) ? $transaction->listing_id : null;
        });
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TransactionEvent::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
