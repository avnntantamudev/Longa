<?php

declare(strict_types=1);

use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Inertia\Testing\AssertableInertia;

it('o formador abre o QR e o aluno o leitor; cada um só o seu', function () {
    $session = paidSession();

    loginWeb($session->trainer->user);
    $this->get("/aulas/{$session->id}/qr")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Sessions/Qr')->where('sessionId', $session->id));
    $this->get("/aulas/{$session->id}/confirmar")->assertForbidden();

    loginWeb($session->student);
    $this->get("/aulas/{$session->id}/confirmar")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Sessions/Scan'));
    $this->get("/aulas/{$session->id}/qr")->assertForbidden();
    $this->get("/aulas/{$session->id}/disputa")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Sessions/Dispute')->where('contest', false));

    loginWeb(User::factory()->withRole(Role::Student)->create());
    $this->get("/aulas/{$session->id}/disputa")->assertForbidden();
});

it('a carteira é só para formadores', function () {
    $session = paidSession();

    loginWeb($session->trainer->user);
    $this->get('/carteira')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Wallet/Show'));

    loginWeb($session->student);
    $this->get('/carteira')->assertForbidden();
});
