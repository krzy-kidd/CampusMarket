<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_student_can_report_an_available_listing_for_admin_review(): void
    {
        $seller = User::factory()->create();
        $reporter = User::factory()->create();
        $listing = $this->createListing($seller);

        $this->actingAs($reporter)->postJson('/api/auth/reports', [
            'listing_id' => $listing->id,
            'reason' => 'Prohibited item',
            'description' => 'This item appears to be prohibited.',
        ])->assertCreated()
            ->assertJsonPath('message', 'Your report was submitted for review.')
            ->assertJsonPath('report.listing_id', $listing->id)
            ->assertJsonPath('report.report_status', 'pending')
            ->assertJsonMissingPath('report.reporter_id')
            ->assertJsonMissingPath('report.description');

        $this->assertDatabaseHas('reports', [
            'reporter_id' => $reporter->id,
            'reported_user_id' => $seller->id,
            'listing_id' => $listing->id,
            'reason' => 'Prohibited item',
            'description' => 'This item appears to be prohibited.',
            'report_status' => 'pending',
        ]);
    }

    public function test_submitted_listing_report_appears_in_the_admin_queue_and_can_be_reviewed(): void
    {
        $seller = User::factory()->create();
        $reporter = User::factory()->create();
        $adminUser = User::factory()->create();
        Admin::query()->create([
            'user_id' => $adminUser->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);
        $listing = $this->createListing($seller);

        $report = $this->actingAs($reporter)->postJson('/api/auth/reports', [
            'listing_id' => $listing->id,
            'reason' => 'Inappropriate content',
            'description' => 'Please review the listing text.',
        ])->assertCreated()->json('report');

        $this->actingAs($adminUser)->getJson('/api/auth/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.0.id', $report['id'])
            ->assertJsonPath('data.0.listing.id', $listing->id)
            ->assertJsonPath('data.0.reporter.id', $reporter->id);

        $this->patchJson('/api/auth/admin/reports/'.$report['id'], [
            'report_status' => 'in_review',
        ])->assertOk()->assertJsonPath('report.report_status', 'in_review');
    }

    public function test_listing_owner_cannot_report_their_own_listing(): void
    {
        $seller = User::factory()->create();
        $listing = $this->createListing($seller);

        $this->actingAs($seller)->postJson('/api/auth/reports', [
            'listing_id' => $listing->id,
            'reason' => 'Other',
            'description' => 'test report',
        ])->assertForbidden();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_inactive_accounts_and_unavailable_listings_cannot_be_reported(): void
    {
        $seller = User::factory()->create();
        $reporter = User::factory()->create(['account_status' => 'restricted']);
        $listing = $this->createListing($seller);

        $this->actingAs($reporter)->postJson('/api/auth/reports', [
            'listing_id' => $listing->id,
            'reason' => 'Other',
            'description' => 'Please review this.',
        ])->assertForbidden();

        $reporter->update(['account_status' => 'active']);
        $listing->update(['listing_status' => 'archived']);

        $this->postJson('/api/auth/reports', [
            'listing_id' => $listing->id,
            'reason' => 'Other',
            'description' => 'Please review this.',
        ])->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_student_cannot_submit_a_second_open_report_for_the_same_listing(): void
    {
        $seller = User::factory()->create();
        $reporter = User::factory()->create();
        $listing = $this->createListing($seller);
        $payload = [
            'listing_id' => $listing->id,
            'reason' => 'Spam or misleading listing',
            'description' => 'This appears misleading.',
        ];

        $this->actingAs($reporter)->postJson('/api/auth/reports', $payload)->assertCreated();
        $this->postJson('/api/auth/reports', $payload)->assertConflict();

        $this->assertDatabaseCount('reports', 1);
    }

    public function test_report_requires_authentication_and_valid_reason_and_description(): void
    {
        $listing = $this->createListing(User::factory()->create());

        $this->postJson('/api/auth/reports', [
            'listing_id' => $listing->id,
            'reason' => 'Made up reason',
            'description' => '   ',
        ])->assertUnauthorized();

        $this->actingAs(User::factory()->create())->postJson('/api/auth/reports', [
            'listing_id' => $listing->id,
            'reason' => 'Made up reason',
            'description' => '   ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'description']);
    }

    private function createListing(User $seller): Listing
    {
        $parent = Category::query()->create(['category_name' => 'Books and Education']);
        $category = Category::query()->create([
            'parent_category_id' => $parent->id,
            'category_name' => 'Textbooks',
        ]);

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
