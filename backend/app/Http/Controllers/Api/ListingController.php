<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Review;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ListingController extends Controller
{
    private const MAX_IMAGES = 5;

    private const MARKETPLACE_PAGE_SIZE = 12;

    public function categories(): JsonResponse
    {
        return response()->json([
            'categories' => Category::query()
                ->with('parent:id,parent_category_id,category_name')
                ->withCount('children')
                ->orderBy('category_name')
                ->get(['id', 'parent_category_id', 'category_name', 'description']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'min_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'max_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'condition_rating' => ['nullable', 'integer', 'between:1,4'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        if (
            isset($filters['min_price'], $filters['max_price'])
            && (float) $filters['max_price'] < (float) $filters['min_price']
        ) {
            throw ValidationException::withMessages([
                'max_price' => ['The maximum price must be at least the minimum price.'],
            ]);
        }

        $query = Listing::query()
            ->where('listing_status', 'available')
            ->with([
                'category.parent:id,category_name',
                'seller:id,first_name,last_name,account_status,email_verified_at',
                'images' => fn ($query) => $query->orderBy('position'),
            ]);

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->where(function ($query) use ($search): void {
                $query->whereRaw('LOWER(title) LIKE LOWER(?)', ['%'.$search.'%'])
                    ->orWhereRaw('LOWER(description) LIKE LOWER(?)', ['%'.$search.'%']);
            });
        }

        if (isset($filters['category_id'])) {
            $category = Category::query()->findOrFail($filters['category_id']);
            $subcategoryIds = $category->children()->pluck('id');
            $categoryIds = $subcategoryIds->isEmpty()
                ? [$category->id]
                : $subcategoryIds->all();

            if (isset($filters['subcategory_id'])) {
                if (! $subcategoryIds->contains((int) $filters['subcategory_id'])) {
                    throw ValidationException::withMessages([
                        'subcategory_id' => ['Choose a subcategory within the selected category.'],
                    ]);
                }
                $categoryIds = [(int) $filters['subcategory_id']];
            }

            $query->whereIn('category_id', $categoryIds);
        } elseif (isset($filters['subcategory_id'])) {
            $query->where('category_id', $filters['subcategory_id']);
        }

        if (isset($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }
        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }
        if (isset($filters['condition_rating'])) {
            $query->where('condition_rating', $filters['condition_rating']);
        }

        match ($filters['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price')->orderByDesc('created_at')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate(self::MARKETPLACE_PAGE_SIZE);

        return response()->json([
            'listings' => $this->presentListings($paginator->getCollection()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(Listing $listing): JsonResponse
    {
        abort_unless($listing->listing_status === 'available', 404);
        $listing->load([
            'category.parent:id,category_name',
            'seller:id,first_name,last_name,account_status,email_verified_at',
            'images' => fn ($query) => $query->orderBy('position'),
        ]);

        return response()->json([
            'listing' => $this->presentListings(collect([$listing]))->first(),
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $listings = $request->user()->listings()
            ->with([
                'category.parent:id,category_name',
                'seller:id,first_name,last_name,account_status,email_verified_at',
                'images' => fn ($query) => $query->orderBy('position'),
            ])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'listings' => $this->presentListings($listings),
        ]);
    }

    public function mineShow(Request $request, Listing $listing): JsonResponse
    {
        abort_unless($listing->seller_id === $request->user()->id, 403);
        $listing->load([
            'category.parent:id,category_name',
            'seller:id,first_name,last_name,account_status,email_verified_at',
            'images' => fn ($query) => $query->orderBy('position'),
        ]);

        return response()->json([
            'listing' => $this->presentListings(collect([$listing]))->first(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureVerifiedSeller($request);
        $validated = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:10000'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'condition_rating' => ['required', 'integer', 'between:1,4'],
            'images' => ['required', 'array', 'min:1', 'max:'.self::MAX_IMAGES],
            'images.*' => ['required', 'image', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(5120), 'extensions:jpg,jpeg,png,webp'],
        ]);
        $this->ensureLeafCategory((int) $validated['category_id']);

        $storedPaths = [];

        try {
            $listing = DB::transaction(function () use ($request, $validated, &$storedPaths): Listing {
                $listing = $request->user()->listings()->create([
                    'category_id' => $validated['category_id'],
                    'title' => trim($validated['title']),
                    'description' => trim($validated['description']),
                    'price' => $validated['price'],
                    'condition_rating' => $validated['condition_rating'],
                    'listing_status' => 'available',
                ]);
                $this->storeImages($listing, $validated['images'], $storedPaths);

                return $listing;
            });
        } catch (Throwable $exception) {
            Storage::disk($this->imageDisk())->delete($storedPaths);
            throw $exception;
        }

        $listing->load([
            'category.parent:id,category_name',
            'seller:id,first_name,last_name,account_status,email_verified_at',
            'images' => fn ($query) => $query->orderBy('position'),
        ]);

        return response()->json([
            'message' => 'Your listing has been published.',
            'listing' => $this->presentListings(collect([$listing]))->first(),
        ], 201);
    }

    public function update(Request $request, Listing $listing): JsonResponse
    {
        $this->ensureOwnerCanEdit($request, $listing);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'title' => ['sometimes', 'required', 'string', 'max:200'],
            'description' => ['sometimes', 'required', 'string', 'max:10000'],
            'price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            'condition_rating' => ['sometimes', 'required', 'integer', 'between:1,4'],
            'images' => ['sometimes', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['required', 'image', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(5120), 'extensions:jpg,jpeg,png,webp'],
            'remove_image_ids' => ['sometimes', 'array'],
            'remove_image_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('listing_images', 'id')->where('listing_id', $listing->id),
            ],
        ]);

        if (isset($validated['category_id'])) {
            $this->ensureLeafCategory((int) $validated['category_id']);
        }

        $removeIds = $validated['remove_image_ids'] ?? [];
        $newImages = $validated['images'] ?? [];
        $remainingCount = $listing->images()->count() - count($removeIds) + count($newImages);
        if ($remainingCount < 1 || $remainingCount > self::MAX_IMAGES) {
            throw ValidationException::withMessages([
                'images' => ['A listing must have between 1 and '.self::MAX_IMAGES.' photos.'],
            ]);
        }

        $storedPaths = [];
        $removedPaths = [];

        try {
            DB::transaction(function () use ($listing, $validated, $removeIds, $newImages, &$storedPaths, &$removedPaths): void {
                $listing->update(collect($validated)->only([
                    'category_id',
                    'title',
                    'description',
                    'price',
                    'condition_rating',
                ])->map(function ($value, $key) {
                    return in_array($key, ['title', 'description'], true) ? trim($value) : $value;
                })->all());

                if ($removeIds !== []) {
                    $images = $listing->images()->whereIn('id', $removeIds)->get();
                    $removedPaths = $images->pluck('image_url')->all();
                    $listing->images()->whereIn('id', $removeIds)->delete();
                    $listing->images()->orderBy('position')->get()
                        ->values()
                        ->each(fn ($image, $position) => $image->update(['position' => $position]));
                }

                $this->storeImages($listing, $newImages, $storedPaths, $listing->images()->count());
            });
        } catch (Throwable $exception) {
            Storage::disk($this->imageDisk())->delete($storedPaths);
            throw $exception;
        }

        foreach ($removedPaths as $path) {
            if (! Storage::disk($this->imageDisk())->delete($path)) {
                report(new RuntimeException('Failed to remove a replaced marketplace image.'));
            }
        }

        $listing->load([
            'category.parent:id,category_name',
            'seller:id,first_name,last_name,account_status,email_verified_at',
            'images' => fn ($query) => $query->orderBy('position'),
        ]);

        return response()->json([
            'message' => 'Your listing has been updated.',
            'listing' => $this->presentListings(collect([$listing]))->first(),
        ]);
    }

    public function archive(Request $request, Listing $listing): JsonResponse
    {
        $this->ensureOwnerCanEdit($request, $listing);
        $listing->update(['listing_status' => 'archived']);

        return response()->json([
            'message' => 'Your listing has been archived.',
            'listing_status' => $listing->listing_status,
        ]);
    }

    public function relist(Request $request, Listing $listing): JsonResponse
    {
        abort_unless($listing->seller_id === $request->user()->id, 403);
        abort_unless($listing->listing_status === 'archived', 409, 'Only archived listings can be relisted.');
        $this->ensureVerifiedSeller($request);

        $listing->update(['listing_status' => 'available']);

        return response()->json([
            'message' => 'Your listing is available in the marketplace again.',
            'listing_status' => $listing->listing_status,
        ]);
    }

    private function ensureVerifiedSeller(Request $request): void
    {
        abort_unless(
            $request->user()->isVerifiedForCurrentAcademicYear(),
            403,
            'An active account with an approved COR for the current academic year is required to publish listings.',
        );
    }

    private function ensureOwnerCanEdit(Request $request, Listing $listing): void
    {
        abort_unless($listing->seller_id === $request->user()->id, 403);
        abort_unless($listing->listing_status === 'available', 409, 'Only available listings without an active transaction can be edited or archived.');
        abort_if(
            $listing->transactions()->whereIn('transaction_status', ['pending_agreement', 'meetup_arranged', 'disputed'])->exists(),
            409,
            'This listing has an active transaction and cannot be changed.',
        );
    }

    private function ensureLeafCategory(int $categoryId): void
    {
        $category = Category::query()->findOrFail($categoryId);
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category_id' => ['Choose a subcategory instead of a parent category.'],
            ]);
        }
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string>  $storedPaths
     */
    private function storeImages(Listing $listing, array $files, array &$storedPaths, int $position = 0): void
    {
        foreach ($files as $file) {
            $path = $file->store('listings/'.$listing->seller_id.'/'.$listing->id, $this->imageDisk());
            if (! $path) {
                throw new RuntimeException('Failed to store a marketplace listing image.');
            }

            $storedPaths[] = $path;
            $listing->images()->create([
                'image_url' => $path,
                'position' => $position++,
            ]);
        }
    }

    /**
     * @param  Collection<int, Listing>  $listings
     * @return Collection<int, array<string, mixed>>
     */
    private function presentListings(Collection $listings): Collection
    {
        $currentYearId = AcademicYear::query()->where('is_current', true)->value('id');
        $sellerIds = $listings->pluck('seller_id')->unique()->all();
        $sellerRatings = $sellerIds
            ? Review::query()
                ->selectRaw('reviewed_user_id, AVG(rating) AS rating_average, COUNT(*) AS rating_count')
                ->whereIn('reviewed_user_id', $sellerIds)
                ->groupBy('reviewed_user_id')
                ->get()
                ->keyBy('reviewed_user_id')
            : collect();
        $verifiedSellerIds = $currentYearId
            ? Verification::query()
                ->where('academic_year_id', $currentYearId)
                ->where('submission_status', 'approved')
                ->whereIn('user_id', $listings->pluck('seller_id'))
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        return $listings->map(function (Listing $listing) use ($sellerRatings, $verifiedSellerIds): array {
            $rating = $sellerRatings->get($listing->seller_id);

            return [
                'id' => $listing->id,
                'title' => $listing->title,
                'description' => $listing->description,
                'price' => $listing->price,
                'condition_rating' => $listing->condition_rating,
                'listing_status' => $listing->listing_status,
                'created_at' => $listing->created_at,
                'category' => [
                    'id' => $listing->category->id,
                    'name' => $listing->category->category_name,
                    'parent_name' => $listing->category->parent?->category_name,
                ],
                'seller' => [
                    'id' => $listing->seller->id,
                    'first_name' => $listing->seller->first_name,
                    'last_name' => $listing->seller->last_name,
                    'is_verified' => $listing->seller->account_status === 'active'
                        && $listing->seller->email_verified_at !== null
                        && in_array((int) $listing->seller_id, $verifiedSellerIds, true),
                    'rating_average' => $rating ? round((float) $rating->rating_average, 2) : null,
                    'rating_count' => $rating ? (int) $rating->rating_count : 0,
                ],
                'images' => $listing->images->map(fn ($image): array => [
                    'id' => $image->id,
                    'url' => Storage::disk($this->imageDisk())->url($image->image_url),
                    'position' => $image->position,
                ])->values(),
            ];
        });
    }

    private function imageDisk(): string
    {
        return (string) config('campus.listing_image_disk', 'public');
    }
}
