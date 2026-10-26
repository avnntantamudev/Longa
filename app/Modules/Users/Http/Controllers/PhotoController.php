<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\ProfilePhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Foto de perfil.
 *
 * @tags Perfil
 */
class PhotoController extends Controller
{
    /**
     * Carregar ou trocar a foto (multipart, campo `photo`).
     *
     * JPG, PNG ou WebP até 5 MB (tipo verificado pelo conteúdo). É recortada em quadrado e recodificada;
     * os metadados (incluindo GPS) são removidos.
     */
    public function store(Request $request, ProfilePhotos $photos): UserResource
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120', 'dimensions:min_width=200,min_height=200'],
        ], [
            'photo.mimes' => 'Só são aceites fotos JPG, PNG ou WebP.',
            'photo.mimetypes' => 'Só são aceites fotos JPG, PNG ou WebP.',
            'photo.max' => 'A foto pode ter no máximo 5 MB.',
            'photo.dimensions' => 'A foto tem de ter pelo menos 200×200 píxeis.',
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('photo');

        return new UserResource($photos->store($this->user($request), $file));
    }

    /**
     * Remover a foto.
     */
    public function destroy(Request $request, ProfilePhotos $photos): UserResource
    {
        return new UserResource($photos->remove($this->user($request)));
    }

    /**
     * Ficheiro da foto. Nome aleatório e imutável: cache de 1 ano.
     *
     * @unauthenticated
     */
    public function show(string $name, int $size, ProfilePhotos $photos): StreamedResponse
    {
        $path = $photos->path($name, $size);
        abort_unless(in_array($size, ProfilePhotos::SIZES, true) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
