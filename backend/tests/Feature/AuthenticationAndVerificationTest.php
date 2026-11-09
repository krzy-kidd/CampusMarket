<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Admin;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationAttempt;
use App\Notifications\VerificationReviewed;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_registration_requires_wmsu_email_and_verification_is_signed_and_account_bound(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', $this->registrationData('student@gmail.com'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $response = $this->postJson('/api/auth/register', array_merge(
            $this->registrationData('ty202600123@wmsu.edu.ph'),
            ['password' => 'Pass2026', 'password_confirmation' => 'Pass2026'],
        ));

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ty202600123@wmsu.edu.ph')
            ->assertJsonPath('user.email_verified', false);

        $user = User::query()->where('email', 'ty202600123@wmsu.edu.ph')->firstOrFail();
        Notification::assertSentTo($user, VerifyEmail::class);

        $signedUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);

        $this->actingAs($user)->getJson($signedUrl)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $otherUser = User::factory()->create();
        $otherSignedUrl = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $otherUser->id,
            'hash' => sha1($otherUser->getEmailForVerification()),
        ]);

        $this->getJson($otherSignedUrl)->assertForbidden();
    }

    public function test_local_email_verification_shortcut_is_unavailable_outside_local_environment(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($user)
            ->postJson('/api/auth/email/verify-local')
            ->assertNotFound();

        $this->assertNull($user->fresh()->email_verified_at);

        config(['app.env' => 'local']);

        $this->postJson('/api/auth/email/verify-local')
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Email marked verified for local testing only. This does not verify your real email address.',
            );

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_registration_matches_student_id_to_wmsu_email_and_normalizes_hyphenated_ids(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', array_merge(
            $this->registrationData('ty202702829@wmsu.edu.ph'),
            ['student_id_number' => '2027-02829'],
        ))->assertCreated();

        $this->assertDatabaseHas('users', [
            'student_id_number' => '202702829',
            'email' => 'ty202702829@wmsu.edu.ph',
        ]);
    }

    public function test_registration_rejects_student_id_that_does_not_match_wmsu_email(): void
    {
        $this->postJson('/api/auth/register', array_merge(
            $this->registrationData('ty202702820@wmsu.edu.ph'),
            ['student_id_number' => '2027-02829'],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('student_id_number');

        $this->postJson('/api/auth/register', array_merge(
            $this->registrationData('ty202702829@wmsu.edu.ph'),
            ['student_id_number' => '20-2702829'],
        ))->assertUnprocessable()
            ->assertJsonValidationErrors('student_id_number');
    }

    public function test_registration_password_must_be_at_least_eight_alphanumeric_characters(): void
    {
        $this->postJson('/api/auth/register', array_merge(
            $this->registrationData('short@wmsu.edu.ph'),
            ['password' => 'Pass202', 'password_confirmation' => 'Pass202'],
        ))->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/register', array_merge(
            $this->registrationData('letters@wmsu.edu.ph'),
            ['password' => 'OnlyLetters', 'password_confirmation' => 'OnlyLetters'],
        ))->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->postJson('/api/auth/register', array_merge(
            $this->registrationData('symbols@wmsu.edu.ph'),
            ['password' => 'Pass202!', 'password_confirmation' => 'Pass202!'],
        ))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_login_me_logout_and_password_reset_use_session_authentication(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'student@wmsu.edu.ph',
            'password' => Hash::make('OldPassword2026'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'STUDENT@WMSU.EDU.PH',
            'password' => 'OldPassword2026',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', $user->email);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me')->assertUnauthorized();

        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'email' => strtoupper($user->email),
            'token' => $token,
            'password' => 'Reset123',
            'password_confirmation' => 'Reset123',
        ])->assertOk();

        $this->assertTrue(Hash::check('Reset123', $user->fresh()->password));
    }

    public function test_cor_requires_a_current_academic_year_and_uploads_to_private_storage(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('cor.pdf', 100, 'application/pdf'),
        ])->assertConflict();

        $this->createCurrentAcademicYear();

        $response = $this->actingAs($user)->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('cor.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('attempt.submission_status', 'pending')
            ->assertJsonMissingPath('attempt.cor_file_path');

        $attempt = VerificationAttempt::query()->firstOrFail();
        $this->assertStringStartsWith('cor/', $attempt->cor_file_path);
        Storage::disk('local')->assertExists($attempt->cor_file_path);

        $this->getJson('/api/auth/verification-attempts/'.$attempt->id.'/file')
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private');

        $this->actingAs(User::factory()->create())
            ->getJson('/api/auth/verification-attempts/'.$attempt->id.'/file')
            ->assertForbidden();
    }

    public function test_admin_review_preserves_rejected_attempts_and_approvals_are_year_specific(): void
    {
        Storage::fake('local');
        Notification::fake();

        $year = $this->createCurrentAcademicYear();
        $student = User::factory()->create();
        $adminUser = User::factory()->create();
        Admin::query()->create([
            'user_id' => $adminUser->id,
            'role' => 'verification_admin',
            'account_status' => 'active',
        ]);

        $this->actingAs($student)->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $verification = Verification::query()->firstOrFail();
        $firstAttempt = VerificationAttempt::query()->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->postJson("/api/auth/admin/verifications/{$verification->id}/attempts/{$firstAttempt->id}/review", [
                'decision' => 'approved',
            ])->assertForbidden();

        $this->actingAs($adminUser)->postJson(
            "/api/auth/admin/verifications/{$verification->id}/attempts/{$firstAttempt->id}/review",
            ['decision' => 'rejected', 'admin_notes' => 'Please upload a readable copy.'],
        )->assertOk();

        $this->assertDatabaseHas('verification_attempts', [
            'id' => $firstAttempt->id,
            'submission_status' => 'rejected',
            'admin_notes' => 'Please upload a readable copy.',
        ]);
        Storage::disk('local')->assertExists($firstAttempt->cor_file_path);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $student->id,
            'notification_type' => 'cor_verification_reviewed',
            'action_url' => '/account',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'cor_verification_rejected',
            'target_id' => $firstAttempt->id,
        ]);
        Notification::assertSentTo($student, VerificationReviewed::class);

        $this->actingAs($student)->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $secondAttempt = VerificationAttempt::query()->where('attempt_number', 2)->firstOrFail();
        $this->actingAs($adminUser)->postJson(
            "/api/auth/admin/verifications/{$verification->id}/attempts/{$secondAttempt->id}/review",
            ['decision' => 'approved'],
        )->assertOk();

        $this->assertTrue($student->fresh()->isVerifiedForCurrentAcademicYear());
        $this->assertDatabaseCount('verification_attempts', 2);

        $nextYear = AcademicYear::query()->create([
            'academic_year' => '2026-2027',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-30',
            'is_current' => false,
        ]);
        $year->update(['is_current' => false]);
        $nextYear->update(['is_current' => true]);

        $this->assertFalse($student->fresh()->isVerifiedForCurrentAcademicYear());
    }

    public function test_cor_upload_rejects_unsupported_file_types(): void
    {
        Storage::fake('local');
        $this->createCurrentAcademicYear();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
        ])->assertUnprocessable()->assertJsonValidationErrors('cor');
    }

    public function test_cor_submission_requires_a_verified_email(): void
    {
        Storage::fake('local');
        $this->createCurrentAcademicYear();
        $unverifiedUser = User::factory()->unverified()->create();

        $this->actingAs($unverifiedUser, 'web')->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('cor.pdf', 100, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_cor_upload_respects_the_configured_size_limit(): void
    {
        Storage::fake('local');
        $this->createCurrentAcademicYear();
        $verifiedUser = User::factory()->create();

        $this->actingAs($verifiedUser, 'web')->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('cor');
    }

    public function test_a_pending_cor_cannot_be_submitted_again(): void
    {
        Storage::fake('local');
        $this->createCurrentAcademicYear();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->postJson('/api/auth/verification/cor', [
            'cor' => UploadedFile::fake()->create('duplicate.pdf', 100, 'application/pdf'),
        ])->assertConflict();

        $this->assertDatabaseCount('verification_attempts', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('cor/'.$user->id));
    }

    private function registrationData(string $email): array
    {
        return [
            'student_id_number' => '202600123',
            'first_name' => 'Campus',
            'last_name' => 'Student',
            'email' => $email,
            'password' => 'SecurePassword2026',
            'password_confirmation' => 'SecurePassword2026',
        ];
    }

    private function createCurrentAcademicYear(): AcademicYear
    {
        return AcademicYear::query()->create([
            'academic_year' => '2025-2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_current' => true,
        ]);
    }
}
