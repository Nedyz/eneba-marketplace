<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/health
 *
 * Used by Render's health check (a non-200 response marks a deploy as failed)
 * and by the frontend to prove the whole chain works: browser -> API -> database.
 *
 * An invokable controller has a single __invoke method, like a minimal-API
 * endpoint handler in .NET instead of a controller with several actions.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = 'ok';
        $pgTrgm = null;

        try {
            // Returns the extension version, or null if it is not installed.
            $pgTrgm = DB::scalar("SELECT extversion FROM pg_extension WHERE extname = 'pg_trgm'");
        } catch (Throwable $e) {
            // Log the details, but never send them to the client: connection
            // errors can contain host names or usernames.
            Log::error('Health check: database unreachable', ['exception' => $e]);
            $database = 'error';
        }

        $healthy = $database === 'ok' && $pgTrgm !== null;

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'database' => $database,
            'pg_trgm' => $pgTrgm,
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }
}
