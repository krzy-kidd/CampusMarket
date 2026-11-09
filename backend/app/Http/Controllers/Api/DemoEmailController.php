<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DemoEmail;
use Illuminate\Http\JsonResponse;

class DemoEmailController extends Controller
{
    public function index(): JsonResponse
    {
        $this->ensureLocalEnvironment();
        DemoEmail::query()->where('expires_at', '<=', now())->delete();

        return response()->json([
            'demo_emails' => DemoEmail::query()
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'recipient_email', 'email_type', 'action_url', 'created_at', 'expires_at']),
        ]);
    }

    public function clear(): JsonResponse
    {
        $this->ensureLocalEnvironment();
        $deleted = DemoEmail::query()->delete();

        return response()->json([
            'message' => 'The local demo email inbox was cleared.',
            'deleted_count' => $deleted,
        ]);
    }

    private function ensureLocalEnvironment(): void
    {
        abort_unless(config('app.env') === 'local', 404);
    }
}
