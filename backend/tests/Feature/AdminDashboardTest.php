<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AcademicYear;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Report;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_verification_admin_can_access_review_dashboard_but_not_super_admin_tools(): void
    {
        $admin = $this->createAdmin('verification_admin');

        $this->actingAs($admin)->getJson('/api/auth/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('role', 'verification_admin')
            ->assertJsonMissingPath('total_students')
            ->assertJsonMissingPath('monthly_analytics');

        $this->getJson('/api/auth/admin/verifications')->assertOk();
        $this->getJson('/api/auth/admin/students')->assertForbidden();
        $this->getJson('/api/auth/admin/academic-years')->assertForbidden();
        $this->getJson('/api/auth/admin/categories')->assertForbidden();
        $this->getJson('/api/auth/admin/admins')->assertForbidden();
        $this->getJson('/api/auth/admin/reports')->assertForbidden();
        $this->getJson('/api/auth/admin/platform-settings')->assertForbidden();
        $this->patchJson('/api/auth/admin/platform-settings', [
            'seller_unlock_response_hours' => 48,
        ])->assertForbidden();
        $this->getJson('/api/auth/admin/audit-logs')->assertForbidden();
    }

    public function test_verification_admin_dashboard_shows_only_their_cor_review_totals(): void
    {
        $admin = $this->createAdmin('verification_admin');
        $otherAdmin = $this->createAdmin('verification_admin');
        $academicYear = AcademicYear::query()->create([
            'academic_year' => '2026-2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_current' => true,
        ]);

        $createAttempt = function (string $status, ?User $reviewer) use ($academicYear): void {
            $student = User::factory()->create();
            $verification = Verification::query()->create([
                'user_id' => $student->id,
                'academic_year_id' => $academicYear->id,
                'cor_file_path' => 'cor/'.$student->id.'/document.pdf',
                'submission_status' => $status,
                'submitted_at' => now(),
                'reviewed_at' => $reviewer ? now() : null,
                'reviewed_by' => $reviewer?->id,
            ]);

            VerificationAttempt::query()->create([
                'verification_id' => $verification->id,
                'user_id' => $student->id,
                'academic_year_id' => $academicYear->id,
                'attempt_number' => 1,
                'cor_file_path' => 'cor/'.$student->id.'/document.pdf',
                'submission_status' => $status,
                'submitted_at' => now(),
                'reviewed_at' => $reviewer ? now() : null,
                'reviewed_by' => $reviewer?->id,
            ]);
        };

        $createAttempt('approved', $admin);
        $createAttempt('rejected', $otherAdmin);
        $createAttempt('pending', null);

        $this->actingAs($admin)->getJson('/api/auth/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('pending_verifications', 1)
            ->assertJsonPath('reviews_by_admin', 1)
            ->assertJsonPath('approvals_by_admin', 1)
            ->assertJsonPath('rejections_by_admin', 0)
            ->assertJsonMissingPath('total_students');
    }

    public function test_super_admin_dashboard_includes_six_month_student_and_listing_analytics(): void
    {
        $admin = $this->createAdmin('super_admin');
        $student = User::factory()->create(['created_at' => now()->startOfMonth()]);
        $category = Category::query()->create([
            'category_name' => 'Books',
            'description' => 'Books and textbooks',
        ]);
        Listing::query()->create([
            'seller_id' => $student->id,
            'category_id' => $category->id,
            'title' => 'Calculus textbook',
            'description' => 'Used textbook',
            'price' => 100,
            'condition_rating' => 4,
            'created_at' => now()->startOfMonth(),
        ]);

        $this->actingAs($admin)->getJson('/api/auth/admin/dashboard')
            ->assertOk()
            ->assertJsonCount(6, 'monthly_analytics')
            ->assertJsonPath('monthly_analytics.5.students', 1)
            ->assertJsonPath('monthly_analytics.5.listings', 1);
    }

    public function test_admin_accounts_cannot_use_student_cor_verification_endpoints(): void
    {
        $admin = $this->createAdmin('verification_admin');

        $this->actingAs($admin)->getJson('/api/auth/verification/status')->assertForbidden();
        $this->getJson('/api/auth/verification/history')->assertForbidden();
        $this->postJson('/api/auth/verification/cor')->assertForbidden();
        $this->getJson('/api/auth/listings')->assertForbidden();
        $this->postJson('/api/auth/listings')->assertForbidden();
        $this->postJson('/api/auth/reports')->assertForbidden();
        $this->getJson('/api/auth/conversations')->assertForbidden();
        $this->postJson('/api/auth/conversations')->assertForbidden();
        $this->getJson('/api/auth/notifications')->assertForbidden();
        $this->getJson('/api/auth/transactions')->assertForbidden();
    }

    public function test_super_admin_can_manage_academic_years_and_categories_with_audit_logs(): void
    {
        $admin = $this->createAdmin('super_admin');

        $this->actingAs($admin)->postJson('/api/auth/admin/academic-years', [
            'academic_year' => '2025-2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_current' => true,
        ])->assertCreated();
        $this->postJson('/api/auth/admin/academic-years', [
            'academic_year' => '2025-2026-overlap',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');

        $this->actingAs($admin)->postJson('/api/auth/admin/academic-years', [
            'academic_year' => '2026-2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_current' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('academic_years', ['academic_year' => '2025-2026', 'is_current' => 0]);
        $this->assertDatabaseHas('academic_years', ['academic_year' => '2026-2027', 'is_current' => 1]);

        $root = $this->actingAs($admin)->postJson('/api/auth/admin/categories', [
            'category_name' => 'Books',
            'description' => 'Textbooks and other books',
        ])->assertCreated()->json('category');
        $this->postJson('/api/auth/admin/categories', [
            'category_name' => 'Books',
        ])->assertUnprocessable();

        $child = $this->postJson('/api/auth/admin/categories', [
            'parent_category_id' => $root['id'],
            'category_name' => 'Engineering',
        ])->assertCreated()->json('category');

        $this->postJson('/api/auth/admin/categories', [
            'parent_category_id' => $child['id'],
            'category_name' => 'Engineering books',
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_category_id');

        $this->patchJson('/api/auth/admin/categories/'.$root['id'], [
            'parent_category_id' => $child['id'],
        ])->assertUnprocessable();

        $otherRoot = $this->postJson('/api/auth/admin/categories', [
            'category_name' => 'Supplies',
        ])->assertCreated()->json('category');
        $this->patchJson('/api/auth/admin/categories/'.$root['id'], [
            'parent_category_id' => $otherRoot['id'],
        ])->assertUnprocessable();

        $this->patchJson('/api/auth/admin/categories/'.$child['id'], [
            'category_name' => 'Engineering books',
        ])->assertOk();

        $this->deleteJson('/api/auth/admin/categories/'.$root['id'])->assertConflict();
        $this->assertGreaterThanOrEqual(4, $this->getJson('/api/auth/admin/audit-logs')->assertOk()->json('total'));
    }

    public function test_super_admin_can_manage_students_reports_and_admin_accounts(): void
    {
        $admin = $this->createAdmin('super_admin');
        $student = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/auth/admin/students?search='.$student->student_id_number)
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id);

        $this->patchJson('/api/auth/admin/students/'.$student->id, ['account_status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('student.account_status', 'suspended');

        $this->postJson('/api/auth/admin/admins', [
            'student_id_number' => 'ADM-10001',
            'first_name' => 'Review',
            'last_name' => 'Admin',
            'email' => 'review@wmsu.edu.ph',
            'password' => 'Review123',
            'password_confirmation' => 'Review123',
        ])->assertCreated()->assertJsonPath('admin.role', 'verification_admin');

        $reviewAdmin = Admin::query()->whereHas('user', fn ($query) => $query->where('email', 'review@wmsu.edu.ph'))->firstOrFail();
        $this->assertTrue(Hash::check('Review123', $reviewAdmin->user->password));

        $reporter = User::factory()->create();
        $report = Report::query()->create([
            'reporter_id' => $reporter->id,
            'reason' => 'Suspicious behavior',
            'description' => 'Test report',
            'report_status' => 'pending',
            'created_at' => now(),
        ]);

        $this->patchJson('/api/auth/admin/reports/'.$report->id, [
            'report_status' => 'resolved',
            'resolution_notes' => 'Reviewed and resolved.',
        ])->assertOk()->assertJsonPath('report.report_status', 'resolved');

        $this->assertDatabaseHas('audit_logs', ['action' => 'student_status_updated', 'target_id' => $student->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_created', 'target_id' => $reviewAdmin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report_status_updated', 'target_id' => $report->id]);
    }

    public function test_super_admin_can_delete_only_deactivated_students_and_their_related_data(): void
    {
        $admin = $this->createAdmin('super_admin');
        $student = User::factory()->create(['account_status' => 'active']);
        $buyer = User::factory()->create();
        $listingOwner = User::factory()->create();
        $imageDisk = (string) config('campus.listing_image_disk', 'public');
        Storage::fake($imageDisk);
        Storage::fake('local');
        $imagePath = 'listings/'.$student->id.'/item.jpg';
        $corPath = 'cor/'.$student->id.'/document.pdf';
        Storage::disk($imageDisk)->put($imagePath, 'image');
        Storage::disk('local')->put($corPath, 'cor');

        $academicYear = AcademicYear::query()->create([
            'academic_year' => '2026-2027',
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
            'is_current' => true,
        ]);
        $category = Category::query()->create(['category_name' => 'Textbooks']);
        $listing = Listing::query()->create([
            'seller_id' => $student->id,
            'category_id' => $category->id,
            'title' => 'Student textbook',
            'description' => 'A used textbook.',
            'price' => '100.00',
            'condition_rating' => 2,
            'listing_status' => 'available',
        ]);
        $listingImage = ListingImage::query()->create([
            'listing_id' => $listing->id,
            'image_url' => $imagePath,
            'position' => 0,
        ]);
        $verification = Verification::query()->create([
            'user_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'cor_file_path' => $corPath,
            'submission_status' => 'pending',
            'submitted_at' => now(),
        ]);
        $attempt = VerificationAttempt::query()->create([
            'verification_id' => $verification->id,
            'user_id' => $student->id,
            'academic_year_id' => $academicYear->id,
            'attempt_number' => 1,
            'cor_file_path' => $corPath,
            'submission_status' => 'pending',
            'submitted_at' => now(),
        ]);
        $verification->update(['current_attempt_id' => $attempt->id]);
        $conversation = Conversation::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $student->id,
        ]);
        $transaction = Transaction::query()->create([
            'listing_id' => $listing->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $student->id,
            'agreed_price' => '100.00',
            'transaction_status' => 'completed',
        ]);
        $conversation->update(['transaction_id' => $transaction->id]);
        $buyerListing = Listing::query()->create([
            'seller_id' => $listingOwner->id,
            'category_id' => $category->id,
            'title' => 'Pending purchase item',
            'description' => 'An item in a pending purchase.',
            'price' => '75.00',
            'condition_rating' => 2,
            'listing_status' => 'pending_agreement',
        ]);
        $buyerConversation = Conversation::query()->create([
            'listing_id' => $buyerListing->id,
            'buyer_id' => $student->id,
            'seller_id' => $listingOwner->id,
        ]);
        $buyerTransaction = Transaction::query()->create([
            'listing_id' => $buyerListing->id,
            'buyer_id' => $student->id,
            'seller_id' => $listingOwner->id,
            'agreed_price' => '75.00',
            'transaction_status' => 'pending_agreement',
        ]);
        $buyerConversation->update(['transaction_id' => $buyerTransaction->id]);
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $student->id,
            'message_text' => 'Thanks for buying.',
            'sent_at' => now(),
        ]);
        $review = Review::query()->create([
            'transaction_id' => $transaction->id,
            'reviewer_id' => $student->id,
            'reviewed_user_id' => $buyer->id,
            'rating' => 5,
            'comment' => 'Great buyer.',
        ]);
        $report = Report::query()->create([
            'reporter_id' => $buyer->id,
            'reported_user_id' => $student->id,
            'listing_id' => $listing->id,
            'transaction_id' => $transaction->id,
            'reason' => 'Other',
            'description' => 'Test report.',
            'report_status' => 'pending',
        ]);
        $event = TransactionEvent::query()->create([
            'transaction_id' => $transaction->id,
            'actor_id' => $student->id,
            'event_type' => 'test_event',
            'event_description' => 'Test event.',
        ]);
        $notification = Notification::query()->create([
            'user_id' => $student->id,
            'notification_type' => 'test',
            'message' => 'Test notification.',
        ]);

        $this->actingAs($admin)->deleteJson('/api/auth/admin/students/'.$student->id)
            ->assertConflict();
        $student->update(['account_status' => 'deactivated']);
        $this->deleteJson('/api/auth/admin/students/'.$admin->id)->assertConflict();

        $this->deleteJson('/api/auth/admin/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('message', 'Deactivated student account and related data deleted.')
            ->assertJsonPath('warning', null);

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseHas('users', ['id' => $buyer->id]);
        foreach ([
            ['listings', $listing->id],
            ['listing_images', $listingImage->id],
            ['conversations', $conversation->id],
            ['transactions', $transaction->id],
            ['transactions', $buyerTransaction->id],
            ['conversations', $buyerConversation->id],
            ['messages', $message->id],
            ['reviews', $review->id],
            ['reports', $report->id],
            ['transaction_events', $event->id],
            ['notifications', $notification->id],
            ['verifications', $verification->id],
            ['verification_attempts', $attempt->id],
        ] as [$table, $id]) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        $this->assertDatabaseHas('listings', [
            'id' => $buyerListing->id,
            'listing_status' => 'available',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'student_deleted',
            'target_type' => 'user',
            'target_id' => $student->id,
        ]);
        Storage::disk($imageDisk)->assertMissing($imagePath);
        Storage::disk('local')->assertMissing($corPath);
    }

    public function test_super_admin_role_transfer_preserves_one_active_super_admin(): void
    {
        $admin = $this->createAdmin('super_admin');
        $nextAdminUser = User::factory()->create();
        $nextAdmin = Admin::query()->create([
            'user_id' => $nextAdminUser->id,
            'role' => 'verification_admin',
            'account_status' => 'active',
        ]);

        $this->actingAs($admin)->patchJson('/api/auth/admin/admins/'.$nextAdmin->id, [
            'role' => 'super_admin',
        ])->assertOk();

        $this->assertDatabaseHas('admins', [
            'user_id' => $admin->id,
            'role' => 'verification_admin',
            'account_status' => 'active',
        ]);
        $this->assertDatabaseHas('admins', [
            'user_id' => $nextAdminUser->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);
        $this->assertSame(1, Admin::query()->where('role', 'super_admin')->where('account_status', 'active')->count());

        $this->actingAs($admin->fresh())->getJson('/api/auth/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('role', 'verification_admin');
        $this->getJson('/api/auth/admin/verifications')->assertOk();
        $this->getJson('/api/auth/admin/admins')->assertForbidden();

        $this->actingAs($nextAdminUser->fresh())->getJson('/api/auth/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('role', 'super_admin');
        $this->getJson('/api/auth/admin/admins')->assertOk();
    }

    public function test_super_admin_can_transfer_role_to_a_deactivated_admin_when_reactivating_it(): void
    {
        $admin = $this->createAdmin('super_admin');
        $nextAdminUser = User::factory()->create();
        $nextAdmin = Admin::query()->create([
            'user_id' => $nextAdminUser->id,
            'role' => 'verification_admin',
            'account_status' => 'deactivated',
        ]);

        $this->actingAs($admin)->patchJson('/api/auth/admin/admins/'.$nextAdmin->id, [
            'role' => 'super_admin',
            'account_status' => 'active',
        ])->assertOk();

        $this->assertDatabaseHas('admins', [
            'user_id' => $admin->id,
            'role' => 'verification_admin',
            'account_status' => 'active',
        ]);
        $this->assertDatabaseHas('admins', [
            'user_id' => $nextAdminUser->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);
    }

    public function test_inactive_admin_and_regular_student_cannot_use_admin_dashboard(): void
    {
        $admin = $this->createAdmin('verification_admin');
        $admin->update(['account_status' => 'deactivated']);

        $this->actingAs($admin)->getJson('/api/auth/admin/dashboard')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/admin/dashboard')->assertUnauthorized();
    }

    private function createAdmin(string $role): User
    {
        $user = User::factory()->create();
        Admin::query()->create([
            'user_id' => $user->id,
            'role' => $role,
            'account_status' => 'active',
        ]);

        return $user;
    }
}
