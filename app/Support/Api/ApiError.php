<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;

/**
 * Formato único das respostas de erro da API:
 *
 * {
 *   "error": {
 *     "code": "validation_failed",
 *     "message": "Os dados enviados são inválidos.",
 *     "details": { "phone": ["O telefone é obrigatório."] }
 *   },
 *   "request_id": "9b1d..."
 * }
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(
        string $code,
        string $message,
        int $status,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return new JsonResponse(
            ['error' => $error, 'request_id' => request()->attributes->get('request_id')],
            $status,
            $headers,
            JSON_UNESCAPED_UNICODE,
        );
    }
}
