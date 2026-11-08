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
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged');
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/complete')
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'completed');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'sold',
        ]);
        $this->assertNull(Transaction::query()->findOrFail($transactionId)->active_listing_id);

        $secondListing = $this->createListing($seller);
        $secondConversation = $this->createConversation($buyer, $seller, $secondListing);
        $secondTransactionId = $this->startTransaction($buyer, $secondConversation);
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$secondTransactionId.'/cancel', [
            'cancellation_reason' => 'Plans changed.',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'cancelled')
            ->assertJsonPath('transaction.cancellation_reason', 'Plans changed.');
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
