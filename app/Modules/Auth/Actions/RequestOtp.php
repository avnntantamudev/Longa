<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Modules\Auth\Services\OtpService;
use App\Modules\Auth\Support\PhoneNumber;
use App\Support\Api\ApiException;
use App\Support\Audit\AuditLogger;

/**
 * Pede um código OTP para um telefone. A resposta ao cliente é sempre a mesma,
 * tenha ou não o telefone uma conta (proteção contra enumeração).
 */
class RequestOtp
{
    public function __construct(private readonly OtpService $otp, private readonly AuditLogger $audit) {}

    public function handle(string $rawPhone, ?string $ip): PhoneNumber
    {
        $phone = PhoneNumber::parse($rawPhone)
            ?? throw new ApiException('validation_failed', 'Os dados enviados são inválidos.', 422, ['phone' => ['Indique um número de telemóvel angolano válido.']]);

        $this->otp->issue($phone->e164, $ip);

        $this->audit->record('auth.otp_requested', null, ['phone' => $phone->masked()]);

        return $phone;
    }
}
