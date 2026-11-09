<?php

namespace Tests;

use App\Models\Listing;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createCompletedTransaction(Listing $listing, User $buyer, User $seller): Transaction
    {
        $transaction = Transaction::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'agreed_price' => $listing->price,
        ]);
        $transaction->update([
            'transaction_status' => 'meetup_arranged',
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
            'buyer_meetup_confirmed_at' => now(),
            'seller_meetup_confirmed_at' => now(),
        ]);
        $transaction->update([
            'transaction_status' => 'completed',
            'buyer_completion_confirmed_at' => now(),
            'seller_completion_confirmed_at' => now(),
            'completed_at' => now(),
        ]);

        return $transaction;
    }
}
