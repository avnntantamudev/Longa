<?php

declare(strict_types=1);

namespace App\Modules\Requests\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Requests\Models\ServiceRequestMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos e vídeos do formulário do pedido: só o cliente, os técnicos a quem o pedido foi oferecido, o técnico
 * atribuído e a equipa os veem, por link temporário (o ficheiro está no disco privado).
 */
class RequestMediaController extends Controller
{
    /**
     * Link temporário para uma foto ou vídeo do pedido.
     */
    public function link(ServiceRequestMedia $media): JsonResponse
    {
        Gate::authorize('viewMedia', $media->request);

        $minutes = (int) config('longa.requests.media.link_minutes');
        $url = URL::temporarySignedRoute('requests.media', now()->addMinutes($minutes), ['media' => $media->id], absolute: false);

        return response()->json(['data' => ['url' => $url, 'expires_in' => $minutes * 60]]);
    }

    /**
     * Ficheiro (rota assinada; o link expira).
     *
     * @unauthenticated
     */
    public function download(ServiceRequestMedia $media): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($media->path), 404);

        return Storage::disk('local')->response($media->path, $media->original_name, [
            'Content-Type' => $media->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
