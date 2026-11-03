<?php

declare(strict_types=1);

use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Services\HourlyPricing;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

/*
| Pacotes de várias aulas (passo 3): o cliente indica a data e hora de cada aula. O pedido-contrato guarda as
| datas, começa na primeira aula e é pago tudo no início; o desconto vem dos pacotes da categoria (4, 8 e 12 aulas
| com 5%, 10% e 15%). Só categorias que aceitam pacotes (no lançamento: Educação).
*/

beforeEach(function () {
    // Segunda-feira às 8h (hora de Angola), para as datas da semana seguinte serem previsíveis
    $this->travelTo(Carbon::parse('2026-11-09 08:00', 'Africa/Luanda'));
    Event::fake([OfferSent::class]);
    $this->sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    apiLogin(User::factory()->withRole(Role::Client)->create());
});

/**
 * Aulas às 9h a partir de amanhã, em dias seguidos.
 *
 * @return list<string>
 */
function pkgDates(int $count, int $startInDays = 1, string $time = '09:00'): array
{
    return array_map(
        fn (int $i) => now('Africa/Luanda')->addDays($startInDays + $i)->setTimeFromTimeString($time)->toIso8601String(),
        range(0, $count - 1),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pkgPayload(Category $category, array $overrides = []): array
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return [
        'category_id' => $category->id, 'answers' => ['disciplina' => 'SGME', 'nivel' => 'Profissional'],
        'max_hourly_rate' => 10000, 'duration_minutes' => 60, 'lesson_dates' => pkgDates(5),
        'modality' => 'in_person', 'municipality' => 'Talatona', 'neighborhood' => 'Talatona', 'latitude' => $lat, 'longitude' => $lng,
        ...$overrides,
    ];
}

it('cria um pacote com as datas das aulas, a começar na primeira e com o desconto dos pacotes', function () {
    $dates = pkgDates(5);
    $shuffled = [$dates[3], $dates[0], $dates[4], $dates[1], $dates[2]];

    $data = $this->postJson('/api/v1/service-requests', pkgPayload($this->sgme, ['lesson_dates' => $shuffled]))->assertCreated()->json('data');

    $request = ServiceRequest::query()->findOrFail($data['id']);
    expect($request->isPackage())->toBeTrue()
        ->and($request->sessions)->toBe(5)
        ->and($request->discount_percent)->toBe(5) // 5 aulas: desconto do pacote de 4
        ->and($request->amount)->toBe(HourlyPricing::total(10000, 60, 5, 5))
        ->and($request->payment_method)->toBe('electronic')
        ->and($request->scheduled_at->equalTo(Carbon::parse($dates[0])))->toBeTrue()
        ->and(array_map(fn ($d) => Carbon::parse($d)->timestamp, $request->lesson_schedule ?? []))
        ->toBe(array_map(fn ($d) => Carbon::parse($d)->timestamp, $dates))
        ->and($data['lesson_schedule'])->toHaveCount(5);

    // 10 aulas: desconto do pacote de 8
    $ten = $this->postJson('/api/v1/service-requests', pkgPayload($this->sgme, ['lesson_dates' => pkgDates(10, 8)]))->assertCreated()->json('data');
    expect(ServiceRequest::query()->findOrFail($ten['id']))->discount_percent->toBe(10)->sessions->toBe(10);
});

it('valida o pacote: limites de aulas, datas, prazo, sobreposição e pagamento', function (array $overrides, string $field) {
    $this->postJson('/api/v1/service-requests', pkgPayload($this->sgme, $overrides))->assertUnprocessable()->assertJsonValidationErrorFor($field, 'error.details');
})->with([
    'uma só aula' => [fn () => ['lesson_dates' => pkgDates(1)], 'lesson_dates'],
    'mais de 20 aulas' => [fn () => ['lesson_dates' => pkgDates(21)], 'lesson_dates'],
    'aula hoje' => [fn () => ['lesson_dates' => pkgDates(3, 0, '18:00')], 'lesson_dates.0'],
    'datas repetidas' => [fn () => ['lesson_dates' => [...pkgDates(2), pkgDates(1)[0]]], 'lesson_dates.0'],
    'mais de 4 semanas' => [fn () => ['lesson_dates' => [pkgDates(1)[0], pkgDates(1, 30)[0]]], 'lesson_dates'],
    'aulas sobrepostas' => [fn () => ['lesson_dates' => [pkgDates(1, 1, '09:00')[0], pkgDates(1, 1, '09:30')[0]]], 'lesson_dates'],
    'pagamento em mão' => [fn () => ['payment_method' => 'cash'], 'payment_method'],
    'com data única' => [fn () => ['scheduled_at' => pkgDates(1)[0]], 'scheduled_at'],
    'com pacote antigo' => [fn () => ['category_package_id' => 1], 'category_package_id'],
]);

it('categorias que não aceitam pacotes recusam várias datas', function () {
    $this->sgme->forceFill(['packages_enabled' => false])->save();

    $this->postJson('/api/v1/service-requests', pkgPayload($this->sgme))->assertUnprocessable()->assertJsonValidationErrorFor('lesson_dates', 'error.details');
});
