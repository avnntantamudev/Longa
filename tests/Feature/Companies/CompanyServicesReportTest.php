<?php

declare(strict_types=1);

use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Models\CompanyEmployee;
use App\Modules\Notifications\Notifications\CompanyConsentRequestNotification;
use App\Modules\Sessions\Models\ServiceJob;
use App\Modules\Users\Enums\Role;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

/**
 * Empresa com um gestor RH e uma aula concluída do aluno indicado.
 *
 * @return array{0: Company, 1: User, 2: ServiceJob}
 */
function companyWithLesson(): array
{
    $rh = User::factory()->withRole(Role::CompanyRh)->create();
    $company = Company::query()->create(['name' => 'Sonangol Formação']);
    $company->managers()->attach($rh->id);

    $session = paidSession(['duration_minutes' => 90, 'scheduled_at' => now()->subDay()]);
    $session->forceFill(['state' => 'completed'])->save();

    return [$company, $rh, $session->fresh() ?? $session];
}

function addEmployee(Company $company, User $rh, string $phone, string $department = 'Finanças'): int
{
    apiLogin($rh);

    return test()->postJson("/api/v1/companies/{$company->id}/employees", ['name' => 'Ana Colaboradora', 'phone' => $phone, 'department' => $department])
        ->assertCreated()->json('data.id');
}

it('o colaborador com conta recebe o pedido; sem autorizar, a empresa não vê nenhuma aula', function () {
    [$company, $rh, $session] = companyWithLesson();
    $employeeId = addEmployee($company, $rh, $session->client->phone);

    Notification::assertSentTo($session->client, CompanyConsentRequestNotification::class);
    $this->getJson("/api/v1/companies/{$company->id}/employees")->assertJsonPath('data.0.consent_status', 'pending');

    $this->getJson("/api/v1/companies/{$company->id}/reports/services?from=".now()->subWeek()->toDateString())
        ->assertOk()->assertJsonPath('data.totals.lessons', 0)->assertJsonPath('data.totals.consented', 0);
    expect(CompanyEmployee::query()->find($employeeId)?->consent_status)->toBe('pending');
});

it('depois de aceitar, a empresa vê aulas, horas e custo (sem dados a mais); retirar volta a esconder', function () {
    [$company, $rh, $session] = companyWithLesson();
    $employeeId = addEmployee($company, $rh, $session->client->phone);

    apiLogin($session->client);
    $this->getJson('/api/v1/me/company-links')->assertJsonPath('data.0.company', 'Sonangol Formação');
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
