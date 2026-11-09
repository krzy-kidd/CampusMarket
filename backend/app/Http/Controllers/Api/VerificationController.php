<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VerificationController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $this->ensureStudentAccount($request);

        $academicYear = AcademicYear::query()->where('is_current', true)->first();

        if (! $academicYear) {
            return response()->json([
                'academic_year' => null,
                'status' => 'not_configured',
                'is_verified' => false,
                'attempts' => [],
            ]);
        }

        $verification = Verification::query()
            ->where('user_id', $request->user()->id)
            ->where('academic_year_id', $academicYear->id)
            ->with('attempts:id,verification_id,attempt_number,submission_status,admin_notes,reviewed_at,created_at')
            ->first();

        return response()->json([
            'academic_year' => $academicYear->academic_year,
            'status' => $verification?->submission_status ?? 'not_submitted',
            'is_verified' => $request->user()->isVerifiedForCurrentAcademicYear(),
            'attempts' => $verification?->attempts ?? [],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $this->ensureStudentAccount($request);

        $attempts = VerificationAttempt::query()
            ->whereHas('verification', fn ($query) => $query->where('user_id', $request->user()->id))
            ->with('verification.academicYear:id,academic_year')
            ->orderByDesc('created_at')
            ->get(['id', 'verification_id', 'attempt_number', 'submission_status', 'admin_notes', 'reviewed_at', 'created_at']);

        return response()->json(['attempts' => $attempts]);
    }

    public function submit(Request $request): JsonResponse
    {
        $this->ensureStudentAccount($request);

        $academicYear = AcademicYear::query()->where('is_current', true)->first();

        if (! $academicYear) {
            return response()->json([
                'message' => 'COR verification is unavailable because no academic year is currently configured.',
            ], 409);
        }

        $validated = $request->validate([
            'cor' => [
                'required',
                File::types(['pdf', 'jpg', 'jpeg', 'png'])->max((int) config('campus.cor_max_file_size_kilobytes')),
                'extensions:pdf,jpg,jpeg,png',
            ],
        ]);

        $file = $validated['cor'];
        $path = $file->store('cor/'.$request->user()->id, 'local');

        if (! $path) {
            report(new \RuntimeException('Failed to store a COR verification document.'));

            return response()->json(['message' => 'The COR could not be stored. Please try again.'], 500);
        }

        $committed = false;

        try {
            $attempt = DB::transaction(function () use ($academicYear, $request, $path, &$committed): VerificationAttempt {
                User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $lockedVerification = Verification::query()
                    ->where('user_id', $request->user()->id)
                    ->where('academic_year_id', $academicYear->id)
                    ->lockForUpdate()
                    ->first();
                $isNewVerification = $lockedVerification === null;

                if ($isNewVerification) {
                    $lockedVerification = Verification::query()->create([
                        'user_id' => $request->user()->id,
                        'academic_year_id' => $academicYear->id,
                        'cor_file_path' => $path,
                        'submission_status' => 'pending',
                        'submitted_at' => now(),
                    ]);
                }

                if (! $isNewVerification) {
                    abort_if($lockedVerification->submission_status === 'approved', 409, 'Your COR has already been approved for the current academic year.');
                    abort_if($lockedVerification->submission_status === 'pending', 409, 'Your COR is awaiting review.');
                }

                $attemptNumber = (int) $lockedVerification->attempts()->max('attempt_number') + 1;
                $attempt = $lockedVerification->attempts()->create([
                    'user_id' => $request->user()->id,
                    'academic_year_id' => $academicYear->id,
                    'attempt_number' => $attemptNumber,
                    'cor_file_path' => $path,
                    'submission_status' => 'pending',
                    'submitted_at' => now(),
                ]);

                $lockedVerification->update([
                    'current_attempt_id' => $attempt->id,
                    'cor_file_path' => $path,
                    'submission_status' => 'pending',
                    'submitted_at' => now(),
                    'admin_notes' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                ]);

                $committed = true;

                return $attempt;
            });
        } finally {
            if (! $committed) {
                Storage::disk('local')->delete($path);
            }
        }

        return response()->json([
            'message' => 'Your COR was submitted for review.',
            'attempt' => $attempt->only(['id', 'attempt_number', 'submission_status', 'created_at']),
        ], 201);
    }

    public function file(Request $request, VerificationAttempt $attempt): StreamedResponse
    {
        $isOwner = $attempt->verification()->where('user_id', $request->user()->id)->exists();
        $isAdmin = $request->user()->admin?->isActiveVerificationAdmin() ?? false;

        abort_unless($isOwner || $isAdmin, 403, 'You are not authorized to view this COR document.');
        abort_unless(str_starts_with($attempt->cor_file_path, 'cor/'), 404);
        abort_unless(Storage::disk('local')->exists($attempt->cor_file_path), 404, 'The COR document is unavailable.');

        return Storage::disk('local')->response(
            $attempt->cor_file_path,
            'cor-document.'.pathinfo($attempt->cor_file_path, PATHINFO_EXTENSION),
            ['Cache-Control' => 'private, no-store'],
            'inline',
        );
    }

    private function ensureStudentAccount(Request $request): void
    {
        abort_if(
            $request->user()->admin()->exists(),
            403,
            'Administrator accounts do not use student COR verification.',
        );
    }
}
