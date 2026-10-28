<?php

declare(strict_types=1);

namespace App\Modules\Users\Services;

use App\Modules\Users\Models\Consent;
use App\Modules\Users\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Termos de Utilização e Política de Privacidade, versionados em ficheiros:
 * resources/legal/{terms,privacy}/<versão>.md. A versão em vigor é o ficheiro mais recente (ordem natural do
 * nome, ex.: 2026-10 < 2027-01). Publicar uma versão nova = juntar um ficheiro; no acesso seguinte quem ainda
 * não a aceitou tem de a aceitar (EnsureLegalAccepted).
 */
class LegalDocuments
{
    /** @var array<string, string> documento => título */
    public const DOCUMENTS = [
        ConsentRecorder::TERMS => 'Termos de Utilização',
        ConsentRecorder::PRIVACY => 'Política de Privacidade',
    ];

    /** @var array<string, string> slug em português (URL) => documento */
    public const SLUGS = ['termos' => ConsentRecorder::TERMS, 'privacidade' => ConsentRecorder::PRIVACY];

    /** @var array<string, list<string>> */
    private array $versions = [];

    public static function isDocument(string $document): bool
    {
        return array_key_exists($document, self::DOCUMENTS);
    }

    /**
     * Versões publicadas, da mais antiga para a mais recente.
     *
     * @return list<string>
     */
    public function versions(string $document): array
    {
        $this->assertDocument($document);

        if (! isset($this->versions[$document])) {
            $files = glob($this->directory($document).DIRECTORY_SEPARATOR.'*.md') ?: [];
            $versions = array_map(fn (string $f) => pathinfo($f, PATHINFO_FILENAME), $files);
            usort($versions, 'strnatcmp');
            $this->versions[$document] = $versions;
        }

        return $this->versions[$document];
    }

    public function currentVersion(string $document): string
    {
        $versions = $this->versions($document);
        if ($versions === []) {
            throw new InvalidArgumentException("Não há nenhuma versão publicada de {$document} em ".$this->directory($document));
        }

        return $versions[count($versions) - 1];
    }

    /**
     * @return array{document: string, title: string, version: string, html: string}
     */
    public function show(string $document, ?string $version = null): array
    {
        $version ??= $this->currentVersion($document);
        if (! in_array($version, $this->versions($document), true)) {
            throw new InvalidArgumentException("Versão desconhecida: {$document} {$version}");
        }

        $markdown = (string) file_get_contents($this->directory($document).DIRECTORY_SEPARATOR.$version.'.md');

        return [
            'document' => $document,
            'title' => self::DOCUMENTS[$document],
            'version' => $version,
            // Ficheiros do próprio projeto; mesmo assim, HTML embutido é removido
            'html' => Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
        ];
    }

    /**
     * Documentos cuja versão em vigor o utilizador ainda não aceitou (ou cuja aceitação retirou).
     *
     * @return list<string>
     */
    public function pendingFor(User $user): array
    {
        $accepted = Consent::query()
            ->where('user_id', $user->id)
            ->whereIn('type', array_keys(self::DOCUMENTS))
            ->whereNull('revoked_at')
            ->get(['type', 'version'])
            ->groupBy('type')
            ->map(fn ($rows) => $rows->pluck('version')->all());

        return array_values(array_filter(
            array_keys(self::DOCUMENTS),
            fn (string $doc) => ! in_array($this->currentVersion($doc), $accepted->get($doc, []), true),
        ));
    }

    private function directory(string $document): string
    {
        return rtrim((string) config('longa.legal.path', resource_path('legal')), '/\\').DIRECTORY_SEPARATOR.$document;
    }

    private function assertDocument(string $document): void
    {
        if (! self::isDocument($document)) {
            throw new InvalidArgumentException("Documento legal desconhecido: {$document}");
        }
    }
}
