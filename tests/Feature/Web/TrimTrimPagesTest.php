<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Category;
use App\Modules\Users\Models\ProviderProfile;
use App\Modules\Users\Models\User;
use Database\Factories\ProviderProfileFactory;
use Inertia\Testing\AssertableInertia as Assert;

it('o aluno abre o formulário e o ecrã de procura dos seus pedidos', function () {
    $request = ServiceRequest::factory()->create();
    loginWeb($request->client);

    $this->get('/pedidos/novo')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Requests/Create')->has('categories')->has('zones')->where('minAmount', 2000));
    $this->get("/pedidos/{$request->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Requests/Show')->where('requestId', $request->id));
});

it('um aluno não abre o pedido de outro', function () {
    $request = ServiceRequest::factory()->create();
    loginWeb(User::factory()->withRole(Role::Client)->create());

    $this->get("/pedidos/{$request->id}")->assertForbidden();
});

it('o formador abre só as suas ofertas e os alunos não têm acesso ao TRIM TRIM', function () {
    $sgme = Category::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = ProviderProfileFactory::TALATONA;
    [$a, $b] = [ProviderProfile::factory()->verified()->near($lat, $lng, 300)->withCategories($sgme)->create(), ProviderProfile::factory()->verified()->near($lat, $lng, 600)->withCategories($sgme)->create()];
    $request = ServiceRequest::factory()->forCategory($sgme)->create();
    app(MatchingCoordinator::class)->start($request);
    $offerOfB = RequestOffer::query()->where('provider_profile_id', $b->id)->firstOrFail();

    loginWeb($a->user);
    $this->get('/formador/ofertas')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Provider/Offers'));
    $this->get("/formador/ofertas/{$offerOfB->id}")->assertForbidden();

    loginWeb($request->client);
    $this->get('/formador/ofertas')->assertForbidden();
});

it('as aulas exigem sessão iniciada', function () {
    $this->get('/aulas')->assertRedirect('/entrar');
});
