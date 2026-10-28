<?php

declare(strict_types=1);

namespace App\Modules\Sessions\Services;

/**
 * Esconde telefones e e-mails escritos no chat, para a comunicação ficar no BISCA nesta fase
 * ("sem partilha de contactos além do chat"). Desligável em config longa.chat.mask_contacts.
 */
class ContactMasker
{
    private const REPLACEMENT = '[contacto escondido]';

    public function mask(string $text): string
    {
        if (! config('longa.chat.mask_contacts')) {
            return $text;
        }

        // E-mails
        $text = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', self::REPLACEMENT, $text);

        // Telemóveis angolanos (com ou sem +244/00244, com espaços, pontos ou hífens)
        return (string) preg_replace('/(?:(?:\+|00)\s?244[\s.-]?)?9\d{2}[\s.-]?\d{3}[\s.-]?\d{3}\b/', self::REPLACEMENT, $text);
    }
}
