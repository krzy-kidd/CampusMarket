<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingImage;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_verified_student_can_publish_edit_archive_and_relist_a_listing(): void
    {
        $seller = $this->createVerifiedStudent();
        $category = $this->createCategory();
        $listing = $this->actingAs($seller)->postJson('/api/auth/listings', [
            ...$this->listingDetails($category),
            'images' => [$this->fakeImage()],
        ])->assertCreated()
            ->assertJsonPath('listing.listing_status', 'available')
            ->assertJsonPath('listing.category.name', 'Textbooks')
            ->assertJsonPath('listing.seller.is_verified', true)
            ->json('listing');

        $this->assertDatabaseHas('listings', [
            'id' => $listing['id'],
            'seller_id' => $seller->id,
            'price' => '125.00',
            'listing_status' => 'available',
        ]);
        Storage::disk('public')->assertExists('listings/'.$seller->id.'/'.$listing['id'].'/'.$this->imageFilename($listing['images'][0]['url']));

        $this->getJson('/api/listings/'.$listing['id'])
            ->assertOk()
            ->assertJsonPath('listing.title', 'Used textbook');

        $this->patchJson('/api/auth/listings/'.$listing['id'], [
            'title' => 'Updated textbook',
            'price' => '150.00',
        ])->assertOk()
            ->assertJsonPath('listing.title', 'Updated textbook')
            ->assertJsonPath('listing.price', '150.00');

        $this->deleteJson('/api/auth/listings/'.$listing['id'])
            ->assertOk()
            ->assertJsonPath('listing_status', 'archived');
        $this->getJson('/api/listings/'.$listing['id'])->assertNotFound();

        $this->postJson('/api/auth/listings/'.$listing['id'].'/relist')
            ->assertOk()
            ->assertJsonPath('listing_status', 'available');
        $this->getJson('/api/listings')->assertJsonPath('listings.0.id', $listing['id']);
    }

    public function test_listing_creation_requires_current_verification_images_and_a_leaf_category(): void
    {
        $student = User::factory()->create();
        $category = $this->createCategory();
        $payload = $this->listingDetails($category);

        $this->actingAs($student)->postJson('/api/auth/listings', [
            ...$payload,
            'images' => [$this->fakeImage()],
        ])->assertForbidden();

        $verifiedStudent = $this->createVerifiedStudent();
        $this->actingAs($verifiedStudent)->postJson('/api/auth/listings', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('images');

        $this->actingAs($verifiedStudent)->postJson('/api/auth/listings', [
            ...$payload,
            'category_id' => $category->parent_category_id,
            'images' => [$this->fakeImage()],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
    }

    public function test_listing_title_and_description_limits_are_enforced_on_create_and_update(): void
    {
        $seller = $this->createVerifiedStudent();
        $category = $this->createCategory();
        $payload = [
            ...$this->listingDetails($category),
            'images' => [$this->fakeImage()],
        ];

        $this->actingAs($seller)->postJson('/api/auth/listings', [
            ...$payload,
            'title' => str_repeat('T', 121),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->actingAs($seller)->postJson('/api/auth/listings', [
            ...$payload,
            'description' => str_repeat('D', 801),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('description');

        $listing = $this->actingAs($seller)->postJson('/api/auth/listings', $payload)
            ->assertCreated()
            ->json('listing');

        $this->actingAs($seller)->patchJson('/api/auth/listings/'.$listing['id'], [
            'title' => str_repeat('T', 121),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('title');

        $this->actingAs($seller)->patchJson('/api/auth/listings/'.$listing['id'], [
            'description' => str_repeat('D', 801),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('description');
    }

    public function test_listing_can_have_up_to_twelve_images_but_not_more(): void
    {
        $seller = $this->createVerifiedStudent();
        $category = $this->createCategory();
        $images = array_fill(0, 12, null);
        foreach ($images as $index => $_) {
            $images[$index] = $this->fakeImage();
        }

        $this->actingAs($seller)->postJson('/api/auth/listings', [
            ...$this->listingDetails($category),
            'images' => $images,
        ])->assertCreated()
            ->assertJsonCount(12, 'listing.images');

        $this->getJson('/api/listings')
            ->assertOk()
            ->assertJsonCount(1, 'listings.0.images');

        $this->actingAs($seller)->getJson('/api/auth/listings')
            ->assertOk()
            ->assertJsonCount(1, 'listings.0.images');

        $tooManyImages = $images;
        $tooManyImages[] = $this->fakeImage();
        $this->actingAs($seller)->postJson('/api/auth/listings', [
            ...$this->listingDetails($category),
            'title' => 'Another textbook',
            'images' => $tooManyImages,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('images');
    }

    public function test_students_can_only_manage_their_own_listings(): void
    {
        $seller = $this->createVerifiedStudent();
        $buyer = $this->createVerifiedStudent();
        $category = $this->createCategory();
        $listing = $this->actingAs($seller)->postJson('/api/auth/listings', [
            ...$this->listingDetails($category),
            'images' => [$this->fakeImage()],
        ])->assertCreated()->json('listing');

        $this->actingAs($buyer)->patchJson('/api/auth/listings/'.$listing['id'], [
            'title' => 'Unauthorized edit',
        ])->assertForbidden();
        $this->actingAs($buyer)->deleteJson('/api/auth/listings/'.$listing['id'])
            ->assertForbidden();
    }

    public function test_inactive_students_cannot_edit_archive_or_submit_cor_documents(): void
    {
        $student = User::factory()->create(['account_status' => 'suspended']);
        $category = $this->createCategory();
        $listing = Listing::query()->create([
            'seller_id' => $student->id,
            ...$this->listingDetails($category),
            'listing_status' => 'available',
        ]);

        $this->actingAs($student)->patchJson('/api/auth/listings/'.$listing->id, [
            'title' => 'Suspended account edit',
        ])->assertForbidden();
        $this->deleteJson('/api/auth/listings/'.$listing->id)->assertForbidden();
        $this->postJson('/api/auth/verification/cor', [
            'cor' => $this->fakeImage(),
        ])->assertForbidden();

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'title' => 'Used textbook',
            'listing_status' => 'available',
        ]);
    }

    public function test_listing_image_model_uses_the_configured_image_disk_url(): void
    {
        config([
            'campus.listing_image_disk' => 'listing_images',
            'filesystems.disks.listing_images' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/listing-images'),
                'url' => 'https://images.example.test',
                'visibility' => 'public',
            ],
        ]);
        Storage::forgetDisk('listing_images');

        $image = new ListingImage(['image_url' => 'listings/1/item.webp']);

        $this->assertSame(
            'https://images.example.test/listings/1/item.webp',
            $image->public_url,
        );
    }

    public function test_super_admin_can_archive_a_listing_without_deleting_its_history(): void
    {
        $seller = $this->createVerifiedStudent();
        $category = $this->createCategory();
        $listing = Listing::query()->create([
            'seller_id' => $seller->id,
            ...$this->listingDetails($category),
            'listing_status' => 'available',
        ]);
        $adminUser = User::factory()->create();
        $admin = Admin::query()->create([
            'user_id' => $adminUser->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);

        $this->actingAs($adminUser)->patchJson('/api/auth/admin/listings/'.$listing->id.'/archive')
            ->assertOk()
            ->assertJsonPath('listing_status', 'archived');

        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'listing_status' => 'archived',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'admin_id' => $admin->id,
            'action' => 'listing_archived',
            'target_type' => 'listing',
            'target_id' => $listing->id,
        ]);
    }

    public function test_marketplace_search_filters_sorting_and_pagination_only_return_available_listings(): void
    {
        $seller = $this->createVerifiedStudent();
        $category = $this->createCategory();

        for ($index = 1; $index <= 13; $index++) {
            Listing::query()->create([
                'seller_id' => $seller->id,
                ...$this->listingDetails($category),
                'title' => 'Used textbook '.$index,
                'price' => number_format(100 + $index, 2, '.', ''),
                'condition_rating' => 2,
                'listing_status' => 'available',
            ]);
        }

        Listing::query()->create([
            'seller_id' => $seller->id,
            ...$this->listingDetails($category),
            'title' => 'Used textbook archived',
            'price' => '105.00',
            'listing_status' => 'archived',
        ]);

        $filters = http_build_query([
            'search' => 'TEXTBOOK',
            'category_id' => $category->parent_category_id,
            'subcategory_id' => $category->id,
            'min_price' => '101',
            'max_price' => '113',
            'condition_rating' => '2',
            'sort' => 'price_asc',
        ]);

        $this->getJson('/api/listings?'.$filters)
            ->assertOk()
            ->assertJsonCount(12, 'listings')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.total', 13)
            ->assertJsonPath('listings.0.price', '101.00');

        $this->getJson('/api/listings?'.$filters.'&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'listings')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('listings.0.price', '113.00');

        $this->getJson('/api/listings?'.http_build_query([
            'search' => 'TEXTBOOK',
            'category_id' => $category->parent_category_id,
        ]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 13);

        $this->getJson('/api/listings?'.http_build_query([
            'subcategory_id' => $category->id,
            'sort' => 'price_desc',
        ]))
            ->assertOk()
            ->assertJsonPath('pagination.total', 13)
            ->assertJsonPath('listings.0.price', '113.00');

        $unrelatedCategory = Category::query()->create([
            'category_name' => 'Electronics',
        ]);
        $unrelatedSubcategory = Category::query()->create([
            'parent_category_id' => $unrelatedCategory->id,
            'category_name' => 'Calculators',
        ]);
        $this->getJson('/api/listings?'.http_build_query([
            'category_id' => $category->parent_category_id,
            'subcategory_id' => $unrelatedSubcategory->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('subcategory_id');

        $this->getJson('/api/listings?min_price=200&max_price=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_price');
        $this->getJson('/api/listings?sort=lowest')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');
    }

    private function createVerifiedStudent(): User
    {
        $academicYear = AcademicYear::query()->firstOrCreate([
            'academic_year' => '2026-2027',
        ], [
            'start_date' => '2026-08-01',
            'end_date' => '2027-07-31',
            'is_current' => true,
        ]);

        $user = User::factory()->create();
        Verification::query()->create([
            'user_id' => $user->id,
            'academic_year_id' => $academicYear->id,
            'cor_file_path' => 'cor/'.$user->id.'/document.pdf',
            'submission_status' => 'approved',
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);

        return $user;
    }

    private function createCategory(): Category
    {
        $parent = Category::query()->create([
            'category_name' => 'Books and Education',
        ]);

        return Category::query()->create([
            'parent_category_id' => $parent->id,
            'category_name' => 'Textbooks',
        ]);
    }

    private function listingDetails(Category $category): array
    {
        return [
            'category_id' => $category->id,
            'title' => 'Used textbook',
            'description' => 'A textbook in good condition.',
            'price' => '125.00',
            'condition_rating' => 2,
        ];
    }

    private function imageFilename(string $url): string
    {
        return basename(parse_url($url, PHP_URL_PATH));
    }

    private function fakeImage(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'item.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC'),
        );
    }
}
