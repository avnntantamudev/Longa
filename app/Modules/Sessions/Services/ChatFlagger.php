<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

/**
 * Deteta mensagens a rever pela equipa: contactos pessoais (telefone, e-mail), links de contacto (WhatsApp,
 * Telegram, redes sociais) e frases de quem quer combinar ou pagar fora da plataforma.
 */
class ChatFlagger
{
    /** Frases de desvio (texto em minúsculas e sem acentos) */
    private const OFF_PLATFORM = [
        'por fora', 'fora da app', 'fora da aplicacao', 'fora da plataforma', 'fora do bisca', 'sem o bisca',
        'paga-me directo', 'paga-me direto', 'pagar directo', 'pagar direto', 'pagamento directo', 'pagamento direto',
        'transferencia directa', 'transferencia direta', 'o meu numero', 'meu numero', 'liga-me', 'ligue-me',
        'manda mensagem', 'chama no whatsapp', 'whatsapp', 'whats app', 'telegram', 'cancela e eu', 'cancele e eu',
        'mais barato sem', 'sem comissao',
    ];

    /**
     * @return list<string> motivos: phone | email | link | off_platform (vazio = não sinalizar)
     */
    public function reasons(string $text): array
    {
        $reasons = [];
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text) === 1) {
            $reasons[] = 'email';
        }
        // Telemóveis angolanos, também escritos com espaços, pontos ou por extenso em dígitos separados
        $digits = (string) preg_replace('/[^\d]/', '', $text);
        if (preg_match('/(?:(?:\+|00)\s?244[\s.-]?)?9\d{2}[\s.-]?\d{3}[\s.-]?\d{3}\b/', $text) === 1 || preg_match('/(?:244)?9\d{8}/', $digits) === 1) {
            $reasons[] = 'phone';
        }
        if (preg_match('~(https?://|www\.|wa\.me|t\.me|facebook\.com|instagram\.com|fb\.me)~i', $text) === 1) {
            $reasons[] = 'link';
        }
        $plain = strtolower(strtr($text, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ç' => 'c']));
        foreach (self::OFF_PLATFORM as $phrase) {
            if (str_contains($plain, $phrase)) {
                $reasons[] = 'off_platform';
                break;
            }
        }

        return $reasons;
    }
}
