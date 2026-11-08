<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\PlatformSetting;
use App\Models\Report;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $validated = $request->validate(['cursor' => ['nullable', 'string']]);
        $transactions = Transaction::query()
            ->where(fn (Builder $query) => $query
                ->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id))
            ->with(['listing', 'buyer:id,first_name,last_name', 'seller:id,first_name,last_name'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate(20, ['*'], 'cursor', $validated['cursor'] ?? null);

        return response()->json([
            'transactions' => $transactions->getCollection()
                ->map(fn (Transaction $transaction): array => $this->present($transaction, $user->id)),
            'next_cursor' => $transactions->nextCursor()?->encode(),
        ]);
    }

    public function createFromConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        abort_unless($conversation->buyer_id === $user->id, 403, 'Only the buyer can request this purchase.');

        [$transaction, $created] = DB::transaction(function () use ($conversation, $user): array {
            $lockedConversation = Conversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existingTransaction = $lockedConversation->transaction;
            if ($existingTransaction && $existingTransaction->transaction_status !== 'cancelled') {
                return [$existingTransaction->load([
                    'listing',
                    'buyer:id,first_name,last_name',
                    'seller:id,first_name,last_name',
                ]), false];
            }

            $listing = Listing::query()
                ->whereKey($lockedConversation->listing_id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($listing->listing_status === 'available', 409, 'This listing is not available for a purchase request.');
            abort_if($listing->seller_id !== $lockedConversation->seller_id, 409, 'The listing seller has changed.');

            $transaction = Transaction::query()->create([
                'listing_id' => $listing->id,
                'buyer_id' => $lockedConversation->buyer_id,
                'seller_id' => $lockedConversation->seller_id,
                'agreed_price' => $listing->price,
                'transaction_status' => 'pending_agreement',
            ]);
            $listing->update(['listing_status' => 'pending_agreement']);
            $lockedConversation->update(['transaction_id' => $transaction->id]);
            $lockedConversation->messages()->create([
                'sender_id' => $user->id,
                'message_text' => 'Purchase request started for PHP '.number_format((float) $listing->price, 2, '.', '').'. Please confirm meetup details in the transaction.',
                'sent_at' => now(),
            ]);
            $this->recordEvent($transaction, $user, 'purchase_requested', 'The buyer requested to purchase this listing at the listed price.');

            return [$transaction->load([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
            ]), true];
        });

        return response()->json([
            'transaction' => $this->present($transaction, $user->id),
        ], $created ? 201 : 200);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);

        $transaction->load([
            'listing',
            'buyer:id,first_name,last_name',
            'seller:id,first_name,last_name',
            'events.actor:id,first_name,last_name',
            'reports',
            'reviews.reviewer:id,first_name,last_name',
        ]);

        return response()->json(['transaction' => $this->present($transaction, $user->id)]);
    }

    public function submitReview(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);
        $request->merge([
            'comment' => is_string($request->input('comment'))
                ? trim($request->input('comment'))
                : $request->input('comment'),
        ]);
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $transaction = DB::transaction(function () use ($transaction, $user, $validated): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($transaction->buyer_id === $user->id || $transaction->seller_id === $user->id, 404, 'This transaction was not found.');
            abort_unless($transaction->transaction_status === 'completed', 409, 'Reviews can only be submitted after a transaction is completed.');
            abort_if(
                $transaction->reviews()->where('reviewer_id', $user->id)->exists(),
                409,
                'You have already reviewed this transaction.',
            );

            $reviewedUserId = $user->id === $transaction->buyer_id
                ? $transaction->seller_id
                : $transaction->buyer_id;
            $transaction->reviews()->create([
                'reviewer_id' => $user->id,
                'reviewed_user_id' => $reviewedUserId,
                'rating' => $validated['rating'],
                'comment' => ($validated['comment'] ?? '') !== '' ? $validated['comment'] : null,
                'created_at' => now(),
            ]);
            Notification::query()->create([
                'user_id' => $reviewedUserId,
                'notification_type' => 'new_review',
                'message' => 'You received a new rating for '.$transaction->listing()->value('title').'.',
                'action_url' => '/transactions/'.$transaction->id,
                'created_at' => now(),
            ]);

            return $transaction->fresh([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'events.actor:id,first_name,last_name',
                'reports',
                'reviews.reviewer:id,first_name,last_name',
            ]);
        });

        return response()->json(['transaction' => $this->present($transaction, $user->id)], 201);
    }

    public function requestUnlock(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);
        abort_unless($transaction->seller_id === $user->id, 403, 'Only the seller can request to release this listing.');

        $transaction = DB::transaction(function () use ($transaction, $user): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($transaction->seller_id === $user->id, 403, 'Only the seller can request to release this listing.');
            abort_unless($transaction->transaction_status === 'meetup_arranged', 409, 'A listing can only be released after a meetup is arranged.');
            abort_if($transaction->seller_unlock_requested_at !== null, 409, 'An unlock request is already active for this transaction.');

            $responseHours = (int) PlatformSetting::query()
                ->where('setting_key', 'seller_unlock_response_hours')
                ->value('setting_value');
            abort_if($responseHours < 1, 500, 'The seller unlock response window is not configured.');

            $requestedAt = now();
            $deadline = $requestedAt->copy()->addHours($responseHours);
            $transaction->update([
                'seller_unlock_requested_at' => $requestedAt,
                'buyer_unlock_response_deadline' => $deadline,
            ]);
            $this->recordEvent(
                $transaction,
                $user,
                'seller_unlock_requested',
                'The seller requested to release the listing. The buyer has until '.$deadline->toDayDateTimeString().' to respond.',
            );
            $this->recordConversationMessage(
                $transaction,
                $user,
                'The seller requested to release the listing. Please respond by '.$deadline->toDayDateTimeString().'.',
            );

            return $transaction->fresh([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'events.actor:id,first_name,last_name',
                'reports',
            ]);
        });

        return response()->json(['transaction' => $this->present($transaction, $user->id)]);
    }

    public function respondToUnlock(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);
        abort_unless($transaction->buyer_id === $user->id, 403, 'Only the buyer can respond to a seller unlock request.');
        $validated = $request->validate([
            'decision' => ['required', 'in:accept,decline'],
        ]);

        $transaction = DB::transaction(function () use ($transaction, $user, $validated): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($transaction->buyer_id === $user->id, 403, 'Only the buyer can respond to a seller unlock request.');
            abort_unless($transaction->transaction_status === 'meetup_arranged', 409, 'This transaction is no longer awaiting an unlock response.');
            abort_unless(
                $transaction->seller_unlock_requested_at !== null && $transaction->buyer_unlock_response_deadline !== null,
                409,
                'There is no active seller unlock request.',
            );
            if (now()->greaterThanOrEqualTo($transaction->buyer_unlock_response_deadline)) {
                $transaction->update([
                    'transaction_status' => 'cancelled',
                    'cancellation_reason' => 'The buyer did not respond to the seller unlock request before the response deadline.',
                ]);
                $transaction->listing()->update(['listing_status' => 'available']);
                TransactionEvent::query()->create([
                    'transaction_id' => $transaction->id,
                    'actor_id' => null,
                    'event_type' => 'seller_unlock_expired',
                    'event_description' => 'The buyer did not respond before the deadline. The transaction was cancelled and the listing released.',
                    'created_at' => now(),
                ]);
                $this->notifyTransactionParticipants(
                    $transaction,
                    'The buyer did not respond before the deadline. The transaction was cancelled and the listing released.',
                );
            } elseif ($validated['decision'] === 'accept') {
                $transaction->update([
                    'transaction_status' => 'cancelled',
                    'cancellation_reason' => 'The buyer accepted the seller’s request to release the listing.',
                ]);
                $transaction->listing()->update(['listing_status' => 'available']);
                $this->recordEvent($transaction, $user, 'seller_unlock_accepted', 'The buyer accepted the seller’s request. The transaction was cancelled and the listing released.');
                $this->recordConversationMessage($transaction, $user, 'The buyer accepted the seller’s request. The transaction was cancelled and the listing released.');
            } else {
                $transaction->update([
                    'seller_unlock_requested_at' => null,
                    'buyer_unlock_response_deadline' => null,
                ]);
                $this->recordEvent($transaction, $user, 'seller_unlock_declined', 'The buyer declined the seller’s request. The meetup arrangement remains active.');
                $this->recordConversationMessage($transaction, $user, 'The buyer declined the seller’s request. The meetup arrangement remains active.');
            }

            return $transaction->fresh([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'events.actor:id,first_name,last_name',
                'reports',
            ]);
        });

        return response()->json(['transaction' => $this->present($transaction, $user->id)]);
    }

    public function dispute(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:150'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $transaction = DB::transaction(function () use ($transaction, $user, $validated): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($transaction->buyer_id === $user->id || $transaction->seller_id === $user->id, 404, 'This transaction was not found.');
            abort_unless($transaction->transaction_status === 'meetup_arranged', 409, 'Only a meetup-arranged transaction can be disputed.');

            $otherParticipantId = $user->id === $transaction->buyer_id
                ? $transaction->seller_id
                : $transaction->buyer_id;
            Report::query()->create([
                'reporter_id' => $user->id,
                'reported_user_id' => $otherParticipantId,
                'listing_id' => $transaction->listing_id,
                'transaction_id' => $transaction->id,
                'reason' => trim($validated['reason']),
                'description' => trim($validated['description']),
                'report_status' => 'pending',
                'created_at' => now(),
            ]);

            $transaction->update([
                'transaction_status' => 'disputed',
                'seller_unlock_requested_at' => null,
                'buyer_unlock_response_deadline' => null,
            ]);
            $this->recordEvent($transaction, $user, 'transaction_disputed', 'A participant reported a problem. The transaction is paused for Super Admin review.');
            $this->recordConversationMessage($transaction, $user, 'A participant reported a problem. The transaction is paused for Super Admin review.');

            return $transaction->fresh([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'events.actor:id,first_name,last_name',
                'reports',
            ]);
        });

        return response()->json(['transaction' => $this->present($transaction, $user->id)]);
    }

    public function proposeMeetup(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);
        abort_unless($transaction->transaction_status === 'pending_agreement', 409, 'Meetup details can only be updated while the purchase request is pending.');

        $validated = $request->validate([
            'meetup_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'meetup_time' => ['required', 'date_format:H:i'],
            'meetup_location' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $meetupAt = Carbon::createFromFormat(
            'Y-m-d H:i',
            $validated['meetup_date'].' '.$validated['meetup_time'],
            config('app.timezone'),
        );
        if ($meetupAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'meetup_time' => ['Choose a future meetup time.'],
            ]);
        }

        $transaction = DB::transaction(function () use ($transaction, $user, $validated): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($transaction->transaction_status === 'pending_agreement', 409, 'Meetup details can only be updated while the purchase request is pending.');

            $detailsChanged = $transaction->meetup_date?->toDateString() !== $validated['meetup_date']
                || $transaction->meetup_time?->format('H:i') !== $validated['meetup_time']
                || $transaction->meetup_location !== trim($validated['meetup_location']);

            $changes = [
                'meetup_date' => $validated['meetup_date'],
                'meetup_time' => $validated['meetup_time'],
                'meetup_location' => trim($validated['meetup_location']),
            ];
            if ($user->id === $transaction->buyer_id) {
                $changes['buyer_meetup_confirmed_at'] = now();
                if ($detailsChanged) {
                    $changes['seller_meetup_confirmed_at'] = null;
                }
            } else {
                $changes['seller_meetup_confirmed_at'] = now();
                if ($detailsChanged) {
                    $changes['buyer_meetup_confirmed_at'] = null;
                }
            }

            $buyerConfirmedAt = array_key_exists('buyer_meetup_confirmed_at', $changes)
                ? $changes['buyer_meetup_confirmed_at']
                : $transaction->buyer_meetup_confirmed_at;
            $sellerConfirmedAt = array_key_exists('seller_meetup_confirmed_at', $changes)
                ? $changes['seller_meetup_confirmed_at']
                : $transaction->seller_meetup_confirmed_at;
            $bothConfirmed = $buyerConfirmedAt !== null && $sellerConfirmedAt !== null;
            $changes['transaction_status'] = $bothConfirmed ? 'meetup_arranged' : 'pending_agreement';
            $transaction->update($changes);

            if ($bothConfirmed) {
                $transaction->listing()->update(['listing_status' => 'locked']);
                $this->recordEvent($transaction, $user, 'meetup_arranged', 'Both participants confirmed the meetup details.');
            } else {
                $this->recordEvent($transaction, $user, 'meetup_proposed', 'A participant proposed or confirmed meetup details.');
            }

            return $transaction->fresh([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'events.actor:id,first_name,last_name',
                'reports',
            ]);
        });

        return response()->json(['transaction' => $this->present($transaction, $user->id)]);
    }

    public function confirmCompletion(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);

        $transaction = DB::transaction(function () use ($transaction, $user): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($transaction->transaction_status === 'meetup_arranged', 409, 'The meetup must be arranged before confirming completion.');

            $changes = $user->id === $transaction->buyer_id
                ? ['buyer_completion_confirmed_at' => now()]
                : ['seller_completion_confirmed_at' => now()];
            $otherConfirmation = $user->id === $transaction->buyer_id
                ? $transaction->seller_completion_confirmed_at
                : $transaction->buyer_completion_confirmed_at;
            $bothConfirmed = $otherConfirmation !== null;
            if ($bothConfirmed) {
                $changes['transaction_status'] = 'completed';
                $changes['completed_at'] = now();
            }

            $transaction->update($changes);
            if ($bothConfirmed) {
                $transaction->listing()->update(['listing_status' => 'sold']);
            }
            $this->recordEvent(
                $transaction,
                $user,
                $bothConfirmed ? 'transaction_completed' : 'completion_confirmed',
                $bothConfirmed
                    ? 'Both participants confirmed the transaction is complete.'
                    : 'A participant confirmed the transaction is complete.',
            );

            return $transaction->fresh([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'events.actor:id,first_name,last_name',
                'reports',
            ]);
        });

        return response()->json(['transaction' => $this->present($transaction, $user->id)]);
    }

    public function cancel(Request $request, Transaction $transaction): JsonResponse
    {
        $user = $request->user();
        $this->ensureActiveAccount($user);
        $this->ensureParticipant($transaction, $user);
        $request->merge([
            'cancellation_reason' => is_string($request->input('cancellation_reason'))
                ? trim($request->input('cancellation_reason'))
                : $request->input('cancellation_reason'),
        ]);
        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'max:1000'],
        ]);

        $transaction = DB::transaction(function () use ($transaction, $user, $validated): Transaction {
            $transaction = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless(
                in_array($transaction->transaction_status, ['pending_agreement', 'meetup_arranged'], true),
                409,
                'Only a pending or meetup-arranged transaction can be cancelled.',
            );
            abort_if(
                $transaction->transaction_status === 'meetup_arranged' && $transaction->seller_id === $user->id,
                409,
                'Sellers must request to release an arranged meetup and allow the buyer to respond.',
            );

            $transaction->update([
                'transaction_status' => 'cancelled',
                'cancellation_reason' => $validated['cancellation_reason'],
            ]);
            $transaction->listing()->update(['listing_status' => 'available']);
            $this->recordEvent($transaction, $user, 'transaction_cancelled', $validated['cancellation_reason']);

            return $transaction->fresh([
                'listing',
                'buyer:id,first_name,last_name',
                'seller:id,first_name,last_name',
                'events.actor:id,first_name,last_name',
                'reports',
            ]);
        });

        return response()->json(['transaction' => $this->present($transaction, $user->id)]);
    }

    private function present(Transaction $transaction, int $currentUserId): array
    {
        return [
            'id' => $transaction->id,
            'current_user_role' => $transaction->buyer_id === $currentUserId ? 'buyer' : 'seller',
            'listing' => [
                'id' => $transaction->listing->id,
                'title' => $transaction->listing->title,
                'listing_status' => $transaction->listing->listing_status,
            ],
            'buyer' => $this->userPayload($transaction->buyer),
            'seller' => $this->userPayload($transaction->seller),
            'transaction_status' => $transaction->transaction_status,
            'agreed_price' => $transaction->agreed_price,
            'meetup_date' => $transaction->meetup_date?->toDateString(),
            'meetup_time' => $transaction->meetup_time?->format('H:i'),
            'meetup_location' => $transaction->meetup_location,
            'buyer_meetup_confirmed_at' => $transaction->buyer_meetup_confirmed_at,
            'seller_meetup_confirmed_at' => $transaction->seller_meetup_confirmed_at,
            'buyer_completion_confirmed_at' => $transaction->buyer_completion_confirmed_at,
            'seller_completion_confirmed_at' => $transaction->seller_completion_confirmed_at,
            'seller_unlock_requested_at' => $transaction->seller_unlock_requested_at,
            'buyer_unlock_response_deadline' => $transaction->buyer_unlock_response_deadline,
            'cancellation_reason' => $transaction->cancellation_reason,
            'completed_at' => $transaction->completed_at,
            'dispute' => $transaction->relationLoaded('reports')
                ? $transaction->reports
                    ->sortByDesc('created_at')
                    ->first(fn (Report $report): bool => $report->transaction_id === $transaction->id)
                    ?->only(['id', 'reason', 'description', 'report_status', 'resolution_notes', 'created_at'])
                : null,
            'reviews' => $transaction->relationLoaded('reviews')
                ? $transaction->reviews->map(fn (Review $review): array => [
                    'id' => $review->id,
                    'reviewer_id' => $review->reviewer_id,
                    'reviewed_user_id' => $review->reviewed_user_id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at,
                    'reviewer' => $review->reviewer ? $this->userPayload($review->reviewer) : null,
                ])->values()
                : [],
            'events' => $transaction->relationLoaded('events')
                ? $transaction->events->map(fn (TransactionEvent $event): array => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'event_description' => $event->event_description,
                    'created_at' => $event->created_at,
                    'actor' => $event->actor ? $this->userPayload($event->actor) : null,
                ])->values()
                : [],
        ];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ];
    }

    private function ensureActiveAccount(User $user): void
    {
        abort_unless($user->account_status === 'active', 403, 'An active account is required to manage a transaction.');
    }

    private function ensureParticipant(Transaction $transaction, User $user): void
    {
        abort_unless(
            $transaction->buyer_id === $user->id || $transaction->seller_id === $user->id,
            404,
            'This transaction was not found.',
        );
    }

    private function recordEvent(Transaction $transaction, User $actor, string $type, string $description): void
    {
        TransactionEvent::query()->create([
            'transaction_id' => $transaction->id,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'event_description' => $description,
            'created_at' => now(),
        ]);
        $listingTitle = $transaction->listing()->value('title');
        $this->notifyTransactionParticipants(
            $transaction,
            'Transaction update for '.$listingTitle.': '.$description,
            $actor->id,
        );
    }

    private function notifyTransactionParticipants(Transaction $transaction, string $message, ?int $excludeUserId = null): void
    {
        foreach ([$transaction->buyer_id, $transaction->seller_id] as $userId) {
            if ($userId === $excludeUserId) {
                continue;
            }

            Notification::query()->create([
                'user_id' => $userId,
                'notification_type' => 'transaction_update',
                'message' => $message,
                'action_url' => '/transactions/'.$transaction->id,
                'created_at' => now(),
            ]);
        }
    }

    private function recordConversationMessage(Transaction $transaction, User $sender, string $message): void
    {
        $conversation = $transaction->conversation()->firstOrFail();
        $conversation->messages()->create([
            'sender_id' => $sender->id,
            'message_text' => $message,
            'sent_at' => now(),
        ]);
        $conversation->touch();
    }
}
