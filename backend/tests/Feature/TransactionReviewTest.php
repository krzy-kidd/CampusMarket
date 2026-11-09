<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_both_participants_can_review_a_completed_transaction_once(): void
    {
        [$buyer, $seller, , $transactionId] = $this->completeTransaction();
        $publicListing = $this->createAvailableListing($seller);

        $this->getJson('/api/listings/'.$publicListing->id)
            ->assertOk()
            ->assertJsonPath('listing.seller.rating_average', null)
            ->assertJsonPath('listing.seller.rating_count', 0);

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 5,
            'comment' => '  Great communication and smooth meetup.  ',
        ])->assertCreated()
            ->assertJsonPath('transaction.reviews.0.rating', 5)
            ->assertJsonPath('transaction.reviews.0.comment', 'Great communication and smooth meetup.');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $seller->id,
            'notification_type' => 'new_review',
            'action_url' => '/transactions/'.$transactionId,
        ]);

        $this->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 3,
        ])->assertConflict();

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 4,
        ])->assertCreated()
            ->assertJsonCount(2, 'transaction.reviews');

        $this->actingAs($buyer)->getJson('/api/auth/transactions/'.$transactionId)
            ->assertOk()
            ->assertJsonCount(2, 'transaction.reviews');

        $this->getJson('/api/listings/'.$publicListing->id)
            ->assertOk()
            ->assertJsonPath('listing.seller.rating_average', 5)
            ->assertJsonPath('listing.seller.rating_count', 1);

        [$secondBuyer, , , $secondTransactionId] = $this->completeTransaction(seller: $seller);
        $this->actingAs($secondBuyer)->postJson('/api/auth/transactions/'.$secondTransactionId.'/reviews', [
            'rating' => 4,
        ])->assertCreated();
        $this->getJson('/api/listings/'.$publicListing->id)
            ->assertOk()
            ->assertJsonPath('listing.seller.rating_average', 4.5)
            ->assertJsonPath('listing.seller.rating_count', 2);
        $this->assertDatabaseCount('reviews', 3);
    }

    public function test_reviews_are_only_allowed_for_completed_transactions_and_participants(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $otherUser = User::factory()->create();
        [$conversation, $transactionId] = $this->startTransaction($buyer, $seller);

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 5,
        ])->assertConflict();

        $this->actingAs($otherUser)->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 5,
        ])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 5,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_rating_and_comment_validation_is_enforced(): void
    {
        [$buyer, , , $transactionId] = $this->completeTransaction();

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 6,
            'comment' => str_repeat('a', 1001),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['rating', 'comment']);

        $this->postJson('/api/auth/transactions/'.$transactionId.'/reviews', [
            'rating' => 1,
        ])->assertCreated()
            ->assertJsonPath('transaction.reviews.0.comment', null);
    }

    private function completeTransaction(?User $buyer = null, ?User $seller = null): array
    {
        $buyer ??= User::factory()->create();
        $seller ??= User::factory()->create();
        [$conversation, $transactionId, $listing] = $this->startTransaction($buyer, $seller);

        $meetup = [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ];
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup)->assertOk();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup)->assertOk();
        $this->travel(2)->days();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/complete')
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged');
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/complete')
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'completed');

        return [$buyer, $seller, $listing, $transactionId];
    }

    private function startTransaction(User $buyer, User $seller): array
    {
        $category = Category::query()->create(['category_name' => 'Textbooks']);
        $listing = Listing::query()->create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Course textbook',
            'description' => 'Used textbook in good condition.',
            'price' => '250.00',
            'condition_rating' => 2,
            'listing_status' => 'available',
        ]);
        $conversation = Conversation::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);
        $transactionId = $this->actingAs($buyer)
            ->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')
            ->assertCreated()
            ->json('transaction.id');

        return [$conversation, $transactionId, $listing];
    }

    private function createAvailableListing(User $seller): Listing
    {
        $category = Category::query()->create(['category_name' => 'School supplies']);

        return Listing::query()->create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Notebook',
            'description' => 'New notebook.',
            'price' => '50.00',
            'condition_rating' => 1,
            'listing_status' => 'available',
        ]);
    }
}
