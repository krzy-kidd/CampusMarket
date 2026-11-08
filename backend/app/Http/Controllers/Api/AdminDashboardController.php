<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\PlatformSetting;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\TransactionEvent;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminDashboardController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $role = $request->user()->admin->role;
        $data = [
            'role' => $role,
            'current_academic_year' => AcademicYear::query()->where('is_current', true)->value('academic_year'),
            'pending_verifications' => Verification::query()->where('submission_status', 'pending')->count(),
        ];

        if ($role === 'super_admin') {
            $data += [
                'total_students' => User::query()->whereDoesntHave('admin')->count(),
                'active_students' => User::query()->whereDoesntHave('admin')->where('account_status', 'active')->count(),
                'pending_reports' => Report::query()->whereIn('report_status', ['pending', 'in_review'])->count(),
                'categories' => Category::query()->count(),
                'active_admins' => Admin::query()->where('account_status', 'active')->count(),
            ];
        }

        return response()->json($data);
    }

    public function academicYears(): JsonResponse
    {
        return response()->json([
            'academic_years' => AcademicYear::query()->orderByDesc('start_date')->get(),
        ]);
    }

    public function storeAcademicYear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['required', 'string', 'max:20', 'unique:academic_years,academic_year'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_current' => ['sometimes', 'boolean'],
        ]);
        if ($this->hasOverlappingAcademicYear($validated['start_date'], $validated['end_date'])) {
            return response()->json([
                'message' => 'The academic-year dates overlap an existing academic year.',
                'errors' => ['start_date' => ['Academic-year date ranges cannot overlap.']],
            ], 422);
        }

        $academicYear = DB::transaction(function () use ($validated): AcademicYear {
            if ($validated['is_current'] ?? false) {
                AcademicYear::query()->where('is_current', true)->update(['is_current' => false]);
            }

            return AcademicYear::query()->create($validated);
        });

        $this->recordAudit($request, 'academic_year_created', 'academic_year', $academicYear->id);

        return response()->json(['academic_year' => $academicYear], 201);
    }

    public function updateAcademicYear(Request $request, AcademicYear $academicYear): JsonResponse
    {
        $validated = $request->validate([
            'academic_year' => ['sometimes', 'required', 'string', 'max:20', Rule::unique('academic_years', 'academic_year')->ignore($academicYear->id)],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['sometimes', 'required', 'date', 'after_or_equal:start_date'],
            'is_current' => ['sometimes', 'boolean'],
        ]);
        $startDate = $validated['start_date'] ?? $academicYear->start_date->toDateString();
        $endDate = $validated['end_date'] ?? $academicYear->end_date->toDateString();
        if ($this->hasOverlappingAcademicYear($startDate, $endDate, $academicYear->id)) {
            return response()->json([
                'message' => 'The academic-year dates overlap an existing academic year.',
                'errors' => ['start_date' => ['Academic-year date ranges cannot overlap.']],
            ], 422);
        }

        DB::transaction(function () use ($academicYear, $validated): void {
            if ($validated['is_current'] ?? false) {
                AcademicYear::query()->where('is_current', true)->whereKeyNot($academicYear->id)->update(['is_current' => false]);
            }

            $academicYear->update($validated);
        });

        $this->recordAudit($request, 'academic_year_updated', 'academic_year', $academicYear->id);

        return response()->json(['academic_year' => $academicYear->fresh()]);
    }

    public function destroyAcademicYear(Request $request, AcademicYear $academicYear): JsonResponse
    {
        abort_if($academicYear->verifications()->exists(), 409, 'An academic year with verification records cannot be deleted.');

        $academicYear->delete();
        $this->recordAudit($request, 'academic_year_deleted', 'academic_year', $academicYear->id);

        return response()->json(['message' => 'Academic year deleted.']);
    }

    public function categories(): JsonResponse
    {
        return response()->json([
            'categories' => Category::query()
                ->with('parent:id,category_name')
                ->withCount(['children', 'listings'])
                ->orderBy('category_name')
                ->get(),
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_if(
            Category::query()
                ->where('category_name', $validated['category_name'])
                ->when(
                    $request->input('parent_category_id'),
                    fn (Builder $query, $parentId) => $query->where('parent_category_id', $parentId),
                    fn (Builder $query) => $query->whereNull('parent_category_id'),
                )
                ->exists(),
            422,
            'A category with this name already exists at that level.',
        );

        $category = Category::query()->create($validated);
        $this->recordAudit($request, 'category_created', 'category', $category->id);

        return response()->json(['category' => $category], 201);
    }

    public function updateCategory(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'parent_category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->whereNot('id', $category->id)],
            'category_name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        abort_if(isset($validated['parent_category_id']) && $this->isDescendant($category, (int) $validated['parent_category_id']), 422, 'A category cannot be moved under one of its descendants.');
        $categoryName = $validated['category_name'] ?? $category->category_name;
        $parentId = array_key_exists('parent_category_id', $validated)
            ? $validated['parent_category_id']
            : $category->parent_category_id;
        abort_if(
            Category::query()
                ->whereKeyNot($category->id)
                ->where('category_name', $categoryName)
                ->when($parentId, fn (Builder $query) => $query->where('parent_category_id', $parentId), fn (Builder $query) => $query->whereNull('parent_category_id'))
                ->exists(),
            422,
            'A category with this name already exists at that level.',
        );

        $category->update($validated);
        $this->recordAudit($request, 'category_updated', 'category', $category->id);

        return response()->json(['category' => $category->fresh()]);
    }

    public function destroyCategory(Request $request, Category $category): JsonResponse
    {
        abort_if($category->children()->exists() || $category->listings()->exists(), 409, 'A category with subcategories or listings cannot be deleted.');

        $category->delete();
        $this->recordAudit($request, 'category_deleted', 'category', $category->id);

        return response()->json(['message' => 'Category deleted.']);
    }

    public function students(Request $request): JsonResponse
    {
        $students = User::query()
            ->whereDoesntHave('admin')
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = trim((string) $request->query('search'));
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('student_id_number', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(20, ['id', 'student_id_number', 'first_name', 'last_name', 'email', 'account_status', 'email_verified_at', 'created_at']);

        return response()->json($students);
    }

    public function updateStudent(Request $request, User $user): JsonResponse
    {
        abort_if($user->admin()->exists(), 409, 'Admin accounts cannot be changed through student management.');
        $validated = $request->validate([
            'account_status' => ['required', Rule::in(['active', 'restricted', 'suspended', 'deactivated'])],
        ]);

        $user->update($validated);
        $this->recordAudit($request, 'student_status_updated', 'user', $user->id);

        return response()->json([
            'student' => $user->only(['id', 'student_id_number', 'first_name', 'last_name', 'email', 'account_status']),
        ]);
    }

    public function admins(): JsonResponse
    {
        return response()->json([
            'admins' => Admin::query()->with('user:id,first_name,last_name,email,student_id_number,account_status')
                ->orderBy('role')
                ->orderBy('id')
                ->get(['id', 'user_id', 'role', 'account_status', 'created_at']),
        ]);
    }

    public function storeAdmin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id_number' => ['required', 'string', 'max:50', 'unique:users,student_id_number'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'ends_with:@'.config('campus.wmsu_email_domain'), 'unique:users,email'],
            'password' => ['required', 'confirmed', 'regex:/\A(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{8,}\z/'],
        ]);
        $validated['email'] = Str::lower(trim($validated['email']));

        $admin = DB::transaction(function () use ($validated): Admin {
            $user = User::query()->create([
                'student_id_number' => $validated['student_id_number'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'account_status' => 'active',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            return Admin::query()->create([
                'user_id' => $user->id,
                'role' => 'verification_admin',
                'account_status' => 'active',
            ]);
        });

        $this->recordAudit($request, 'admin_created', 'admin', $admin->id);

        return response()->json([
            'admin' => $admin->load('user:id,first_name,last_name,email,student_id_number,account_status'),
        ], 201);
    }

    public function updateAdmin(Request $request, Admin $admin): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', 'required', Rule::in(['verification_admin', 'super_admin'])],
            'account_status' => ['sometimes', 'required', Rule::in(['active', 'deactivated'])],
        ]);
        $actorAdmin = Admin::query()->where('user_id', $request->user()->id)->firstOrFail();
        $changingSelfSuperAdmin = $admin->id === $actorAdmin->id
            && $admin->isActiveSuperAdmin()
            && (($validated['account_status'] ?? 'active') !== 'active' || ($validated['role'] ?? 'super_admin') !== 'super_admin');
        abort_if($changingSelfSuperAdmin, 409, 'Use another active Super Admin account to change your own role or status.');

        $transferSuperAdmin = ($validated['role'] ?? $admin->role) === 'super_admin'
            && ($validated['account_status'] ?? $admin->account_status) === 'active'
            && $admin->id !== $actorAdmin->id
            && $actorAdmin->isActiveSuperAdmin();
        abort_if(
            $transferSuperAdmin
                && (($validated['account_status'] ?? $admin->account_status) !== 'active'
                    || $admin->user->account_status !== 'active'),
            409,
            'Super Admin access can only be transferred to an active administrator account.',
        );
        $lastActiveSuperAdmin = $admin->isActiveSuperAdmin()
            && (($validated['account_status'] ?? 'active') !== 'active' || ($validated['role'] ?? 'super_admin') !== 'super_admin')
            && Admin::query()->where('role', 'super_admin')->where('account_status', 'active')->count() <= 1;
        abort_if($lastActiveSuperAdmin && ! $transferSuperAdmin, 409, 'The last active Super Admin cannot be deactivated or demoted.');

        DB::transaction(function () use ($admin, $actorAdmin, $validated, $transferSuperAdmin): void {
            if ($transferSuperAdmin) {
                $actorAdmin->update(['role' => 'verification_admin']);
            }

            $admin->update($validated);
        });

        $this->recordAudit($request, 'admin_updated', 'admin', $admin->id);
        if ($transferSuperAdmin) {
            $this->recordAudit($request, 'super_admin_role_transferred', 'admin', $admin->id);
        }

        return response()->json([
            'admin' => $admin->fresh()->load('user:id,first_name,last_name,email,student_id_number,account_status'),
        ]);
    }

    public function reports(Request $request): JsonResponse
    {
        return response()->json(
            Report::query()
                ->with([
                    'reporter:id,first_name,last_name,email',
                    'reportedUser:id,first_name,last_name,email',
                    'listing:id,title',
                    'transaction:id,listing_id,buyer_id,seller_id,transaction_status',
                    'transaction.listing:id,title',
                    'transaction.buyer:id,first_name,last_name,email',
                    'transaction.seller:id,first_name,last_name,email',
                ])
                ->when($request->filled('status'), fn (Builder $query) => $query->where('report_status', $request->query('status')))
                ->orderByDesc('created_at')
                ->paginate(20),
        );
    }

    public function platformSettings(): JsonResponse
    {
        $setting = PlatformSetting::query()
            ->where('setting_key', 'seller_unlock_response_hours')
            ->firstOrFail();

        return response()->json([
            'platform_settings' => [
                'seller_unlock_response_hours' => (int) $setting->setting_value,
            ],
        ]);
    }

    public function updatePlatformSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'seller_unlock_response_hours' => ['required', 'integer', 'min:1', 'max:168'],
        ]);
        $adminId = Admin::query()->where('user_id', $request->user()->id)->value('id');

        $setting = DB::transaction(function () use ($validated, $adminId): PlatformSetting {
            $setting = PlatformSetting::query()
                ->where('setting_key', 'seller_unlock_response_hours')
                ->lockForUpdate()
                ->firstOrFail();
            $setting->update([
                'setting_value' => (string) $validated['seller_unlock_response_hours'],
                'updated_by' => $adminId,
            ]);

            return $setting->fresh();
        });
        $this->recordAudit($request, 'seller_unlock_response_window_updated', 'platform_setting', $setting->id);

        return response()->json([
            'platform_settings' => [
                'seller_unlock_response_hours' => (int) $setting->setting_value,
            ],
        ]);
    }

    public function listings(): JsonResponse
    {
        return response()->json(
            Listing::query()
                ->with([
                    'category:id,category_name',
                    'seller:id,first_name,last_name,email',
                ])
                ->withCount('transactions')
                ->orderByDesc('created_at')
                ->paginate(30),
        );
    }

    public function updateReport(Request $request, Report $report): JsonResponse
    {
        $validated = $request->validate([
            'report_status' => ['required', Rule::in(['pending', 'in_review', 'resolved', 'dismissed'])],
            'resolution_notes' => ['nullable', 'required_if:report_status,resolved,dismissed', 'string', 'max:5000'],
            'transaction_resolution' => [
                'nullable',
                Rule::requiredIf(
                    $report->transaction_id !== null
                        && in_array($request->input('report_status'), ['resolved', 'dismissed'], true),
                ),
                Rule::in(['resume', 'cancel']),
            ],
        ]);

        $isFinalStatus = in_array($validated['report_status'], ['resolved', 'dismissed'], true);
        abort_if(
            $report->transaction_id !== null
                && $report->report_status !== $validated['report_status']
                && in_array($report->report_status, ['resolved', 'dismissed'], true),
            409,
            'A finalized transaction dispute cannot be reopened.',
        );
        abort_if(
            $report->transaction_id !== null
                && $validated['report_status'] === 'dismissed'
                && ($validated['transaction_resolution'] ?? null) !== 'resume',
            422,
            'A dismissed dispute must resume the transaction.',
        );

        $report = DB::transaction(function () use ($request, $report, $validated, $isFinalStatus): Report {
            $report = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            $transaction = null;

            if ($report->transaction_id !== null && $isFinalStatus) {
                $transaction = Transaction::query()
                    ->whereKey($report->transaction_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($transaction->transaction_status === 'disputed', 409, 'This transaction is no longer awaiting dispute review.');

                if ($validated['transaction_resolution'] === 'resume') {
                    $transaction->update(['transaction_status' => 'meetup_arranged']);
                    $transaction->listing()->update(['listing_status' => 'locked']);
                    $eventType = 'dispute_resumed';
                    $eventDescription = 'A Super Admin reviewed the dispute and resumed the meetup arrangement.';
                } else {
                    $transaction->update([
                        'transaction_status' => 'cancelled',
                        'cancellation_reason' => 'The transaction was cancelled by a Super Admin after dispute review: '.$validated['resolution_notes'],
                    ]);
                    $transaction->listing()->update(['listing_status' => 'available']);
                    $eventType = 'dispute_cancelled';
                    $eventDescription = 'A Super Admin reviewed the dispute and cancelled the transaction, releasing the listing.';
                }

                TransactionEvent::query()->create([
                    'transaction_id' => $transaction->id,
                    'actor_id' => $request->user()->id,
                    'event_type' => $eventType,
                    'event_description' => $eventDescription,
                    'created_at' => now(),
                ]);
                foreach ([$transaction->buyer_id, $transaction->seller_id] as $userId) {
                    Notification::query()->create([
                        'user_id' => $userId,
                        'notification_type' => 'transaction_update',
                        'message' => 'A Super Admin reviewed the dispute. '.$eventDescription,
                        'action_url' => '/transactions/'.$transaction->id,
                        'created_at' => now(),
                    ]);
                }
            }

            $report->update([
                'report_status' => $validated['report_status'],
                'resolution_notes' => $validated['resolution_notes'] ?? null,
                'updated_at' => now(),
            ]);

            return $report->fresh([
                'reporter:id,first_name,last_name,email',
                'reportedUser:id,first_name,last_name,email',
                'listing:id,title',
                'transaction',
            ]);
        });
        $this->recordAudit(
            $request,
            $report->transaction_id !== null && $isFinalStatus
                ? 'transaction_dispute_resolved'
                : 'report_status_updated',
            'report',
            $report->id,
        );

        return response()->json(['report' => $report->fresh()]);
    }

    public function archiveListing(Request $request, Listing $listing): JsonResponse
    {
        abort_if(
            in_array($listing->listing_status, ['pending_agreement', 'locked'], true)
                || $listing->transactions()->whereIn('transaction_status', ['pending_agreement', 'meetup_arranged', 'disputed'])->exists(),
            409,
            'A listing with an active transaction cannot be archived.',
        );
        abort_if($listing->listing_status === 'sold', 409, 'A sold listing cannot be archived.');

        $listing->update(['listing_status' => 'archived']);
        $this->recordAudit($request, 'listing_archived', 'listing', $listing->id);

        return response()->json([
            'message' => 'The listing has been archived.',
            'listing_status' => $listing->listing_status,
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        return response()->json(
            AuditLog::query()
                ->with('admin.user:id,first_name,last_name,email')
                ->when($request->filled('action'), fn (Builder $query) => $query->where('action', 'like', '%'.trim((string) $request->query('action')).'%'))
                ->orderByDesc('created_at')
                ->paginate(50),
        );
    }

    private function recordAudit(Request $request, string $action, string $targetType, ?int $targetId): void
    {
        $adminId = Admin::query()->where('user_id', $request->user()->id)->value('id');

        AuditLog::query()->create([
            'admin_id' => $adminId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'created_at' => now(),
        ]);
    }

    private function hasOverlappingAcademicYear(string $startDate, string $endDate, ?int $exceptId = null): bool
    {
        return AcademicYear::query()
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    private function isDescendant(Category $category, int $candidateId): bool
    {
        $children = $category->children()->pluck('id');

        foreach ($children as $childId) {
            if ($childId === $candidateId || $this->isDescendant(Category::query()->findOrFail($childId), $candidateId)) {
                return true;
            }
        }

        return false;
    }
}
