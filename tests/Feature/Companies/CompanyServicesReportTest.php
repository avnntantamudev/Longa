<?php

declare(strict_types=1);

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyEmployee;
use App\Modules\Notifications\Notifications\CompanyConsentRequestNotification;
use App\Modules\Requests\Models\ServiceRequest;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Notification::fake());

/**
 * Empresa com um gestor RH e um serviço concluído do cliente indicado.
 *
 * @return array{0: Company, 1: User, 2: ServiceRequest}
 */
function companyWithLesson(): array
{
    $rh = User::factory()->withRole(Role::CompanyRh)->create();
    $company = Company::query()->create(['name' => 'Sonangol Serviço']);
    $company->managers()->attach($rh->id);

    $session = paidSession(['duration_minutes' => 90, 'scheduled_at' => now()->subDay()]);
    $session->forceFill(['state' => 'paid'])->save();

    return [$company, $rh, $session->fresh() ?? $session];
}

function addEmployee(Company $company, User $rh, string $phone, string $department = 'Finanças'): int
{
    apiLogin($rh);

    return test()->postJson("/api/v1/companies/{$company->id}/employees", ['name' => 'Ana Colaboradora', 'phone' => $phone, 'department' => $department])
        ->assertCreated()->json('data.id');
}

it('o colaborador com conta recebe o pedido; sem autorizar, a empresa não vê nenhum serviço', function () {
    [$company, $rh, $session] = companyWithLesson();
    $employeeId = addEmployee($company, $rh, $session->client->phone);

    Notification::assertSentTo($session->client, CompanyConsentRequestNotification::class);
    $this->getJson("/api/v1/companies/{$company->id}/employees")->assertJsonPath('data.0.consent_status', 'pending');

    $this->getJson("/api/v1/companies/{$company->id}/reports/services?from=".now()->subWeek()->toDateString())
        ->assertOk()->assertJsonPath('data.totals.lessons', 0)->assertJsonPath('data.totals.consented', 0);
    expect(CompanyEmployee::query()->find($employeeId)?->consent_status)->toBe('pending');
});

it('depois de aceitar, a empresa vê serviços, horas e custo (sem dados a mais); retirar volta a esconder', function () {
    [$company, $rh, $session] = companyWithLesson();
    $employeeId = addEmployee($company, $rh, $session->client->phone);

    apiLogin($session->client);
    $this->getJson('/api/v1/me/company-links')->assertJsonPath('data.0.company', 'Sonangol Serviço');
    $this->postJson("/api/v1/me/company-links/{$employeeId}", ['decision' => 'accepted'])->assertOk()->assertJsonPath('data.consent_status', 'accepted');

    apiLogin($rh);
    $data = $this->getJson("/api/v1/companies/{$company->id}/reports/services?from=".now()->subWeek()->toDateString())->assertOk()->json('data');
    expect($data['totals'])->toMatchArray(['lessons' => 1, 'hours' => 1.5, 'cost' => 25000, 'consented' => 1])
        ->and($data['by_department'][0]['department'])->toBe('Finanças')
        ->and(array_keys($data['lessons'][0]))->toBe(['date', 'employee', 'department', 'category', 'provider', 'hours', 'cost']);

    $this->get("/api/v1/companies/{$company->id}/reports/services/export?format=pdf&from=".now()->subWeek()->toDateString())->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get("/api/v1/companies/{$company->id}/reports/services/export?format=csv")->assertOk();

    apiLogin($session->client);
    $this->postJson("/api/v1/me/company-links/{$employeeId}", ['decision' => 'revoked'])->assertOk();
    apiLogin($rh);
    $this->getJson("/api/v1/companies/{$company->id}/reports/services?from=".now()->subWeek()->toDateString())->assertJsonPath('data.totals.lessons', 0);
});

it('só o próprio decide; mudar o telefone repõe o pedido; outras empresas não veem o relatório', function () {
    [$company, $rh, $session] = companyWithLesson();
    $employeeId = addEmployee($company, $rh, $session->client->phone);

    apiLogin(User::factory()->create());
    $this->postJson("/api/v1/me/company-links/{$employeeId}", ['decision' => 'accepted'])->assertForbidden();

    apiLogin($session->client);
    $this->postJson("/api/v1/me/company-links/{$employeeId}", ['decision' => 'accepted'])->assertOk();

    apiLogin($rh);
    $this->patchJson("/api/v1/companies/{$company->id}/employees/{$employeeId}", ['phone' => '+244911222333'])->assertOk();
    expect(CompanyEmployee::query()->find($employeeId)?->consent_status)->toBe('pending');

    apiLogin(User::factory()->withRole(Role::CompanyRh)->create());
    $this->getJson("/api/v1/companies/{$company->id}/reports/services")->assertForbidden();
});

it('um colaborador desativado sai do relatório, mesmo tendo autorizado', function () {
    [$company, $rh, $session] = companyWithLesson();
    $employeeId = addEmployee($company, $rh, $session->client->phone);

    apiLogin($session->client);
    $this->postJson("/api/v1/me/company-links/{$employeeId}", ['decision' => 'accepted'])->assertOk();

    apiLogin($rh);
    $from = now()->subWeek()->toDateString();
    $this->getJson("/api/v1/companies/{$company->id}/reports/services?from={$from}")->assertJsonPath('data.totals.lessons', 1);

    $this->patchJson("/api/v1/companies/{$company->id}/employees/{$employeeId}", ['status' => 'inactive'])->assertOk();
    $data = $this->getJson("/api/v1/companies/{$company->id}/reports/services?from={$from}")->json('data');
    expect($data['totals'])->toMatchArray(['lessons' => 0, 'employees' => 0])
        ->and($data['lessons'])->toBe([])
        ->and($data['by_employee'])->toBe([]);
});

it('a página da equipa abre para o RH (e /empresa leva lá); outros perfis não entram', function () {
    $rh = loginWeb(User::factory()->withRole(Role::CompanyRh)->create());

    $this->get('/empresa')->assertRedirect('/empresa/equipa');
    $this->get('/empresa/equipa')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Company/Team'));
    $this->get('/empresa/relatorios')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Company/Reports'));

    app('auth')->forgetGuards();
    loginWeb(User::factory()->withRole(Role::Client)->create());
    $this->get('/empresa/equipa')->assertForbidden();
});

it('mostra à parte os serviços que o colaborador prestou como técnico (faturado e líquido), sem o cliente', function () {
    [$company, $rh, $session] = companyWithLesson();
    $technician = $session->provider->user;
    $employeeId = addEmployee($company, $rh, $technician->phone, 'Técnicos');

    apiLogin($technician);
    $this->postJson("/api/v1/me/company-links/{$employeeId}", ['decision' => 'accepted'])->assertOk();

    apiLogin($rh);
    $from = now()->subWeek()->toDateString();
    $data = $this->getJson("/api/v1/companies/{$company->id}/reports/services?from={$from}")->assertOk()->json('data');

    $net = (int) ($session->provider_net ?? $session->amount - $session->commission);
    expect($data['totals']['lessons'])->toBe(0) // não pediu nada como cliente
        ->and($data['provided']['totals'])->toMatchArray(['services' => 1, 'hours' => 1.5, 'amount' => 25000, 'net' => $net])
        ->and($net)->toBeLessThan(25000)
        ->and($data['provided']['by_employee'][0])->toMatchArray(['department' => 'Técnicos', 'services' => 1])
        ->and(array_keys($data['provided']['services'][0]))->toBe(['date', 'employee', 'department', 'category', 'hours', 'amount', 'net'])
        ->and(json_encode($data))->not->toContain($session->client->name);

    $csv = $this->get("/api/v1/companies/{$company->id}/reports/services/export?format=csv&from={$from}")->assertOk()->streamedContent();
    expect($csv)->toContain('Serviços prestados')->toContain('Líquido');
});
