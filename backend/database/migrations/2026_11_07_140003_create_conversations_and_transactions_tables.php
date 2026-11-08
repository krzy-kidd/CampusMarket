<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['listing_id', 'buyer_id', 'seller_id']);
            $table->index(['buyer_id', 'updated_at']);
            $table->index(['seller_id', 'updated_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('message_text');
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('read_at')->nullable();

            $table->index(['conversation_id', 'sent_at']);
            $table->index(['sender_id', 'sent_at']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_listing_id')->nullable()->unique()->constrained('listings')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();
            $table->decimal('agreed_price', 10, 2)->unsigned();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->enum('transaction_status', ['pending_agreement', 'meetup_arranged', 'completed', 'cancelled', 'disputed'])->default('pending_agreement');
            $table->date('meetup_date')->nullable();
            $table->time('meetup_time')->nullable();
            $table->string('meetup_location', 255)->nullable();
            $table->timestamp('buyer_meetup_confirmed_at')->nullable();
            $table->timestamp('seller_meetup_confirmed_at')->nullable();
            $table->timestamp('buyer_completion_confirmed_at')->nullable();
            $table->timestamp('seller_completion_confirmed_at')->nullable();
            $table->timestamp('seller_unlock_requested_at')->nullable();
            $table->timestamp('buyer_unlock_response_deadline')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'transaction_status']);
            $table->index(['buyer_id', 'transaction_status', 'created_at']);
            $table->index(['seller_id', 'transaction_status', 'created_at']);
            $table->index(
                ['transaction_status', 'buyer_unlock_response_deadline'],
                'transactions_unlock_deadline_idx',
            );
        });

        Schema::create('transaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 100);
            $table->text('event_description');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['transaction_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_events');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
