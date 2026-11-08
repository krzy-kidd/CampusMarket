<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    private const REASONS = [
        'Spam or misleading listing',
        'Prohibited item',
        'Inappropriate content',
        'Safety concern',
        'Other',
    ];

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->account_status === 'active', 403, 'Your account is not active.');

        if (is_string($request->input('description'))) {
            $request->merge(['description' => trim($request->input('description'))]);
        }

        $validated = $request->validate([
            'listing_id' => ['required', 'integer', 'exists:listings,id'],
            'reason' => ['required', 'string', 'max:150', Rule::in(self::REASONS)],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $description = $validated['description'];

        $report = DB::transaction(function () use ($user, $validated, $description): Report {
            $listing = Listing::query()->lockForUpdate()->findOrFail($validated['listing_id']);
            abort_unless($listing->listing_status === 'available', 404);
            abort_if($listing->seller_id === $user->id, 403, 'You cannot report your own listing.');

            $hasOpenReport = Report::query()
                ->where('reporter_id', $user->id)
                ->where('listing_id', $listing->id)
                ->whereIn('report_status', ['pending', 'in_review'])
                ->exists();

            abort_if($hasOpenReport, 409, 'You already have an open report for this listing.');

            return Report::query()->create([
                'reporter_id' => $user->id,
                'reported_user_id' => $listing->seller_id,
                'listing_id' => $listing->id,
                'reason' => $validated['reason'],
                'description' => $description,
                'report_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Your report was submitted for review.',
            'report' => [
                'id' => $report->id,
                'listing_id' => $report->listing_id,
                'report_status' => $report->report_status,
                'created_at' => $report->created_at,
            ],
        ], 201);
    }
}
