<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DemoEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoEmailPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.env' => 'local']);
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_local_reset_and_verification_links_are_visible_only_to_the_super_admin(): void
    {
        $superAdmin = User::factory()->create();
        Admin::query()->create([
            'user_id' => $superAdmin->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);
        $verificationAdmin = User::factory()->create();
        Admin::query()->create([
            'user_id' => $verificationAdmin->id,
            'role' => 'verification_admin',
            'account_status' => 'active',
        ]);
        $student = User::factory()->unverified()->create();

        $this->postJson('/api/auth/forgot-password', ['email' => $student->email])
            ->assertAccepted();
        $student->sendEmailVerificationNotification();

        $this->actingAs($verificationAdmin)->getJson('/api/auth/admin/demo-emails')->assertForbidden();

        $this->actingAs($superAdmin)->getJson('/api/auth/admin/demo-emails')
            ->assertOk()
            ->assertJsonCount(2, 'demo_emails')
            ->assertJsonFragment(['email_type' => 'password_reset', 'recipient_email' => $student->email])
            ->assertJsonFragment(['email_type' => 'email_verification', 'recipient_email' => $student->email]);

        $this->assertDatabaseHas('demo_emails', [
            'user_id' => $student->id,
            'email_type' => 'password_reset',
        ]);
        $this->assertDatabaseHas('demo_emails', [
            'user_id' => $student->id,
            'email_type' => 'email_verification',
        ]);
    }

    public function test_local_demo_links_are_removed_after_verification_and_password_reset(): void
    {
        $student = User::factory()->unverified()->create();
        $student->sendEmailVerificationNotification();
        $this->postJson('/api/auth/forgot-password', ['email' => $student->email])->assertAccepted();
        $resetEmail = DemoEmail::query()
            ->where('user_id', $student->id)
            ->where('email_type', 'password_reset')
            ->firstOrFail();
        parse_str((string) parse_url($resetEmail->action_url, PHP_URL_QUERY), $query);

        $this->actingAs($student)->postJson('/api/auth/email/verify-local')->assertOk();

        $this->assertDatabaseMissing('demo_emails', [
            'user_id' => $student->id,
            'email_type' => 'email_verification',
        ]);

        $this->postJson('/api/auth/reset-password', [
            'token' => $query['token'],
            'email' => $query['email'],
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertOk();

        $this->assertDatabaseMissing('demo_emails', [
            'user_id' => $student->id,
            'email_type' => 'password_reset',
        ]);
    }

    public function test_demo_email_preview_is_unavailable_outside_local_environment(): void
    {
        $superAdmin = User::factory()->create();
        Admin::query()->create([
            'user_id' => $superAdmin->id,
            'role' => 'super_admin',
            'account_status' => 'active',
        ]);
        config(['app.env' => 'production']);

        $this->actingAs($superAdmin)->getJson('/api/auth/admin/demo-emails')->assertNotFound();
        $this->actingAs($superAdmin)->deleteJson('/api/auth/admin/demo-emails')->assertNotFound();
    }
}
