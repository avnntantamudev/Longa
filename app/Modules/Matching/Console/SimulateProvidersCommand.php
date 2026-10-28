<?php

declare(strict_types=1);

namespace App\Modules\Matching\Console;

use App\Modules\Requests\Actions\AcceptOffer;
use App\Modules\Requests\Enums\OfferState;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Enums\VerificationStatus;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\ProfilePhotos;
use App\Support\Api\ApiException;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

/**
 * Simula formadores para testar o TRIM TRIM localmente (só em ambientes não produtivos).
 *
 *   php artisan longa:simulate-providers 8 --zone=Talatona --category=sgme-gestao-empresarial
 *   php artisan longa:simulate-providers 8 --auto-accept     # aceitam ofertas com atraso aleatório (corridas reais)
 *
 * Os formadores criados têm telefones +244 91 000 0001… e podem entrar na PWA por OTP.
 */
class SimulateProvidersCommand extends Command
{
    protected $signature = 'longa:simulate-providers
        {count=5 : Quantos formadores criar}
        {--zone=Talatona : Bairro (config/locations.php) à volta do qual ficam os formadores}
        {--category=sgme-gestao-empresarial : Slug da área de formação}
        {--radius=4000 : Distância máxima (m) ao centro da zona}
        {--auto-accept : Fica a correr e aceita ofertas destes formadores com atraso aleatório}
        {--min-delay=2 : Atraso mínimo (s) antes de aceitar}
        {--max-delay=15 : Atraso máximo (s) antes de aceitar}
        {--duration=0 : Com --auto-accept, parar ao fim de N segundos (0 = até Ctrl+C)}';

    protected $description = 'Cria formadores verificados perto de uma zona e, opcionalmente, simula aceitações';

    public function handle(AcceptOffer $accept): int
    {
        if (app()->isProduction()) {
            $this->error('Não disponível em produção.');

            return self::FAILURE;
        }

        $zone = Arr::first((array) config('locations.zones'), fn (mixed $z) => is_array($z) && ($z['neighborhood'] ?? null) === $this->option('zone'));
        $category = Category::query()->where('slug', $this->option('category'))->first();

        if ($zone === null || $category === null) {
            $this->error('Zona ou área de formação desconhecida.');

            return self::FAILURE;
        }

        $providers = collect(range(1, (int) $this->argument('count')))->map(function (int $i) use ($zone, $category) {
            $phone = sprintf('+24491000%04d', $i);
            $user = User::query()->firstOrCreate(['phone' => $phone], [
                'name' => "Formador Simulado {$i}",
                'phone_verified_at' => now(),
                'onboarded_at' => now(),
            ]);
            $user->assignRole(Role::Provider->value);

            // Posição aleatória dentro do raio pedido
            $angle = random_int(0, 359) * M_PI / 180;
            $meters = random_int(200, (int) $this->option('radius'));
            $profile = ProviderProfile::query()->firstOrNew(['user_id' => $user->id]);
            $profile->user_id = $user->id;
            $profile->fill([
                'bio' => 'Formador simulado para testes do TRIM TRIM.',
                'modalities' => ['in_person', 'online'],
                'municipality' => $zone['municipality'],
                'neighborhood' => $zone['neighborhood'],
                'latitude' => $zone['lat'] + ($meters * cos($angle)) / 111_320,
                'longitude' => $zone['lng'] + ($meters * sin($angle)) / (111_320 * cos(deg2rad($zone['lat']))),
                'service_radius_m' => 5000,
                'is_available' => true,
                'onboarding_completed_at' => now(),
            ]);
            $profile->verification_status = VerificationStatus::Verified;
            $profile->score = random_int(40, 95);
            $profile->save();
            $profile->categories()->syncWithoutDetaching([$category->id]);

            // Foto obrigatória para receber pedidos: um quadrado colorido gerado na hora
            if ($user->photo === null) {
                $image = imagecreatetruecolor(400, 400);
                imagefill($image, 0, 0, (int) imagecolorallocate($image, random_int(40, 200), random_int(40, 200), random_int(40, 200)));
                $path = tempnam(sys_get_temp_dir(), 'sim').'.png';
                imagepng($image, $path);
                app(ProfilePhotos::class)->store($user, new UploadedFile($path, 'simulado.png', 'image/png', null, true));
                @unlink($path);
            }

            return $profile;
        });

        $this->table(['Telefone', 'Nome', 'Score'], $providers->map(fn (ProviderProfile $p) => [$p->user->phone, $p->user->name, $p->score])->all());
        $this->info("{$providers->count()} formadores disponíveis em {$zone['neighborhood']} para {$category->name}.");

        if (! $this->option('auto-accept')) {
            return self::SUCCESS;
        }

        $this->info('A aguardar ofertas (Ctrl+C para parar)…');
        $ids = $providers->pluck('id')->all();
        $scheduled = [];
        $duration = (int) $this->option('duration');
        $until = $duration > 0 ? microtime(true) + $duration : null;

        while ($until === null || microtime(true) < $until) {
            $offers = RequestOffer::query()
                ->whereIn('provider_profile_id', $ids)
                ->whereIn('state', OfferState::open())
                ->where('expires_at', '>', now())
                ->get();

            foreach ($offers as $offer) {
                $scheduled[$offer->id] ??= microtime(true) + random_int((int) $this->option('min-delay'), (int) $this->option('max-delay'));

                if (microtime(true) >= $scheduled[$offer->id]) {
                    unset($scheduled[$offer->id]);
                    try {
                        $session = $accept->handle($offer);
                        $this->info("Oferta {$offer->id}: aceite, aula {$session->id}");
                    } catch (ApiException $e) {
                        $this->line("Oferta {$offer->id}: {$e->getMessage()}");
                    }
                }
            }

            usleep(500_000);
        }

        return self::SUCCESS;
    }
}
