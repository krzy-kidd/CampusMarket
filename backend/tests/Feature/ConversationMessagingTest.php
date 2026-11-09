<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_buyer_can_start_a_private_conversation_and_seller_can_read_it(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);

        $response = $this->actingAs($buyer)->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => '  Is this textbook still available?  ',
        ])->assertCreated()
            ->assertJsonPath('message.message_text', 'Is this textbook still available?');

        $conversationId = $response->json('conversation_id');
        $this->assertDatabaseHas('conversations', [
            'id' => $conversationId,
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);

        $this->actingAs($seller)->getJson('/api/auth/conversations')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('conversations.0.participant.id', $buyer->id)
            ->assertJsonPath('conversations.0.last_message.message_text', 'Is this textbook still available?')
            ->assertJsonPath('conversations.0.unread_count', 1);

        $this->actingAs($seller)->getJson('/api/auth/conversations/'.$conversationId)
            ->assertOk()
            ->assertJsonPath('conversation.participant.id', $buyer->id)
            ->assertJsonPath('messages.0.message_text', 'Is this textbook still available?');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'sender_id' => $buyer->id,
            'message_text' => 'Is this textbook still available?',
        ]);
        $this->assertNotNull(Message::query()->where('conversation_id', $conversationId)->value('read_at'));

        $this->actingAs($seller)->postJson('/api/auth/conversations/'.$conversationId.'/messages', [
            'message_text' => 'Yes, it is still available.',
        ])->assertCreated()
            ->assertJsonPath('message.sender_id', $seller->id);

        $this->actingAs($buyer)->getJson('/api/auth/conversations')
            ->assertJsonPath('conversations.0.unread_count', 1);
        $this->actingAs($buyer)->getJson('/api/auth/conversations/'.$conversationId)
            ->assertJsonPath('messages.1.message_text', 'Yes, it is still available.');
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversationId,
            'sender_id' => $seller->id,
            'message_text' => 'Yes, it is still available.',
        ]);
    }

    public function test_repeat_contact_reuses_the_listing_conversation(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);

        $first = $this->actingAs($buyer)->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => 'First message',
        ])->assertCreated()->json('conversation_id');

        $second = $this->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => 'Follow-up message',
        ])->assertOk()->json('conversation_id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_messaging_requires_an_active_participant_and_an_available_other_users_listing(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = Conversation::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);
        $otherUser = User::factory()->create();

        $this->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => '  ',
        ])->assertUnauthorized();

        $this->actingAs($buyer)->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => '  ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('message_text');

        $this->actingAs($otherUser)->getJson('/api/auth/conversations/'.$conversation->id)
            ->assertNotFound();

        $this->actingAs($seller)->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => 'I want to buy my own item.',
        ])->assertForbidden();

        $listing->update(['listing_status' => 'archived']);
        $this->actingAs($buyer)->postJson('/api/auth/conversations', [
            'listing_id' => $listing->id,
            'message_text' => 'Is this still available?',
        ])->assertStatus(409);

        $inactiveBuyer = User::factory()->create(['account_status' => 'suspended']);
        $availableListing = $this->createListing($seller);
        $this->actingAs($inactiveBuyer)->postJson('/api/auth/conversations', [
            'listing_id' => $availableListing->id,
            'message_text' => 'Hello',
        ])->assertForbidden();
    }

    public function test_conversation_messages_are_cursor_paginated(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);
        $conversation = Conversation::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);

        for ($index = 1; $index <= 51; $index++) {
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_id' => $seller->id,
                'message_text' => 'Message '.$index,
                'sent_at' => now()->addSeconds($index),
            ]);
        }

        $page = $this->actingAs($buyer)->getJson('/api/auth/conversations/'.$conversation->id)
            ->assertOk()
            ->assertJsonCount(50, 'messages')
            ->assertJsonPath('messages.0.message_text', 'Message 2')
            ->assertJsonPath('messages.49.message_text', 'Message 51');

        $cursor = $page->json('next_cursor');
        $this->getJson('/api/auth/conversations/'.$conversation->id.'/messages?cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.message_text', 'Message 1');
    }

    public function test_inbox_conversations_are_cursor_paginated(): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();

        for ($index = 1; $index <= 31; $index++) {
            $listing = $this->createListing($seller);
            $conversation = Conversation::query()->create([
                'listing_id' => $listing->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
            ]);
            $conversation->forceFill([
                'updated_at' => now()->addSeconds($index),
            ])->save();
        }
        Message::query()->create([
            'conversation_id' => Conversation::query()->orderBy('updated_at')->value('id'),
            'sender_id' => $seller->id,
            'message_text' => 'Unread message on an older conversation',
            'sent_at' => now(),
        ]);

        $page = $this->actingAs($buyer)->getJson('/api/auth/conversations')
            ->assertOk()
            ->assertJsonCount(30, 'conversations')
            ->assertJsonPath('unread_count', 1);

        $cursor = $page->json('next_cursor');
        $this->getJson('/api/auth/conversations?cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('unread_count', 1);
    }

    private function createListing(User $seller): Listing
    {
        $category = Category::query()->create(['category_name' => 'Textbooks']);

        return Listing::query()->create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Used textbook',
            'description' => 'A textbook in good condition.',
            'price' => '125.00',
            'condition_rating' => 2,
            'listing_status' => 'available',
        ]);
    }
}
