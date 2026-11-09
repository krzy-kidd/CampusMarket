<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Sanctum\TransientToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $studentIdNumber = $request->input('student_id_number');
        if (is_string($studentIdNumber) && preg_match('/\A\d{4}-?\d{5}\z/', $studentIdNumber) === 1) {
            $request->merge([
                'student_id_number' => str_replace('-', '', $studentIdNumber),
            ]);
        }

        $validated = $request->validate([
            'student_id_number' => [
                'required',
                'string',
                'regex:/\A\d{9}\z/',
                'unique:users,student_id_number',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $email = $request->input('email');
                    if (! is_string($value) || ! is_string($email)) {
                        return;
                    }

                    $emailUsername = Str::before($email, '@');
                    if (
                        preg_match('/\A[a-z]+(\d{9})\z/i', $emailUsername, $matches) !== 1
                        || ! hash_equals($matches[1] ?? '', $value)
                    ) {
                        $fail('The student ID number must match the 9-digit number in your WMSU email address.');
                    }
                },
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                'ends_with:@'.config('campus.wmsu_email_domain'),
                'unique:users,email',
            ],
            'college' => ['nullable', 'string', 'max:150'],
            'course' => ['nullable', 'string', 'max:150'],
            'year_level' => ['nullable', 'string', 'max:25'],
            'section' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'confirmed', 'regex:/\A(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{8,}\z/'],
        ]);

        $user = User::create($validated);

        Auth::login($user);
        $request->session()->regenerate();
        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Your account was created. Verify your WMSU email address to continue.',
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'The supplied credentials are invalid.',
            ], 422);
        }

        $user = $request->user();

        if ($user->account_status !== 'active'
            || ($user->admin && ! $user->admin->isActiveVerificationAdmin())) {
            Auth::logout();

            return response()->json([
                'message' => 'This account is not active. Contact a campus administrator for assistance.',
            ], 403);
        }

        $request->session()->regenerate();

        return response()->json([
            'message' => 'Signed in successfully.',
            'user' => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken && ! ($accessToken instanceof TransientToken)) {
            $accessToken->delete();
        } else {
            Auth::guard('web')->logout();
        }

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Signed out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function sendVerificationNotification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Your email address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'A new email verification link has been sent.']);
    }

    public function verifyEmailLocally(Request $request): JsonResponse
    {
        abort_unless(config('app.env') === 'local', 404);

        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Email marked verified for local testing only. This does not verify your real email address.',
        ]);
    }

    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = $request->user();

        abort_unless((string) $user->getKey() === $id, 403, 'This verification link belongs to another account.');
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403, 'This email verification link is invalid.');

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json(['message' => 'Your WMSU email address has been verified.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $validated['email'] = Str::lower(trim($validated['email']));

        Password::sendResetLink($validated);

        return response()->json([
            'message' => 'If an account exists for that email address, a password reset link will be sent.',
        ], 202);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'regex:/\A(?=.*[A-Za-z])(?=.*[0-9])[A-Za-z0-9]{8,}\z/'],
        ]);
        $validated['email'] = Str::lower(trim($validated['email']));

        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'The password reset link is invalid or has expired.',
            ], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }

    private function userPayload(User $user): array
    {
        $currentAcademicYear = AcademicYear::query()
            ->where('is_current', true)
            ->first(['id', 'academic_year']);

        $verification = $currentAcademicYear
            ? $user->verifications()
                ->where('academic_year_id', $currentAcademicYear->id)
                ->first(['submission_status'])
            : null;

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'account_status' => $user->account_status,
            'admin_role' => $user->admin?->isActiveVerificationAdmin() ? $user->admin->role : null,
            'current_academic_year' => $currentAcademicYear?->academic_year,
            'verification_status' => $verification?->submission_status ?? 'not_submitted',
            'is_verified_for_current_year' => $user->isVerifiedForCurrentAcademicYear(),
        ];
    }
}
