<?php

declare(strict_types=1);

namespace App\Modules\Auth\Support;

/**
 * Telefone móvel angolano normalizado em E.164 (+2449XXXXXXXX).
 *
 * Aceita as formas comuns: "923 456 789", "923-456-789", "+244 923 456 789", "00244923456789", "244923456789".
 */
final class PhoneNumber
{
    private const PATTERN = '/^\+2449\d{8}$/';

    private function __construct(public readonly string $e164) {}

    public static function parse(string $input): ?self
    {
        $digits = (string) preg_replace('/\D+/', '', $input);

        $local = match (true) {
            str_starts_with($digits, '00244') => substr($digits, 5),
            str_starts_with($digits, '244') && strlen($digits) === 12 => substr($digits, 3),
            default => $digits,
        };

        $e164 = '+244'.$local;

        return preg_match(self::PATTERN, $e164) === 1 ? new self($e164) : null;
    }

    public static function normalize(string $input): ?string
    {
        return self::parse($input)?->e164;
    }

    /**
     * Forma para apresentar: +244 923 456 789
     */
    public function formatted(): string
    {
        return sprintf('+244 %s %s %s', substr($this->e164, 4, 3), substr($this->e164, 7, 3), substr($this->e164, 10, 3));
    }

    /**
     * Forma parcialmente escondida para mensagens e logs: +244 9•• ••• 789
     */
    public function masked(): string
    {
        return '+244 '.substr($this->e164, 4, 1).'•• ••• '.substr($this->e164, -3);
    }
}
