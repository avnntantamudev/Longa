<?php

declare(strict_types=1);

use App\Modules\Users\Models\User;

it('regista a empresa e torna o utilizador gestor RH', function () {
    $user = User::factory()->create();
    apiLogin($user);

    $this->postJson('/api/v1/companies', [
        'name' => 'Cantina Central Talatona',
        'nif' => '5001234567',
        'sector' => 'Alimentação',
        'municipality' => 'Talatona',
    ])->assertCreated()->assertJsonPath('data.name', 'Cantina Central Talatona');

    expect($user->fresh()?->hasRole('company_rh'))->toBeTrue();
    $this->getJson('/api/v1/companies')->assertOk()->assertJsonCount(1, 'data');
});

it('gere funcionários com telefone normalizado e liga contas BISCA', function () {
    apiLogin(User::factory()->create());
    $companyId = $this->postJson('/api/v1/companies', ['name' => 'Empresa X'])->json('data.id');
    $existing = User::factory()->create(['phone' => '+244923111222']);

    $employeeId = $this->postJson("/api/v1/companies/{$companyId}/employees", [
        'name' => 'João Pedro Martins', 'phone' => '923 111 222', 'department' => 'Operações',
    ])->assertCreated()
        ->assertJsonPath('data.phone', '+244923111222')
        ->assertJsonPath('data.has_longa_account', true)
        ->json('data.id');

    $this->postJson("/api/v1/companies/{$companyId}/employees", ['name' => 'Outro', 'phone' => '923111222'])
        ->assertStatus(422);

    $this->postJson("/api/v1/companies/{$companyId}/employees", ['name' => 'Maria Silva', 'department' => 'Vendas'])->assertCreated();

    $this->getJson("/api/v1/companies/{$companyId}/employees?department=Operações")->assertOk()->assertJsonCount(1, 'data');

    $this->patchJson("/api/v1/companies/{$companyId}/employees/{$employeeId}", ['status' => 'inactive'])
        ->assertOk()->assertJsonPath('data.status', 'inactive');

    $this->deleteJson("/api/v1/companies/{$companyId}/employees/{$employeeId}")->assertNoContent();

    expect($existing->id)->toBeInt();
});

it('impede outros utilizadores de ver ou gerir a empresa', function () {
    apiLogin(User::factory()->create());
    $companyId = $this->postJson('/api/v1/companies', ['name' => 'Empresa Y'])->json('data.id');

    apiLogin(User::factory()->create());

    $this->getJson("/api/v1/companies/{$companyId}")->assertForbidden();
    $this->getJson("/api/v1/companies/{$companyId}/employees")->assertForbidden();
    $this->postJson("/api/v1/companies/{$companyId}/employees", ['name' => 'Intruso'])->assertForbidden();
});
