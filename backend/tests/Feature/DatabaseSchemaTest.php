<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\Message;
use App\Models\PlatformSetting;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_years_cannot_overlap_or_have_multiple_current_years(): void
    {
        AcademicYear::create([
            'academic_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
            'is_current' => true,
        ]);

        try {
            AcademicYear::create([
                'academic_year' => '2027-2028',
                'start_date' => '2027-07-01',
                'end_date' => '2028-06-30',
                'is_current' => false,
            ]);

            $this->fail('Overlapping academic years must be rejected by the database.');
        } catch (QueryException) {
            $this->assertDatabaseCount('academic_years', 1);
        }

        try {
            AcademicYear::create([
                'academic_year' => '2027-2028',
                'start_date' => '2027-08-01',
                'end_date' => '2028-07-31',
                'is_current' => true,
            ]);

            $this->fail('Only one academic year may be current.');
        } catch (QueryException) {
            $this->assertDatabaseCount('academic_years', 1);
        }
    }

    public function test_academic_year_updates_cannot_create_overlaps(): void
    {
        AcademicYear::create([
            'academic_year' => '2025-2026',
            'start_date' => '2025-08-01',
            'end_date' => '2026-07-31',
            'is_current' => false,
        ]);
        $year = AcademicYear::create([
            'academic_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
            'is_current' => true,
        ]);

        $this->expectException(QueryException::class);

        $year->update(['start_date' => '2026-07-01']);
    }

    public function test_only_one_verification_record_exists_per_student_and_year(): void
    {
        $user = User::factory()->create();
        $academicYear = AcademicYear::create([
            'academic_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
            'is_current' => true,
        ]);

        Verification::create([
            'user_id' => $user->id,
            'academic_year_id' => $academicYear->id,
            'cor_file_path' => 'cor/private/randomized-file.pdf',
            'submission_status' => 'pending',
            'submitted_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        Verification::create([
            'user_id' => $user->id,
            'academic_year_id' => $academicYear->id,
            'cor_file_path' => 'cor/private/another-file.pdf',
            'submission_status' => 'pending',
            'submitted_at' => now(),
        ]);
    }

    public function test_only_one_active_transaction_can_claim_a_listing_and_cancellation_releases_it(): void
    {
        $seller = User::factory()->create();
        $firstBuyer = User::factory()->create();
        $secondBuyer = User::factory()->create();
        $category = Category::create(['category_name' => 'Textbooks']);
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Course textbook',
            'description' => 'Used textbook in good condition.',
            'price' => '250.00',
            'condition_rating' => 2,
            'listing_status' => 'pending_agreement',
        ]);

        $firstTransaction = Transaction::create([
            'listing_id' => $listing->id,
            'buyer_id' => $firstBuyer->id,
            'seller_id' => $seller->id,
            'agreed_price' => $listing->price,
            'transaction_status' => 'pending_agreement',
        ]);

        $this->assertSame($listing->id, $firstTransaction->active_listing_id);

        try {
            Transaction::create([
                'listing_id' => $listing->id,
                'buyer_id' => $secondBuyer->id,
                'seller_id' => $seller->id,
                'agreed_price' => $listing->price,
                'transaction_status' => 'pending_agreement',
            ]);

            $this->fail('A second active transaction must not claim the same listing.');
        } catch (QueryException) {
            $this->assertDatabaseCount('transactions', 1);
        }

        TransactionEvent::create([
            'transaction_id' => $firstTransaction->id,
            'actor_id' => $firstBuyer->id,
            'event_type' => 'cancellation_requested',
            'event_description' => 'The buyer requested cancellation.',
        ]);

        $firstTransaction->update([
            'transaction_status' => 'cancelled',
            'cancellation_reason' => 'The buyer and seller agreed to cancel.',
        ]);

        $secondTransaction = Transaction::create([
            'listing_id' => $listing->id,
            'buyer_id' => $secondBuyer->id,
            'seller_id' => $seller->id,
            'agreed_price' => $listing->price,
            'transaction_status' => 'pending_agreement',
        ]);

        $this->assertNull($firstTransaction->fresh()->active_listing_id);
        $this->assertSame($listing->id, $secondTransaction->active_listing_id);
        $this->assertDatabaseHas('transaction_events', [
            'transaction_id' => $firstTransaction->id,
            'event_type' => 'cancellation_requested',
        ]);
    }

    public function test_duplicate_conversations_for_the_same_participants_and_listing_are_rejected(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $category = Category::create(['category_name' => 'Electronics']);
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Keyboard',
            'description' => 'Mechanical keyboard.',
            'price' => '500.00',
            'condition_rating' => 1,
        ]);

        Conversation::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);

        $this->expectException(QueryException::class);

        Conversation::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);

    }

    public function test_only_one_active_super_admin_is_allowed(): void
    {
        Admin::create([
            'user_id' => User::factory()->create()->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        Admin::create([
            'user_id' => User::factory()->create()->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);
    }

    public function test_messages_are_limited_to_conversation_participants(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $outsider = User::factory()->create();
        $category = Category::create(['category_name' => 'Dormitory essentials']);
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Desk lamp',
            'description' => 'LED desk lamp.',
            'price' => '200.00',
            'condition_rating' => 1,
        ]);
        $conversation = Conversation::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $buyer->id,
            'message_text' => 'Is this available?',
        ]);

        $this->expectException(QueryException::class);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $outsider->id,
            'message_text' => 'I am not part of this conversation.',
        ]);
    }

    public function test_database_rejects_transactions_without_a_matching_active_listing_claim(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $category = Category::create(['category_name' => 'Clothing']);
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Jacket',
            'description' => 'Warm jacket.',
            'price' => '400.00',
            'condition_rating' => 2,
        ]);

        $this->expectException(QueryException::class);

        DB::table('transactions')->insert([
            'listing_id' => $listing->id,
            'active_listing_id' => null,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'agreed_price' => '400.00',
            'transaction_status' => 'pending_agreement',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_transaction_state_requires_both_meetup_and_completion_confirmations(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $category = Category::create(['category_name' => 'Electronics']);
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Headphones',
            'description' => 'Over-ear headphones.',
            'price' => '600.00',
            'condition_rating' => 1,
        ]);
        $transaction = Transaction::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'agreed_price' => $listing->price,
            'transaction_status' => 'pending_agreement',
        ]);

        try {
            $transaction->update(['transaction_status' => 'completed']);
            $this->fail('A transaction cannot skip meetup agreement and completion confirmations.');
        } catch (QueryException) {
            $this->assertSame('pending_agreement', $transaction->fresh()->transaction_status);
        }

        $meetup = now();
        $transaction->fresh()->update([
            'transaction_status' => 'meetup_arranged',
            'meetup_date' => $meetup->toDateString(),
            'meetup_time' => $meetup->format('H:i:s'),
            'meetup_location' => 'WMSU Main Gate',
            'buyer_meetup_confirmed_at' => $meetup,
            'seller_meetup_confirmed_at' => $meetup,
        ]);

        try {
            $transaction->fresh()->update([
                'transaction_status' => 'completed',
                'buyer_completion_confirmed_at' => $meetup,
                'completed_at' => $meetup,
            ]);
            $this->fail('Completion requires confirmation from both participants.');
        } catch (QueryException) {
            $this->assertSame('meetup_arranged', $transaction->fresh()->transaction_status);
        }

        $transaction->fresh()->update([
            'transaction_status' => 'completed',
            'buyer_completion_confirmed_at' => $meetup,
            'seller_completion_confirmed_at' => $meetup,
            'completed_at' => $meetup,
        ]);

        $this->assertNull($transaction->fresh()->active_listing_id);
    }

    public function test_default_unlock_response_period_is_configurable_in_platform_settings(): void
    {
        $setting = PlatformSetting::where('setting_key', 'seller_unlock_response_hours')->firstOrFail();

        $this->assertSame('24', $setting->setting_value);
    }

    public function test_listings_require_positive_prices_and_valid_condition_ratings(): void
    {
        $seller = User::factory()->create();
        $category = Category::create(['category_name' => 'Books']);
        $listing = [
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Test book',
            'description' => 'Temporary test listing.',
            'price' => '10.00',
            'condition_rating' => 1,
        ];

        foreach ([
            ['price' => '0.00'],
            ['condition_rating' => 5],
        ] as $invalidValues) {
            try {
                Listing::create(array_merge($listing, $invalidValues));
                $this->fail('Invalid listing prices and condition ratings must be rejected.');
            } catch (QueryException) {
                $this->assertDatabaseCount('listings', 0);
            }
        }
    }

    public function test_review_ratings_must_be_between_one_and_five(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $category = Category::create(['category_name' => 'Accessories']);
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Test bag',
            'description' => 'Temporary test listing.',
            'price' => '10.00',
            'condition_rating' => 1,
        ]);
        $transaction = Transaction::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'agreed_price' => $listing->price,
        ]);

        $this->expectException(QueryException::class);

        $transaction->reviews()->create([
            'reviewer_id' => $buyer->id,
            'reviewed_user_id' => $seller->id,
            'rating' => 6,
        ]);
    }

    public function test_historical_transaction_dependencies_prevent_listing_deletion(): void
    {
        $seller = User::factory()->create();
        $buyer = User::factory()->create();
        $category = Category::create(['category_name' => 'School supplies']);
        $listing = Listing::create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'title' => 'Calculator',
            'description' => 'Scientific calculator.',
            'price' => '300.00',
            'condition_rating' => 2,
        ]);

        Transaction::create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'agreed_price' => $listing->price,
            'transaction_status' => 'pending_agreement',
        ]);

        $this->expectException(QueryException::class);

        $listing->delete();
    }
}
