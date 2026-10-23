<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Converte qualquer exceção num erro da API no formato de ApiError.
 * Aplica-se a pedidos em /api/* e a pedidos que esperam JSON (exceto Inertia).
 */
final class ApiExceptionRenderer
{
    public static function shouldRender(Request $request): bool
    {
        if ($request->header('X-Inertia')) {
            return false;
        }

        return $request->is('api/*') || $request->expectsJson();
    }

    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::shouldRender($request)) {
            return null;
        }

        return match (true) {
            $e instanceof ApiException => ApiError::response($e->errorCode, $e->getMessage(), $e->status, $e->details),
            $e instanceof ValidationException => ApiError::response(
                'validation_failed',
                'Os dados enviados são inválidos.',
                422,
                $e->errors(),
            ),
            $e instanceof AuthenticationException => ApiError::response('unauthenticated', 'É necessário iniciar sessão.', 401),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiError::response(
                'forbidden',
                'Não tem permissão para realizar esta ação.',
                403,
            ),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiError::response(
                'not_found',
                'O recurso pedido não existe.',
                404,
            ),
            $e instanceof MethodNotAllowedHttpException => ApiError::response('method_not_allowed', 'Método não permitido.', 405),
            $e instanceof TokenMismatchException => ApiError::response('session_expired', 'A sessão expirou. Atualize a página e tente novamente.', 419),
            $e instanceof ThrottleRequestsException => ApiError::response(
                'too_many_requests',
                'Demasiados pedidos. Aguarde um pouco e tente novamente.',
                429,
                [],
                self::stringHeaders($e->getHeaders()),
            ),
            $e instanceof HttpExceptionInterface => ApiError::response(
                'http_error',
                $e->getStatusCode() === 503 ? 'Serviço temporariamente indisponível.' : 'Não foi possível processar o pedido.',
                $e->getStatusCode(),
                [],
                self::stringHeaders($e->getHeaders()),
            ),
            default => ApiError::response(
                'server_error',
                'Ocorreu um erro inesperado. Tente novamente mais tarde.',
                500,
                config('app.debug') ? ['exception' => $e::class, 'message' => $e->getMessage()] : [],
            ),
        };
    }

    /**
     * @param  array<mixed>  $headers
     * @return array<string, string>
     */
    private static function stringHeaders(array $headers): array
    {
        return array_map(static fn ($value): string => is_array($value) ? implode(', ', $value) : (string) $value, $headers);
    }
}
