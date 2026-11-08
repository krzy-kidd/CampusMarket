<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            ->assertJsonMissingPath('total_students');

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

        $this->patchJson('/api/auth/admin/categories/'.$root['id'], [
            'parent_category_id' => $child['id'],
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
