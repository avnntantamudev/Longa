<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Sem prefixo, todos os limites "throttle:N,M" do mesmo utilizador partilham um só contador (e a validade do limite
| mais longo): enviar documentos bloqueava o técnico de enviar orçamentos. Cada rota tem de ter o seu.
*/

it('todas as rotas com limite numérico têm um contador próprio', function () {
    $prefixes = [];
    foreach (Route::getRoutes() as $route) {
        // Rotas internas de pacotes (ex.: upload do Livewire) têm limites próprios
        if (str_starts_with($route->uri(), 'livewire')) {
            continue;
        }
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && preg_match('/^throttle:\d+,\d+(,(.+))?$/', $middleware, $m)) {
                expect($m[2] ?? null)->not->toBeNull("{$route->uri()} sem contador próprio");
                $prefixes[$m[2]][] = $route->uri();
            }
        }
    }

    expect($prefixes)->not->toBeEmpty();
    foreach ($prefixes as $prefix => $uris) {
        expect(array_unique($uris))->toHaveCount(1, "{$prefix} partilhado por ".implode(', ', $uris));
    }
});

it('esgotar o limite de uma ação não bloqueia as outras do mesmo técnico', function () {
    $session = acceptedSession('cash');
    $provider = $session->provider->user;
    apiLogin($provider);

    // Esgota o limite de 10 por hora do envio de documentos de verificação
    foreach (range(1, 10) as $i) {
        $this->postJson('/api/v1/me/verifications', [])->assertUnprocessable();
    }
    $this->postJson('/api/v1/me/verifications', [])->assertStatus(429);

    // As outras ações continuam a funcionar, mesmo com um limite igual ou mais baixo que o já esgotado
    $this->postJson("/api/v1/sessions/{$session->id}/messages/read")->assertOk();
    $this->postJson("/api/v1/sessions/{$session->id}/cancel", ['reason' => 'Imprevisto'], ['Idempotency-Key' => 'limits-cancel-1'])->assertOk();
});
