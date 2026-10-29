<?php

declare(strict_types=1);

use App\Modules\Requests\Events\OfferSent;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Requests\Models\ServiceRequestMedia;
use App\Modules\Users\Enums\PricingMode;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
| BISCA, Fase 2.1: o pedido segue o formulário da categoria (respostas, fotos ou vídeo), só aceita as modalidades
| da categoria e fixa o valor pelo modo de preço (fixo: tabela, bloqueado no envio; orçamento: 0 até ao orçamento).
*/

beforeEach(function () {
    $this->travelTo(now()->setTime(8, 0));
    Event::fake([OfferSent::class]);
    Storage::fake('local');
    $this->client = User::factory()->withRole(Role::Client)->create();
    $this->cleaning = Category::query()->where('slug', 'limpeza')->firstOrFail();
    $this->ac = Category::query()->where('slug', 'manutencao-de-ar-condicionado')->firstOrFail();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function homePayload(Category $category, array $overrides = []): array
{
    [$lat, $lng] = ProviderProfileFactory::TALATONA;

    return [
        'category_id' => $category->id,
        'scheduled_at' => now()->setTime(14, 0)->toIso8601String(),
        'modality' => 'in_person',
        'municipality' => 'Talatona',
        'neighborhood' => 'Talatona',
        'latitude' => $lat,
        'longitude' => $lng,
        ...$overrides,
    ];
}

it('o formulário mostra os campos da categoria (com os herdados) e o preço antes de enviar', function () {
    apiLogin($this->client);

    $form = $this->getJson("/api/v1/categories/{$this->cleaning->id}/form")->assertOk()->json('data');

    expect(array_column($form['fields'], 'key'))->toContain('descricao', 'fotos', 'tipologia')
        ->and($form['price']['fixed_price'])->toBe(20000);
});

it('preço fixo: o valor vem da tabela da categoria e fica bloqueado no envio', function () {
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', homePayload($this->cleaning, ['amount' => 5000, 'answers' => ['descricao' => 'Casa toda', 'tipologia' => 'T3']]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['amount']]]);

    $id = $this->postJson('/api/v1/service-requests', homePayload($this->cleaning, ['answers' => ['descricao' => 'Casa toda', 'tipologia' => 'T3']]))
        ->assertCreated()
        ->assertJsonPath('data.pricing_mode', 'fixed')
        ->assertJsonPath('data.amount', 20000)
        ->assertJsonPath('data.title', 'Limpeza')
        ->json('data.id');

    // Mudar a tabela depois não altera um pedido já enviado
    $this->cleaning->price()->update(['fixed_price' => 30000]);
    expect(ServiceRequest::query()->findOrFail($id)->amount)->toBe(20000);
});

it('guarda as respostas do formulário e valida os campos obrigatórios e as escolhas', function () {
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', homePayload($this->cleaning, ['answers' => ['tipologia' => 'T9']]))
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['answers.descricao', 'answers.tipologia']]]);

    $id = $this->postJson('/api/v1/service-requests', homePayload($this->cleaning, [
        'answers' => ['descricao' => 'Casa toda, com varanda', 'tipologia' => 'T3', 'campo_estranho' => 'ignorado'],
    ]))->assertCreated()
        ->assertJsonPath('data.answers.0', ['key' => 'descricao', 'label' => 'O que precisa?', 'value' => 'Casa toda, com varanda'])
        ->json('data.id');

    expect(ServiceRequest::query()->findOrFail($id)->answers)->toBe(['descricao' => 'Casa toda, com varanda', 'tipologia' => 'T3']);
});

it('só aceita as modalidades da categoria', function () {
    apiLogin($this->client);

    $this->postJson('/api/v1/service-requests', homePayload($this->cleaning, ['modality' => 'online', 'answers' => ['descricao' => 'x', 'tipologia' => 'T1']]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['modality']]]);
});

it('fotos: guardadas no disco privado e abertas só por quem pode ver o pedido, por link temporário', function () {
    $provider = ProviderProfile::factory()->verified()->near(...ProviderProfileFactory::TALATONA)->withCategories($this->ac)->create();
    apiLogin($this->client);

    $id = $this->post('/api/v1/service-requests', homePayload($this->ac, ['answers' => [
        'descricao' => 'Não arrefece', 'aparelhos' => 2,
        'fotos' => [UploadedFile::fake()->image('sala.jpg'), UploadedFile::fake()->image('quarto.png')],
    ]]), ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(2, 'data.media')->json('data.id');

    $media = ServiceRequestMedia::query()->where('service_request_id', $id)->get();
    expect($media)->toHaveCount(2)
        ->and($media->pluck('kind')->unique()->all())->toBe(['photo']);
    Storage::disk('local')->assertExists($media[0]->path);

    $url = $this->getJson("/api/v1/request-media/{$media[0]->id}")->assertOk()->json('data.url');
    $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

    // O técnico a quem o pedido foi oferecido vê as fotos e as respostas na oferta
    expect(RequestOffer::query()->where('provider_profile_id', $provider->id)->exists())->toBeTrue();
    apiLogin($provider->user);
    $this->getJson("/api/v1/request-media/{$media[0]->id}")->assertOk();
    $offer = RequestOffer::query()->where('provider_profile_id', $provider->id)->firstOrFail();
    $this->getJson("/api/v1/offers/{$offer->id}")->assertOk()
        ->assertJsonPath('data.pricing_mode', 'quote')
        ->assertJsonCount(2, 'data.media');

    // Outro utilizador não
    apiLogin(User::factory()->withRole(Role::Client)->create());
    $this->getJson("/api/v1/request-media/{$media[0]->id}")->assertForbidden();
});

it('recusa ficheiros que não são fotos', function () {
    apiLogin($this->client);

    $this->post('/api/v1/service-requests', homePayload($this->ac, ['answers' => [
        'descricao' => 'Não arrefece', 'aparelhos' => 1,
        'fotos' => [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')],
    ]]), ['Accept' => 'application/json'])->assertUnprocessable();

    expect(ServiceRequest::query()->count())->toBe(0);
});

it('por orçamento: valor a 0 até haver orçamento e sem pagamento antecipado quando o técnico aceita', function () {
    $provider = ProviderProfile::factory()->verified()->near(...ProviderProfileFactory::TALATONA)->withCategories($this->ac)->create();
    apiLogin($this->client);

    $id = $this->postJson('/api/v1/service-requests', homePayload($this->ac, ['answers' => ['descricao' => 'Não arrefece', 'aparelhos' => 1]]))
        ->assertCreated()->assertJsonPath('data.pricing_mode', 'quote')->assertJsonPath('data.amount', 0)->json('data.id');

    apiLogin($provider->user);
    $offer = RequestOffer::query()->where('service_request_id', $id)->where('provider_profile_id', $provider->id)->firstOrFail();
    $this->postJson("/api/v1/offers/{$offer->id}/accept")->assertOk();

    $request = ServiceRequest::query()->findOrFail($id);
    expect($request->pricing_mode)->toBe(PricingMode::Quote)
        ->and($request->payment_status)->toBeNull()
        ->and($request->awaitingPayment())->toBeFalse();
});
