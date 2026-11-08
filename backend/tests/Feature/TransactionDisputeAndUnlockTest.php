<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\PlatformSetting;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TransactionDisputeAndUnlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_buyer_can_accept_or_decline_a_seller_unlock_request(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $transactionId = $this->arrangeTransaction($buyer, $seller, $listing);

        PlatformSetting::query()
            ->where('setting_key', 'seller_unlock_response_hours')
            ->update(['setting_value' => '2']);

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/unlock-request')
            ->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged');
        $conversation = Conversation::query()->where('transaction_id', $transactionId)->firstOrFail();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $seller->id,
        ]);
        $transaction = Transaction::query()->findOrFail($transactionId);
        $this->assertSame(
            $transaction->seller_unlock_requested_at->copy()->addHours(2)->toDateTimeString(),
            $transaction->buyer_unlock_response_deadline->toDateTimeString(),
        );

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/unlock-response', [
            'decision' => 'decline',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'meetup_arranged')
            ->assertJsonPath('transaction.buyer_unlock_response_deadline', null);

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/unlock-request')->assertOk();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/unlock-response', [
            'decision' => 'accept',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'cancelled');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'available',
        ]);
    }

    public function test_unanswered_unlock_requests_expire_and_release_the_listing(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $transactionId = $this->arrangeTransaction($buyer, $seller, $listing);
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/unlock-request')->assertOk();

        $this->travel(25)->hours();
        Artisan::call('transactions:expire-unanswered-unlocks');

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'transaction_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'available',
        ]);
        $this->assertDatabaseHas('transaction_events', [
            'transaction_id' => $transactionId,
            'event_type' => 'seller_unlock_expired',
        ]);
        foreach ([$buyer, $seller] as $participant) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $participant->id,
                'notification_type' => 'transaction_update',
                'action_url' => '/transactions/'.$transactionId,
            ]);
        }
    }

    public function test_late_buyer_response_applies_expiry_without_waiting_for_the_scheduler(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $transactionId = $this->arrangeTransaction($buyer, $seller, $listing);
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/unlock-request')->assertOk();

        $this->travel(25)->hours();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/unlock-response', [
            'decision' => 'decline',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'cancelled');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'available',
        ]);
        $this->assertDatabaseHas('transaction_events', [
            'transaction_id' => $transactionId,
            'event_type' => 'seller_unlock_expired',
        ]);
    }

    public function test_seller_must_use_unlock_request_instead_of_cancelling_an_arranged_meetup(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $transactionId = $this->arrangeTransaction($buyer, $seller, $listing);

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/cancel', [
            'cancellation_reason' => 'Plans changed.',
        ])->assertConflict();

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'transaction_status' => 'meetup_arranged',
        ]);
    }

    public function test_participant_can_dispute_and_super_admin_can_resume_the_transaction(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $admin = $this->createSuperAdmin();
        $listing = $this->createListing($seller);
        $transactionId = $this->arrangeTransaction($buyer, $seller, $listing);

        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/dispute', [
            'reason' => 'Item does not match',
            'description' => 'The item condition is different from what we agreed on.',
        ])->assertOk()
            ->assertJsonPath('transaction.transaction_status', 'disputed')
            ->assertJsonPath('transaction.dispute.report_status', 'pending');

        $report = Report::query()->where('transaction_id', $transactionId)->firstOrFail();
        $this->assertSame($seller->id, $report->reported_user_id);
        $conversation = Conversation::query()->where('transaction_id', $transactionId)->firstOrFail();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $buyer->id,
            'message_text' => 'A participant reported a problem. The transaction is paused for Super Admin review.',
        ]);

        $this->actingAs($admin)->patchJson('/api/auth/admin/reports/'.$report->id, [
            'report_status' => 'resolved',
            'transaction_resolution' => 'resume',
            'resolution_notes' => 'The participants can continue the meetup arrangement.',
        ])->assertOk()->assertJsonPath('report.report_status', 'resolved');

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'transaction_status' => 'meetup_arranged',
        ]);
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'locked',
        ]);
        $this->assertDatabaseHas('transaction_events', [
            'transaction_id' => $transactionId,
            'event_type' => 'dispute_resumed',
        ]);
        foreach ([$buyer, $seller] as $participant) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $participant->id,
                'notification_type' => 'transaction_update',
                'action_url' => '/transactions/'.$transactionId,
            ]);
        }
    }

    public function test_super_admin_can_cancel_a_disputed_transaction_and_release_its_listing(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $admin = $this->createSuperAdmin();
        $listing = $this->createListing($seller);
        $transactionId = $this->arrangeTransaction($buyer, $seller, $listing);
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/dispute', [
            'reason' => 'Unable to complete meetup',
            'description' => 'The other participant did not attend the agreed meetup.',
        ])->assertOk();
        $report = Report::query()->where('transaction_id', $transactionId)->firstOrFail();

        $this->actingAs($admin)->patchJson('/api/auth/admin/reports/'.$report->id, [
            'report_status' => 'resolved',
            'transaction_resolution' => 'cancel',
            'resolution_notes' => 'The transaction will not continue.',
        ])->assertOk();

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'transaction_status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'available',
        ]);
        foreach ([$buyer, $seller] as $participant) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $participant->id,
                'notification_type' => 'transaction_update',
                'action_url' => '/transactions/'.$transactionId,
            ]);
        }
    }

    public function test_super_admin_can_update_the_unlock_response_window_within_limits(): void
    {
        $admin = $this->createSuperAdmin();

        $this->actingAs($admin)->getJson('/api/auth/admin/platform-settings')
            ->assertOk()
            ->assertJsonPath('platform_settings.seller_unlock_response_hours', 24);
        $this->patchJson('/api/auth/admin/platform-settings', [
            'seller_unlock_response_hours' => 48,
        ])->assertOk()
            ->assertJsonPath('platform_settings.seller_unlock_response_hours', 48);
        $this->patchJson('/api/auth/admin/platform-settings', [
            'seller_unlock_response_hours' => 0,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('seller_unlock_response_hours');
    }

    private function arrangeTransaction(User $buyer, User $seller, Listing $listing): int
    {
        $conversation = Conversation::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);
        $transactionId = $this->actingAs($buyer)
            ->postJson('/api/auth/conversations/'.$conversation->id.'/transactions')
            ->assertCreated()
            ->json('transaction.id');
        $meetup = [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ];
        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup)->assertOk();
        $this->actingAs($buyer)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', $meetup)->assertOk();

        return $transactionId;
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

    private function createSuperAdmin(): User
    {
        $user = User::factory()->create();
        Admin::query()->create([
            'user_id' => $user->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);

        return $user;
    }
}
