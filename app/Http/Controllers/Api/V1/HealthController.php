<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * GET /api/v1/health — estado da aplicação, base de dados e Redis.
 * Responde 200 quando tudo está operacional e 503 quando alguma dependência falha.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => ['status' => 'ok'],
            'database' => $this->check(fn () => DB::select('select 1')),
            'redis' => $this->check(fn () => Redis::connection()->ping()),
        ];

        $healthy = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok');

        return new JsonResponse([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'version' => config('app.version'),
            'time' => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * @return array{status: string, latency_ms?: float}
     */
    private function check(callable $probe): array
    {
        $start = microtime(true);

        try {
            $probe();
        } catch (Throwable $e) {
            Log::warning('health_check_failed', ['exception' => $e::class, 'message' => $e->getMessage()]);

            return ['status' => 'fail'];
        }

        return ['status' => 'ok', 'latency_ms' => round((microtime(true) - $start) * 1000, 2)];
    }
}
