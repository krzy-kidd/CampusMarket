<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (PDOException $exception) {
            Log::error('Health check could not connect to the database.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'status' => 'unavailable',
                'message' => 'The database connection is unavailable.',
            ], 503);
        }

        return response()->json([
            'status' => 'healthy',
            'application' => config('app.name'),
            'database' => 'connected',
        ]);
    }
}
