<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionCoordinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_buyer_can_request_a_fixed_price_purchase_once_per_active_listing(): void
    {
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);

        $response = $this->actingAs($buyer)->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')
            ->assertCreated()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.agreed_price', '250.00')
            ->assertJsonPath('transaction.current_user_role', 'buyer');

        $transactionId = $response->json('transaction.id');
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'pending_agreement',
        ]);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'transaction_id' => $transactionId,
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $buyer->id,
            'message_text' => 'Purchase request started for PHP 250.00. Please confirm meetup details in the transaction.',
        ]);

        $this->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')
            ->assertOk()
            ->assertJsonPath('transaction.id', $transactionId);

        $otherConversation = $this->createConversation($otherBuyer, $seller, $listing);
        $this->actingAs($otherBuyer)->postJson('/api/auth/conversations/'.$otherConversation->id.'/transactions')
            ->assertStatus(409);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_transaction_index_includes_count_of_active_transactions(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $activeListing = $this->createListing($seller);
        $activeConversation = $this->createConversation($buyer, $seller, $activeListing);
        $this->startTransaction($buyer, $activeConversation);

        foreach (['completed', 'cancelled'] as $status) {
            $listing = $this->createListing($seller);
            Transaction::query()->create([
                'listing_id' => $listing->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'agreed_price' => $listing->price,
                'transaction_status' => $status,
            ]);
        }

        $this->actingAs($buyer)->getJson('/api/auth/transactions')
            ->assertOk()
            ->assertJsonPath('active_count', 1);
        $this->actingAs($seller)->getJson('/api/auth/transactions')
            ->assertOk()
            ->assertJsonPath('active_count', 1);
    }

    public function test_completed_transaction_can_be_hidden_from_only_the_requesting_participants_history(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);
        $transactionId = $this->startTransaction($buyer, $conversation);
        $meetup = [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ];

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup);
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup);
        $this->travel(2)->days();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/complete');
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/complete')
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'completed');

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/hide-from-history')
            ->assertOk()
            ->assertJsonPath('hidden', true);
        $this->assertDatabaseHas('transaction_history_hides', [
            'user_id' => $buyer->id,
            'transaction_id' => $transactionId,
        ]);
        $this->assertDatabaseMissing('transaction_history_hides', [
            'user_id' => $seller->id,
            'transaction_id' => $transactionId,
        ]);

        $this->actingAs($buyer)->getJson('/api/auth/transactions')
            ->assertOk()
            ->assertJsonCount(0, 'transactions');
        $this->actingAs($seller)->getJson('/api/auth/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'transactions');

        $activeListing = $this->createListing($seller);
        $activeConversation = $this->createConversation($buyer, $seller, $activeListing);
        $activeTransactionId = $this->startTransaction($buyer, $activeConversation);
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$activeTransactionId.'/hide-from-history')
            ->assertConflict()
            ->assertJsonPath('message', 'Only completed transactions can be removed from your history.');

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)->postJson('/api/auth/transactions/'.$transactionId.'/hide-from-history')
            ->assertNotFound();
    }

    public function test_buyer_can_start_a_new_purchase_request_after_cancellation(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);
        $firstTransactionId = $this->startTransaction($buyer, $conversation);

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$firstTransactionId.'/cancel', [
            'cancellation_reason' => 'Plans changed.',
        ])->assertOk();
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$firstTransactionId.'/cancel-response', [
            'decision' => 'accept',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'cancelled');

        $newTransaction = $this->actingAs($buyer)
            ->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')
            ->assertCreated()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->json('transaction');

        $this->assertNotSame($firstTransactionId, $newTransaction['id']);
        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'transaction_id' => $newTransaction['id'],
        ]);
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'pending_agreement',
        ]);
    }

    public function test_buyer_can_start_a_purchase_directly_from_a_listing(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);

        $this->actingAs($seller)
            ->postJson('/api/auth/listings/'.$listing->id.'/purchase')
            ->assertForbidden();
        $this->assertDatabaseCount('transactions', 0);

        $response = $this->actingAs($buyer)
            ->postJson('/api/auth/listings/'.$listing->id.'/purchase')
            ->assertCreated()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.agreed_price', '250.00');
        $transactionId = $response->json('transaction.id');
        $conversation = Conversation::query()->where('transaction_id', $transactionId)->firstOrFail();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $buyer->id,
            'message_text' => 'Purchase request started for PHP 250.00. Please confirm meetup details in the transaction.',
        ]);
        $this->actingAs($buyer)
            ->postJson('/api/auth/listings/'.$listing->id.'/purchase')
            ->assertOk()
            ->assertJsonPath('transaction.id', $transactionId);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_participant_can_decline_a_cancellation_request(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);
        $transactionId = $this->startTransaction($buyer, $conversation);

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/cancel', [
            'cancellation_reason' => 'Plans changed.',
        ])->assertOk();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/cancel-response', [
            'decision' => 'decline',
        ])->assertConflict();
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/cancel-response', [
            'decision' => 'decline',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.cancellation_requested_at', null)
            ->assertJsonPath('transaction.cancellation_request_reason', null);

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'pending_agreement',
        ]);
    }

    public function test_both_participants_must_confirm_the_same_future_meetup_before_handoff(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);
        $transactionId = $this->startTransaction($buyer, $conversation);
        $meetup = [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ];

        $sellerProposal = $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.buyer_meetup_confirmed_at', null);
        $this->assertNotNull($sellerProposal->json('transaction.seller_meetup_confirmed_at'));

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'pending_agreement',
        ]);

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged')
            ->assertJsonPath('transaction.meetup_location', 'Main library entrance');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'locked',
        ]);
    }

    public function test_changing_meetup_details_clears_the_other_participants_old_confirmation(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);
        $transactionId = $this->startTransaction($buyer, $conversation);
        $original = [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ];
        $changed = [...$original, 'meetup_time' => '13:00'];

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $original)->assertOk();
        $sellerProposal = $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $changed)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.buyer_meetup_confirmed_at', null);
        $this->assertNotNull($sellerProposal->json('transaction.seller_meetup_confirmed_at'));

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $changed)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged');
    }

    public function test_either_participant_can_request_a_reschedule_and_both_must_reconfirm_it(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);
        $transactionId = $this->startTransaction($buyer, $conversation);
        $original = [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ];
        $rescheduled = [
            ...$original,
            'meetup_date' => now()->addDays(3)->toDateString(),
            'meetup_location' => 'Student center entrance',
        ];

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $original)->assertOk();
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $original)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged');

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $original)
            ->assertConflict()
            ->assertJsonPath('message', 'Change at least one meetup detail to request a reschedule.');

        $rescheduleResponse = $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $rescheduled)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.seller_meetup_confirmed_at', null)
            ->assertJsonPath('transaction.meetup_location', 'Student center entrance');
        $this->assertNotNull($rescheduleResponse->json('transaction.buyer_meetup_confirmed_at'));

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'pending_agreement',
        ]);

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $rescheduled)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged')
            ->assertJsonPath('transaction.meetup_location', 'Student center entrance');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'locked',
        ]);

        $sellerReschedule = [
            ...$rescheduled,
            'meetup_date' => now()->addDays(5)->toDateString(),
        ];
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $sellerReschedule)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.buyer_meetup_confirmed_at', null);
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $sellerReschedule)
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged');
    }

    public function test_both_participants_must_confirm_completion_and_cancellation_releases_the_listing(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);
        $transactionId = $this->startTransaction($buyer, $conversation);
        $meetup = [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ];

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup);
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup);
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/complete')
            ->assertConflict()
            ->assertJsonPath('message', 'Handoff confirmation is available at or after the scheduled meetup time.');
        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'transaction_status' => 'meetup_arranged',
            'buyer_completion_confirmed_at' => null,
        ]);

        $this->travel(2)->days();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/complete')
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged');
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', [
            'meetup_date' => now()->addDays(3)->toDateString(),
            'meetup_time' => '13:00',
            'meetup_location' => 'Student center entrance',
        ])->assertConflict()
            ->assertJsonPath('message', 'A handoff has already been confirmed. Report a problem instead of rescheduling.');
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/complete')
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'completed');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'sold',
        ]);
        $this->assertNull(Transaction::query()->findOrFail($transactionId)->active_listing_id);
        $marketplaceResponse = $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('listings.0.listing_status', 'sold');
        $this->assertNotNull($marketplaceResponse->json('listings.0.sold_until'));
        $this->getJson('/api/listings/'.$listing->id)
            ->assertOk()
            ->assertJsonPath('listing.listing_status', 'sold');
        $otherBuyer = User::factory()->create();
        $this->actingAs($otherBuyer)->postJson('/api/auth/listings/'.$listing->id.'/purchase')
            ->assertConflict();

        $this->travel(9)->minutes();
        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1);
        $this->travel(2)->minutes();
        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonPath('pagination.total', 0);
        $this->getJson('/api/listings/'.$listing->id)->assertNotFound();
        $this->actingAs($seller)->getJson('/api/auth/listings')
            ->assertOk()
            ->assertJsonPath('listings.0.listing_status', 'sold');

        $secondListing = $this->createListing($seller);
        $secondConversation = $this->createConversation($buyer, $seller, $secondListing);
        $secondTransactionId = $this->startTransaction($buyer, $secondConversation);
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$secondTransactionId.'/cancel', [
            'cancellation_reason' => 'Plans changed.',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'pending_agreement')
            ->assertJsonPath('transaction.cancellation_requested_by', $buyer->id)
            ->assertJsonPath('transaction.cancellation_request_reason', 'Plans changed.');
        $this->assertDatabaseHas('listings', [
            'id' => $secondListing->id,
            'listing_status' => 'pending_agreement',
        ]);

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$secondTransactionId.'/cancel-response', [
            'decision' => 'accept',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'cancelled')
            ->assertJsonPath('transaction.cancellation_reason', 'Plans changed.')
            ->assertJsonPath('transaction.cancellation_requested_at', null);
        $this->assertDatabaseHas('listings', [
            'id' => $secondListing->id,
            'listing_status' => 'available',
        ]);
    }

    public function test_only_active_participants_can_manage_transactions_and_meetups_must_be_future(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $otherUser = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = $this->createConversation($buyer, $seller, $listing);

        $this->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')->assertUnauthorized();
        $transactionId = $this->startTransaction($buyer, $conversation);
        $this->actingAs($seller)->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')
            ->assertForbidden();
        $this->actingAs($otherUser)->getJson('/api/auth/transactions/'.$transactionId)->assertNotFound();

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', [
            'meetup_date' => now()->subDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('meetup_date');

        $inactiveUser = User::factory()->create(['account_status' => 'suspended']);
        $this->actingAs($inactiveUser)->getJson('/api/auth/transactions/'.$transactionId)->assertForbidden();
    }

    private function startTransaction(User $buyer, Conversation $conversation): int
    {
        return $this->actingAs($buyer)
            ->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')
            ->assertCreated()
            ->json('transaction.id');
    }

    private function createConversation(User $buyer, User $seller, Listing $listing): Conversation
    {
        return Conversation::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);
    }

    private function createListing(User $seller): Listing
    {
        $category = Category::query()->create(['category_name' => 'Textbooks']);

        return Listing::query()->create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Course textbook',
            'description' => 'Used textbook in good condition.',
            'price' => '250.00',
            'condition_rating' => 2,
            'listing_status' => 'available',
        ]);
    }
}
