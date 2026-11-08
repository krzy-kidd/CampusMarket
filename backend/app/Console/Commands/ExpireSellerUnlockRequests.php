<?php

namespace App\Console\Commands;

use App\Models\Listing;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireSellerUnlockRequests extends Command
{
    protected $signature = 'transactions:expire-unanswered-unlocks';

    protected $description = 'Release listings when buyers do not respond to seller unlock requests in time';

    public function handle(): int
    {
        $expiredCount = 0;

        Transaction::query()
            ->where('transaction_status', 'meetup_arranged')
            ->whereNotNull('seller_unlock_requested_at')
            ->whereNotNull('buyer_unlock_response_deadline')
            ->where('buyer_unlock_response_deadline', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use (&$expiredCount): void {
                foreach ($transactions as $candidate) {
                    $expired = DB::transaction(function () use ($candidate): bool {
                        $transaction = Transaction::query()
                            ->whereKey($candidate->id)
                            ->lockForUpdate()
                            ->firstOrFail();
                        if (
                            $transaction->transaction_status !== 'meetup_arranged'
                            || $transaction->seller_unlock_requested_at === null
                            || $transaction->buyer_unlock_response_deadline === null
                            || $transaction->buyer_unlock_response_deadline->isFuture()
                        ) {
                            return false;
                        }

                        $listing = Listing::query()
                            ->whereKey($transaction->listing_id)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $transaction->update([
                            'transaction_status' => 'cancelled',
                            'cancellation_reason' => 'The buyer did not respond to the seller unlock request before the response deadline.',
                        ]);
                        $listing->update(['listing_status' => 'available']);
                        TransactionEvent::query()->create([
                            'transaction_id' => $transaction->id,
                            'actor_id' => null,
                            'event_type' => 'seller_unlock_expired',
                            'event_description' => 'The buyer did not respond before the deadline. The transaction was cancelled and the listing released.',
                            'created_at' => now(),
                        ]);
                        foreach ([$transaction->buyer_id, $transaction->seller_id] as $userId) {
                            Notification::query()->create([
                                'user_id' => $userId,
                                'notification_type' => 'transaction_update',
                                'message' => 'The buyer did not respond before the deadline. The transaction was cancelled and the listing released.',
                                'action_url' => '/transactions/'.$transaction->id,
                                'created_at' => now(),
                            ]);
                        }

                        return true;
                    });

                    if ($expired) {
                        $expiredCount++;
                    }
                }
            });

        $this->components->info("Expired {$expiredCount} seller unlock request(s).");

        return self::SUCCESS;
    }
}
