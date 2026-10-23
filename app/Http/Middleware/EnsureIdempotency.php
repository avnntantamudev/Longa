<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Api\ApiException;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Cabeçalho Idempotency-Key obrigatório nos endpoints financeiros (uso: ->middleware('idempotent')).
 *
 *  - Primeira vez: executa e guarda a resposta (2xx/4xx) durante 24 h.
 *  - Repetição com a mesma chave e o mesmo pedido: devolve a resposta guardada (Idempotent-Replayed: true).
 *  - Mesma chave com outro pedido: 422 idempotency_key_reused.
 *  - Mesma chave ainda em execução (pedido paralelo): 409 request_in_progress.
 *  - Erro 5xx: a chave é libertada para o cliente poder repetir.
 *
 * Camada adicional: as operações de dinheiro têm também chaves próprias no ledger (não dependem disto).
 */
class EnsureIdempotency
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $userId = $request->user()?->getAuthIdentifier();

        if (! is_string($key) || preg_match('/^[A-Za-z0-9_\-]{8,100}$/', $key) !== 1) {
            throw new ApiException('idempotency_key_required', 'Falta o cabeçalho Idempotency-Key (8 a 100 caracteres: letras, números, - ou _).', 400);
        }

        if ($userId === null) {
            return $next($request);
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->all()));

        $stored = DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->where('expires_at', '>', now())->first();

        if ($stored !== null) {
            return $this->replay($stored, $hash);
        }

        DB::table('idempotency_keys')->where('user_id', $userId)->where('key', $key)->where('expires_at', '<=', now())->delete();

        try {
            $id = DB::table('idempotency_keys')->insertGetId([
                'user_id' => $userId,
                'key' => $key,
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 255),
                'request_hash' => $hash,
                'status' => 'processing',
                'created_at' => now(),
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ApiException('request_in_progress', 'Este pedido já está a ser processado. Aguarde.', 409);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Exceções de domínio (4xx) também se guardam: repetir dá a mesma resposta
            if ($e instanceof ApiException && $e->status < 500) {
                $this->complete($id, $e->status, json_encode(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details ?: null]]) ?: '');
            } else {
                DB::table('idempotency_keys')->where('id', $id)->delete();
            }

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            DB::table('idempotency_keys')->where('id', $id)->delete();
        } else {
            $this->complete($id, $response->getStatusCode(), (string) $response->getContent());
        }

        return $response;
    }

    private function replay(object $stored, string $hash): Response
    {
        if ($stored->request_hash !== $hash) {
            throw new ApiException('idempotency_key_reused', 'Esta Idempotency-Key já foi usada com outro pedido.', 422);
        }

        if ($stored->status !== 'completed') {
            throw new ApiException('request_in_progress', 'Este pedido já está a ser processado. Aguarde.', 409);
        }

        $response = new JsonResponse(json_decode((string) $stored->response_body, true), (int) $stored->response_status);
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }

    private function complete(int $id, int $status, string $body): void
    {
        DB::table('idempotency_keys')->where('id', $id)->update([
            'status' => 'completed',
            'response_status' => $status,
            'response_body' => $body,
        ]);
    }
}
