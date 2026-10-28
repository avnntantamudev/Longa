<?php

declare(strict_types=1);

use App\Modules\Users\Models\Consent;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\LegalDocuments;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

/*
| Fase 1.5: Termos de Utilização e Política de Privacidade versionados, lidos dentro da app, aceites no registo
| (duas caixas) e aceites de novo quando sai uma versão nova.
*/

beforeEach(function () {
    // Cópia dos textos numa pasta temporária, para publicar versões novas sem tocar nos ficheiros do projeto
    $this->legalPath = storage_path('framework/testing/legal-'.Str::random(8));
    File::copyDirectory(resource_path('legal'), $this->legalPath);
    config(['longa.legal.path' => $this->legalPath]);
});

afterEach(function () {
    File::deleteDirectory($this->legalPath);
});

function publishVersion(string $document, string $version): void
{
    File::put(test()->legalPath."/{$document}/{$version}.md", "# Nova versão {$version}\n\nTexto atualizado.");
}

it('os textos estão versionados em ficheiros e abrem na app (API e página)', function () {
    $terms = $this->getJson('/api/v1/legal/terms')->assertOk()->json('data');
    expect($terms['title'])->toBe('Termos de Utilização')
        ->and($terms['version'])->toBe('2026-10')
        ->and($terms['html'])->toContain('<h1>Termos de Utilização do BISCA</h1>');

    $this->getJson('/api/v1/legal/privacy')->assertOk()->assertJsonPath('data.title', 'Política de Privacidade');
    $this->getJson('/api/v1/legal/outro')->assertNotFound();

    $this->get('/legal/privacidade')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Show')->where('document.document', 'privacy'));
});

it('a versão em vigor é o ficheiro mais recente', function () {
    publishVersion('terms', '2027-01');
    publishVersion('terms', '2026-11');

    expect(app(LegalDocuments::class)->currentVersion('terms'))->toBe('2027-01')
        ->and(app(LegalDocuments::class)->currentVersion('privacy'))->toBe('2026-10');
});

it('o registo exige as duas caixas e guarda a versão, a data e o IP de cada aceitação', function () {
    apiLogin($user = User::factory()->notOnboarded()->create());

    $this->postJson('/api/v1/onboarding', ['name' => 'Ana Costa', 'profiles' => ['client'], 'accept_terms' => true])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['accept_privacy']]]);

    $this->postJson('/api/v1/onboarding', ['name' => 'Ana Costa', 'profiles' => ['client'], 'accept_terms' => true, 'accept_privacy' => true], ['REMOTE_ADDR' => '197.149.1.10'])
        ->assertSuccessful();

    $consents = Consent::query()->where('user_id', $user->id)->whereIn('type', ['terms', 'privacy'])->get();
    expect($consents)->toHaveCount(2)
        ->and($consents->pluck('version')->unique()->all())->toBe(['2026-10'])
        ->and($consents->first()?->ip)->toBe('197.149.1.10')
        ->and($consents->first()?->accepted_at)->not->toBeNull();
});

it('com uma versão nova, quem já tem conta tem de aceitar no acesso seguinte', function () {
    $user = User::factory()->create();
    publishVersion('terms', '2027-01');

    loginWeb($user);
    $this->get('/perfil')->assertRedirect('/termos/aceitar');
    $this->get('/termos/aceitar')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Legal/Accept')
            ->has('documents', 1)->where('documents.0.document', 'terms')->where('documents.0.version', '2027-01'));

    // A API (PWA ou app) responde com um código próprio; ler os textos e o "me" continuam a funcionar
    apiLogin($user);
    $this->getJson('/api/v1/wallet')->assertForbidden()->assertJsonPath('error.code', 'legal_acceptance_required')
        ->assertJsonPath('error.details.documents', ['terms']);
    $this->getJson('/api/v1/me')->assertOk();
    $this->getJson('/api/v1/legal/terms')->assertOk()->assertJsonPath('data.version', '2027-01');
    $this->getJson('/api/v1/me/legal')->assertOk()->assertJsonPath('data.0.version', '2027-01');

    $this->postJson('/api/v1/me/legal/accept', ['documents' => ['terms']])->assertOk();
    expect(Consent::query()->where('user_id', $user->id)->where('type', 'terms')->pluck('version')->all())->toBe(['2026-10', '2027-01']);
    $this->getJson('/api/v1/me/legal')->assertOk()->assertJsonCount(0, 'data');
});

it('na página de aceitação tem de marcar todos os documentos novos', function () {
    $user = User::factory()->create();
    publishVersion('terms', '2027-01');
    publishVersion('privacy', '2027-01');
    loginWeb($user);

    $this->post('/termos/aceitar', ['documents' => ['terms']])->assertSessionHasErrors('documents');
    $this->get('/perfil')->assertRedirect('/termos/aceitar');

    $this->post('/termos/aceitar', ['documents' => ['terms', 'privacy']])->assertRedirect();
    $this->get('/perfil')->assertOk();
    expect(App\Models\AuditLog::query()->where('action', 'legal.accepted')->where('subject_id', (string) $user->id)->exists())->toBeTrue();
});

it('quem ainda não concluiu o registo não é travado (aceita no próprio registo)', function () {
    loginWeb(User::factory()->notOnboarded()->create());
    publishVersion('terms', '2027-01');

    $this->get('/onboarding')->assertOk();
});
