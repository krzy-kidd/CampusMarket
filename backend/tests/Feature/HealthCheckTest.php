<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_check_confirms_api_and_database_availability(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andReturn([(object) ['1' => 1]]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'healthy',
                'application' => 'CampusTrade',
                'database' => 'connected',
            ]);
    }

    public function test_health_check_reports_database_failure_without_exposing_details(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('SELECT 1')
            ->andThrow(new PDOException('sensitive database details'));
        Log::shouldReceive('error')->once();

        $this->getJson('/api/health')
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('message', 'The database connection is unavailable.')
            ->assertDontSee('sensitive database details');
    }

    public function test_frontend_origin_is_allowed_for_credentialed_api_requests(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:5173',
            'Access-Control-Request-Method' => 'GET',
        ])->options('/api/health')
            ->assertNoContent()
            ->assertHeader('access-control-allow-origin', 'http://localhost:5173')
            ->assertHeader('access-control-allow-credentials', 'true');
    }
}
