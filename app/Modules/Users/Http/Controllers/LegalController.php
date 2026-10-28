<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\ConsentRecorder;
use App\Modules\Users\Services\LegalDocuments;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Termos de Utilização e Política de Privacidade: leitura (pública) e nova aceitação quando a versão muda.
 *
 * @tags Termos e privacidade
 */
class LegalController extends Controller
{
    public function __construct(private readonly LegalDocuments $legal) {}

    /** Página com o texto completo (/legal/termos, /legal/privacidade). */
    public function page(string $slug): Response
    {
        return Inertia::render('Legal/Show', ['document' => $this->legal->show(LegalDocuments::SLUGS[$slug])]);
    }

    /**
     * Texto completo em vigor (HTML), para abrir dentro da app antes de aceitar.
     *
     * @unauthenticated
     */
    public function show(string $document): JsonResponse
    {
        return response()->json(['data' => $this->legal->show($document)]);
    }

    /**
     * Documentos que o utilizador ainda tem de aceitar (versão nova publicada).
     */
    public function pending(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->pendingList($this->user($request))]);
    }

    /**
     * Aceitar a versão em vigor dos documentos pendentes. Têm de vir todos.
     */
    public function accept(Request $request): JsonResponse
    {
        $this->record($request);

        return response()->json(['data' => []]);
    }

    /** Ecrã "Atualizámos os nossos termos" (redireciona quem tem documentos por aceitar). */
    public function acceptPage(Request $request): Response|RedirectResponse
    {
        $pending = $this->pendingList($this->user($request));
        if ($pending === []) {
            return redirect()->intended(route('home'));
        }

        return Inertia::render('Legal/Accept', ['documents' => $pending]);
    }

    public function acceptFromPage(Request $request): RedirectResponse
    {
        $this->record($request);

        return redirect()->intended(route('home'))->with('success', 'Obrigado. Pode continuar.');
    }

    private function record(Request $request): void
    {
        $user = $this->user($request);
        $pending = $this->legal->pendingFor($user);

        $data = $request->validate([
            'documents' => ['required', 'array'],
            'documents.*' => ['string', Rule::in(array_keys(LegalDocuments::DOCUMENTS))],
        ], [], ['documents' => 'documentos']);

        $missing = array_diff($pending, $data['documents']);
        if ($missing !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'documents' => 'Tem de aceitar: '.implode(' e ', array_map(fn (string $d) => LegalDocuments::DOCUMENTS[$d], $missing)).'.',
            ]);
        }

        app(ConsentRecorder::class)->record($user, array_values(array_intersect($pending, $data['documents'])));
        app(AuditLogger::class)->record('legal.accepted', $user, ['documents' => array_map(
            fn (string $d) => ['document' => $d, 'version' => $this->legal->currentVersion($d)],
            $pending,
        )]);
    }

    /**
     * @return list<array{document: string, slug: string, title: string, version: string}>
     */
    private function pendingList(User $user): array
    {
        $slugs = array_flip(LegalDocuments::SLUGS);

        return array_map(fn (string $d) => [
            'document' => $d,
            'slug' => $slugs[$d],
            'title' => LegalDocuments::DOCUMENTS[$d],
            'version' => $this->legal->currentVersion($d),
        ], $this->legal->pendingFor($user));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
