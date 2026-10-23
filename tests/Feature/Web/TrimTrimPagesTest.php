<?php

declare(strict_types=1);

use App\Modules\Matching\Services\MatchingCoordinator;
use App\Modules\Requests\Models\RequestOffer;
use App\Modules\Requests\Models\TrainingRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\Skill;
use App\Modules\Users\Models\TrainerProfile;
use App\Modules\Users\Models\User;
use Database\Factories\TrainerProfileFactory;
use Inertia\Testing\AssertableInertia as Assert;

it('o aluno abre o formulário e o ecrã de procura dos seus pedidos', function () {
    $request = TrainingRequest::factory()->create();
    loginWeb($request->student);

    $this->get('/pedidos/novo')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Requests/Create')->has('skills')->has('zones')->where('minAmount', 2000));
    $this->get("/pedidos/{$request->id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Requests/Show')->where('requestId', $request->id));
});

it('um aluno não abre o pedido de outro', function () {
    $request = TrainingRequest::factory()->create();
    loginWeb(User::factory()->withRole(Role::Student)->create());

    $this->get("/pedidos/{$request->id}")->assertForbidden();
});

it('o formador abre só as suas ofertas e os alunos não têm acesso ao TRIM TRIM', function () {
    $sgme = Skill::query()->where('slug', 'sgme-gestao-empresarial')->firstOrFail();
    [$lat, $lng] = TrainerProfileFactory::TALATONA;
    [$a, $b] = [TrainerProfile::factory()->verified()->near($lat, $lng, 300)->withSkills($sgme)->create(), TrainerProfile::factory()->verified()->near($lat, $lng, 600)->withSkills($sgme)->create()];
    $request = TrainingRequest::factory()->forSkill($sgme)->create();
    app(MatchingCoordinator::class)->start($request);
    $offerOfB = RequestOffer::query()->where('trainer_profile_id', $b->id)->firstOrFail();

    loginWeb($a->user);
    $this->get('/formador/ofertas')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Trainer/Offers'));
    $this->get("/formador/ofertas/{$offerOfB->id}")->assertForbidden();

    loginWeb($request->student);
    $this->get('/formador/ofertas')->assertForbidden();
});

it('as aulas exigem sessão iniciada', function () {
    $this->get('/aulas')->assertRedirect('/entrar');
});
