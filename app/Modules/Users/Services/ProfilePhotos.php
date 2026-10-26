<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Fotos de perfil. A imagem enviada nunca é guardada tal como veio: é descodificada e recodificada
 * em WebP quadrado (512 px e 128 px), o que remove metadados (incluindo a localização GPS) e
 * conteúdo escondido. Nome aleatório; servida por /fotos/{nome}-{tamanho}.webp.
 */
class ProfilePhotos
{
    public const SIZES = [512, 128];

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(User $user, UploadedFile $file): User
    {
        $manager = new ImageManager(new Driver);
        $name = Str::lower(Str::random(32));

        foreach (self::SIZES as $size) {
            $encoded = $manager->read($file->getRealPath())->orient()->cover($size, $size)->toWebp(82);
            Storage::disk('local')->put($this->path($name, $size), (string) $encoded);
        }

        $previous = $user->photo;
        $user->forceFill(['photo' => $name, 'photo_updated_at' => now()])->save();
        $this->deleteFiles($previous);
        $this->audit->record('profile.photo_updated', $user, [], $user->id);

        return $user;
    }

    /**
     * Remover (pelo próprio ou pelo admin, ex.: foto imprópria).
     */
    public function remove(User $user, ?User $by = null, ?string $reason = null): User
    {
        $previous = $user->photo;
        $user->forceFill(['photo' => null, 'photo_updated_at' => now()])->save();
        $this->deleteFiles($previous);
        $this->audit->record('profile.photo_removed', $user, array_filter(['reason' => $reason]), ($by ?? $user)->id);

        return $user;
    }

    public function path(string $name, int $size): string
    {
        return "photos/{$name}-{$size}.webp";
    }

    private function deleteFiles(?string $name): void
    {
        if ($name !== null) {
            Storage::disk('local')->delete(array_map(fn (int $size) => $this->path($name, $size), self::SIZES));
        }
    }
}
