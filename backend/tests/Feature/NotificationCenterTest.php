<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_students_can_page_and_manage_only_their_own_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notifications = [];

        for ($index = 1; $index <= 31; $index++) {
            $notifications[] = Notification::query()->create([
                'user_id' => $user->id,
                'notification_type' => 'transaction_update',
                'message' => 'Transaction update '.$index,
                'action_url' => '/transactions/'.$index,
                'created_at' => now()->addSeconds($index),
            ]);
        }
        $otherNotification = Notification::query()->create([
            'user_id' => $otherUser->id,
            'notification_type' => 'new_message',
            'message' => 'Private notification',
            'created_at' => now(),
        ]);

        $page = $this->actingAs($user)->getJson('/api/auth/notifications')
            ->assertOk()
            ->assertJsonCount(30, 'notifications')
            ->assertJsonPath('unread_count', 31)
            ->assertJsonPath('notifications.0.action_url', '/transactions/31');
        $cursor = $page->json('next_cursor');
        $this->getJson('/api/auth/notifications?cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.message', 'Transaction update 1');

        $this->patchJson('/api/auth/notifications/'.$notifications[0]->id.'/read')
            ->assertOk()
            ->assertJsonPath('notification.is_read', true)
            ->assertJsonPath('unread_count', 30);
        $readAt = $notifications[0]->fresh()->read_at;
        $this->patchJson('/api/auth/notifications/'.$notifications[0]->id.'/read')
            ->assertOk()
            ->assertJsonPath('unread_count', 30);
        $this->assertSame($readAt->toDateTimeString(), $notifications[0]->fresh()->read_at->toDateTimeString());

        $this->patchJson('/api/auth/notifications/'.$otherNotification->id.'/read')->assertNotFound();
        $this->deleteJson('/api/auth/notifications/'.$notifications[1]->id)
            ->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('unread_count', 29);
        $this->assertDatabaseMissing('notifications', ['id' => $notifications[1]->id]);
        $this->deleteJson('/api/auth/notifications/'.$otherNotification->id)->assertNotFound();
        $this->deleteJson('/api/auth/notifications/'.$notifications[0]->id)
            ->assertOk()
            ->assertJsonPath('unread_count', 29);
        $this->assertDatabaseMissing('notifications', ['id' => $notifications[0]->id]);
        $this->postJson('/api/auth/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('updated_count', 29)
            ->assertJsonPath('unread_count', 0);
        $this->assertSame(29, $user->campusNotifications()->where('is_read', true)->count());

        $suspendedUser = User::factory()->create(['account_status' => 'suspended']);
        $this->actingAs($suspendedUser)->getJson('/api/auth/notifications')->assertForbidden();
    }

    public function test_incoming_messages_and_transaction_updates_create_actionable_notifications(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
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

        $conversationId = $this->actingAs($buyer)->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => 'Is this available?',
        ])->assertCreated()->json('conversation_id');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $seller->id,
            'notification_type' => 'new_message',
            'action_url' => '/messages/'.$conversationId,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $buyer->id,
            'notification_type' => 'new_message',
        ]);

        $this->actingAs($seller)->postJson('/api/auth/conversations/'.$conversationId.'/messages', [
            'message_text' => 'Yes, it is available.',
        ])->assertCreated();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'notification_type' => 'new_message',
            'action_url' => '/messages/'.$conversationId,
        ]);

        $transactionId = $this->actingAs($buyer)
            ->postJson('/api/auth/conversations/'.$conversationId.'/transactions')
            ->assertCreated()
            ->json('transaction.id');
        $this->assertDatabaseHas('notifications', [
            'user_id' => $seller->id,
            'notification_type' => 'transaction_update',
            'action_url' => '/transactions/'.$transactionId,
        ]);

        $this->actingAs($seller)->postJson('/api/auth/transactions/'.$transactionId.'/meetup', [
            'meetup_date' => now()->addDay()->toDateString(),
            'meetup_time' => '12:00',
            'meetup_location' => 'Main library entrance',
        ])->assertOk();
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'notification_type' => 'transaction_update',
            'action_url' => '/transactions/'.$transactionId,
        ]);
    }

    public function test_navigation_summary_returns_compact_counts_for_the_signed_in_student(): void
    {
        $user = User::factory()->create();
        $seller = User::factory()->create();
        $otherUser = User::factory()->create();
        $category = Category::query()->create(['category_name' => 'Textbooks']);
        $listing = Listing::query()->create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Course textbook',
            'description' => 'Used textbook in good condition.',
            'price' => '250.00',
            'condition_rating' => 2,
        ]);
        $conversation = Conversation::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $user->id,
            'seller_id' => $seller->id,
        ]);
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $seller->id,
            'message_text' => 'Is this still available?',
        ]);
        Transaction::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $user->id,
            'seller_id' => $seller->id,
            'agreed_price' => $listing->price,
        ]);
        Listing::query()->create([
            'seller_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Available item',
            'description' => 'An available item.',
            'price' => '100.00',
            'condition_rating' => 2,
            'listing_status' => 'available',
        ]);
        Listing::query()->create([
            'seller_id' => $user->id,
            'category_id' => $category->id,
            'title' => 'Archived item',
            'description' => 'An archived item.',
            'price' => '100.00',
            'condition_rating' => 2,
            'listing_status' => 'archived',
        ]);
        Notification::query()->create([
            'user_id' => $user->id,
            'notification_type' => 'transaction_update',
            'message' => 'A transaction needs your attention.',
        ]);
        Notification::query()->create([
            'user_id' => $otherUser->id,
            'notification_type' => 'transaction_update',
            'message' => 'Another student update.',
        ]);

        $this->actingAs($user)->getJson('/api/auth/navigation-summary')
            ->assertOk()
            ->assertExactJson([
                'unread_notifications' => 1,
                'unread_messages' => 1,
                'active_transactions' => 1,
                'available_listings' => 1,
            ]);
    }
}
