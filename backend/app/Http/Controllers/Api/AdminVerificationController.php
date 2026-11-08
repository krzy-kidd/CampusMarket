<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Notification as CampusNotification;
use App\Models\Verification;
use App\Models\VerificationAttempt;
use App\Notifications\VerificationReviewed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminVerificationController extends Controller
{
    public function index(): JsonResponse
    {
        $verifications = Verification::query()
            ->where('submission_status', 'pending')
            ->with([
                'user:id,first_name,last_name,email,student_id_number',
                'academicYear:id,academic_year',
                'currentAttempt:id,attempt_number,submission_status,created_at',
            ])
            ->orderBy('created_at')
            ->paginate(20);

        return response()->json($verifications);
    }

    public function review(Request $request, Verification $verification, VerificationAttempt $attempt): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'admin_notes' => ['nullable', 'required_if:decision,rejected', 'string', 'max:2000'],
        ]);

        abort_unless(
            $verification->current_attempt_id === $attempt->id
                && $attempt->verification_id === $verification->id
                && $verification->submission_status === 'pending'
                && $attempt->submission_status === 'pending',
            409,
            'This submission is no longer awaiting review.',
        );

        $user = DB::transaction(function () use ($request, $verification, $attempt, $validated) {
            $lockedVerification = Verification::query()->whereKey($verification->id)->lockForUpdate()->firstOrFail();
            $lockedAttempt = VerificationAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            abort_unless(
                $lockedVerification->current_attempt_id === $lockedAttempt->id
                    && $lockedVerification->submission_status === 'pending'
                    && $lockedAttempt->submission_status === 'pending',
                409,
                'This submission is no longer awaiting review.',
            );

            $lockedAttempt->update([
                'submission_status' => $validated['decision'],
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $lockedVerification->update([
                'submission_status' => $validated['decision'],
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $admin = Admin::query()->where('user_id', $request->user()->id)->firstOrFail();
            AuditLog::query()->create([
                'admin_id' => $admin->id,
                'action' => 'cor_verification_'.$validated['decision'],
                'target_type' => 'verification_attempt',
                'target_id' => $lockedAttempt->id,
                'created_at' => now(),
            ]);

            CampusNotification::query()->create([
                'user_id' => $lockedVerification->user_id,
                'notification_type' => 'cor_verification_reviewed',
                'message' => 'Your COR submission for '.$lockedVerification->academicYear->academic_year.' was '.$validated['decision'].'.',
                'action_url' => '/account',
                'created_at' => now(),
            ]);

            return $lockedVerification->user;
        });

        try {
            $user->notify(new VerificationReviewed(
                $validated['decision'],
                $validated['admin_notes'] ?? null,
                $verification->academicYear->academic_year,
            ));
        } catch (\Throwable $exception) {
            report($exception);
        }

        return response()->json(['message' => 'The COR submission was reviewed.']);
    }
}
